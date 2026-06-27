<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Integrations\IntegrationFactory;
use App\Models\Store;
use App\Models\Integration;

class OAuthController extends Controller
{
    /** Which credential key proves a platform is configured. */
    private const CONFIG_KEY = [
        'shopify' => 'services.shopify.api_key',
        'salla' => 'services.salla.client_id',
        'amazon' => 'services.amazon.client_id',
        'noon' => 'services.noon.client_id',
    ];

    public function redirect(Request $request, $platform)
    {
        if (!isset(self::CONFIG_KEY[$platform])) {
            return response()->json([
                'message' => "Connecting " . ucfirst($platform) . " isn't available yet.",
            ], 422);
        }

        if (blank(config(self::CONFIG_KEY[$platform]))) {
            return response()->json([
                'message' => ucfirst($platform) . " connect isn't configured yet. Add the {$platform} OAuth "
                    . "credentials (client id & secret) to the server environment to enable it.",
            ], 422);
        }

        try {
            $service = $this->getService($platform);
            return response()->json(['url' => $service->getAuthUrl()]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to start the connection right now.'], 422);
        }
    }

    public function callback(Request $request, $platform)
    {
        $code = $request->code;
        $service = $this->getService($platform);
        $tokenData = $service->exchangeCode($code);

        // Store integration data
        // For MVP, we might need to know which store this belongs to.
        // Usually, the state parameter is used to pass the store ID.
        
        return response()->json(['message' => 'Successfully connected', 'data' => $tokenData]);
    }

    protected function getService($platform)
    {
        return IntegrationFactory::make($platform);
    }
}
