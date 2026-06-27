<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Store;
use App\Jobs\SyncOrdersJob;
use App\Jobs\SyncProductsJob;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        $stores = Store::where('organization_id', $organizationId)->with('integration')->get();
        return response()->json($stores);
    }

    /**
     * Tell the dashboard which platforms have one-click OAuth available — i.e.
     * the operator has configured that platform's app client keys in the
     * environment. Platforms without keys are connectable by token only.
     */
    public function connectOptions()
    {
        // Config key that proves a platform's OAuth app is configured.
        $oauthKeys = [
            'shopify' => 'services.shopify.api_key',
            'salla' => 'services.salla.client_id',
            'amazon' => 'services.amazon.client_id',
            'noon' => 'services.noon.client_id',
        ];

        $oauthEnabled = [];
        foreach ($oauthKeys as $platform => $key) {
            $oauthEnabled[$platform] = filled(config($key));
        }

        return response()->json(['oauth_enabled' => $oauthEnabled]);
    }

    /**
     * Connect a merchant's store from the dashboard using their own API
     * credentials. This is the self-serve path a tenant uses — no platform-app
     * OAuth secrets required from the operator. The OAuth redirect flow
     * (OAuthController) is an optional convenience layered on top.
     */
    public function connect(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'platform' => 'required|in:shopify,woocommerce,salla,zid,amazon,noon,trendyol',
            'domain' => 'required|string|max:255',
            'access_token' => 'required|string',
            // Some platforms need a second secret (e.g. WooCommerce consumer secret).
            'api_secret' => 'nullable|string',
        ]);

        $organizationId = $request->header('X-Organization-Id');

        // Normalise the domain (strip scheme/trailing slash) so API base URLs build cleanly.
        $domain = rtrim(preg_replace('#^https?://#', '', trim($data['domain'])), '/');

        $store = Store::create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'platform' => $data['platform'],
            'domain' => $domain,
            'status' => 'syncing',
        ]);

        $store->integration()->create([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['api_secret'] ?? null,
            'shop_domain' => $domain,
        ]);

        // Pull the merchant's catalog and orders in the background.
        SyncProductsJob::dispatch($store);
        SyncOrdersJob::dispatch($store);

        return response()->json([
            'message' => 'Store connected. Initial sync started.',
            'store' => $store->load('integration'),
        ], 201);
    }

    public function setMaster(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        // Reset all stores to not master
        Store::where('organization_id', $organizationId)->update(['is_master' => false]);
        
        // Set the selected one as master
        $store = Store::where('organization_id', $organizationId)->findOrFail($id);
        $store->update(['is_master' => true]);
        
        return response()->json(['message' => "{$store->name} is now the master store", 'store' => $store]);
    }

    public function sync(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        $store = Store::where('organization_id', $organizationId)->findOrFail($id);

        $store->update(['status' => 'syncing']);
        SyncOrdersJob::dispatch($store);
        SyncProductsJob::dispatch($store);

        return response()->json(['message' => 'Syncing started in the background']);
    }

    public function syncAll(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        $stores = Store::where('organization_id', $organizationId)->get();

        foreach ($stores as $store) {
            $store->update(['status' => 'syncing']);
            SyncOrdersJob::dispatch($store);
            SyncProductsJob::dispatch($store);
        }
        
        return response()->json(['message' => 'Syncing started for ' . $stores->count() . ' stores']);
    }

    public function destroy(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        $store = Store::where('organization_id', $organizationId)->findOrFail($id);
        
        $store->delete(); // Integration will cascade if defined in migration, or handle manually
        
        return response()->json(['message' => 'Store disconnected']);
    }
}
