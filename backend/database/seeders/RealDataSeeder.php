<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Integration;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformProduct;
use App\Models\Category;
use App\Models\Notification;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class RealDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create User first (to be owner)
        $user = User::updateOrCreate(
            ['email' => 'admin@hubbyglobal.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // 2. Create Organization
        $org = Organization::updateOrCreate(
            ['slug' => 'hubbyglobal-demo'],
            [
                'name' => 'HubbyGlobal Demo',
                'owner_id' => $user->id,
            ]
        );

        // Attach User to Org if not already attached
        if (!$org->users()->where('user_id', $user->id)->exists()) {
            $org->users()->attach($user, ['role' => 'admin']);
        }

        // 3. Create Stores & Integrations
        $shopify = Store::updateOrCreate(
            ['organization_id' => $org->id, 'platform' => 'shopify'],
            [
                'name' => 'Main Shopify Store',
                'status' => 'connected',
                'is_master' => true,
            ]
        );
        Integration::updateOrCreate(
            ['store_id' => $shopify->id],
            [
                'access_token' => 'shpat_demo_token',
                'shop_domain' => 'hubby-demo.myshopify.com',
            ]
        );

        $salla = Store::updateOrCreate(
            ['organization_id' => $org->id, 'platform' => 'salla'],
            [
                'name' => 'Salla Boutique',
                'status' => 'connected',
                'is_master' => false,
            ]
        );
        Integration::updateOrCreate(
            ['store_id' => $salla->id],
            [
                'access_token' => 'salla_demo_token',
                'shop_domain' => 'salla.sa/boutique',
            ]
        );

        // 4. Create Products
        $productsData = [
            [
                'name' => 'Premium Wireless Headphones',
                'sku' => 'HEAD-001',
                'price' => 299.99,
                'description' => 'High-quality noise-canceling headphones.',
                'variants' => [
                    ['sku' => 'HEAD-001-BLK', 'name' => 'Black', 'price' => 299.99, 'stock' => 50],
                    ['sku' => 'HEAD-001-SLV', 'name' => 'Silver', 'price' => 319.99, 'stock' => 20],
                ]
            ],
            [
                'name' => 'Smart Watch Series 5',
                'sku' => 'WATCH-005',
                'price' => 399.00,
                'description' => 'Next-gen smartwatch with health tracking.',
                'variants' => [
                    ['sku' => 'WATCH-005-GPS', 'name' => 'GPS', 'price' => 399.00, 'stock' => 100],
                    ['sku' => 'WATCH-005-CEL', 'name' => 'Cellular', 'price' => 499.00, 'stock' => 5],
                ]
            ],
            [
                'name' => 'Organic Cotton T-Shirt',
                'sku' => 'TSHIRT-ORG',
                'price' => 25.00,
                'description' => '100% organic cotton, sustainably made.',
                'variants' => [
                    ['sku' => 'TSHIRT-ORG-S', 'name' => 'Small', 'price' => 25.00, 'stock' => 200],
                    ['sku' => 'TSHIRT-ORG-M', 'name' => 'Medium', 'price' => 25.00, 'stock' => 150],
                    ['sku' => 'TSHIRT-ORG-L', 'name' => 'Large', 'price' => 25.00, 'stock' => 0],
                ]
            ],
        ];

        foreach ($productsData as $pData) {
            $product = Product::updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $pData['sku']],
                [
                    'name' => $pData['name'],
                    'price' => $pData['price'],
                    'description' => $pData['description'],
                    'status' => 'active',
                ]
            );

            foreach ($pData['variants'] as $vData) {
                $variant = ProductVariant::updateOrCreate(
                    ['product_id' => $product->id, 'sku' => $vData['sku']],
                    [
                        'price' => $vData['price'],
                        'stock' => $vData['stock'],
                    ]
                );

                // Create platform products for both stores
                PlatformProduct::updateOrCreate(
                    ['store_id' => $shopify->id, 'product_variant_id' => $variant->id],
                    ['external_id' => 'shp_' . rand(100000, 999999)]
                );

                PlatformProduct::updateOrCreate(
                    ['store_id' => $salla->id, 'product_variant_id' => $variant->id],
                    ['external_id' => 'sal_' . rand(100000, 999999)]
                );
            }
        }

        // 4b. Categories (a small tree) + assign products
        $electronics = Category::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'electronics'],
            ['name' => 'Electronics', 'description' => 'Devices and gadgets']
        );
        $audio = Category::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'audio'],
            ['name' => 'Audio', 'parent_id' => $electronics->id]
        );
        $wearables = Category::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'wearables'],
            ['name' => 'Wearables', 'parent_id' => $electronics->id]
        );
        $apparel = Category::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'apparel'],
            ['name' => 'Apparel', 'description' => 'Clothing & accessories']
        );

        Product::where('organization_id', $org->id)->where('sku', 'HEAD-001')->update(['category_id' => $audio->id]);
        Product::where('organization_id', $org->id)->where('sku', 'WATCH-005')->update(['category_id' => $wearables->id]);
        Product::where('organization_id', $org->id)->where('sku', 'TSHIRT-ORG')->update(['category_id' => $apparel->id]);

        // 5. Create Orders (Historical Data for Charts)
        // Clear old orders to avoid duplicates on re-seed
        Order::whereIn('store_id', [$shopify->id, $salla->id])->delete();

        $statuses = ['paid', 'processing', 'shipped', 'delivered'];
        $customers = [
            ['name' => 'John Doe', 'email' => 'john@example.com'],
            ['name' => 'Jane Smith', 'email' => 'jane@smith.com'],
            ['name' => 'Ahmed Ali', 'email' => 'ahmed@salla.sa'],
            ['name' => 'Maria Garcia', 'email' => 'maria@global.com'],
            ['name' => 'Chen Wei', 'email' => 'wei@tech.cn'],
        ];

        for ($i = 0; $i < 60; $i++) {
            $date = Carbon::now()->subDays(rand(0, 30))->subHours(rand(0, 23));
            $store = rand(0, 1) ? $shopify : $salla;
            $customer = $customers[array_rand($customers)];
            $total = rand(50, 1500);

            $order = Order::create([
                'store_id' => $store->id,
                'external_id' => 'ORD-' . strtoupper(bin2hex(random_bytes(4))),
                'status' => $statuses[array_rand($statuses)],
                'total' => $total,
                'currency' => $store->platform === 'salla' ? 'SAR' : 'EGP',
                'customer_name' => $customer['name'],
                'customer_email' => $customer['email'],
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            // Add 1-3 items per order
            $orderProducts = Product::all()->random(rand(1, 2));
            foreach ($orderProducts as $p) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'name' => $p->name, // Corrected from product_name
                    'sku' => $p->sku,
                    'quantity' => rand(1, 3),
                    'price' => $p->price,
                ]);
            }
        }

        // 6. Create Notifications
        Notification::where('organization_id', $org->id)->delete();
        
        Notification::create([
            'organization_id' => $org->id,
            'title' => 'Welcome to HubbyGlobal!',
            'message' => 'Start by connecting your first store to sync your products and orders.',
            'type' => 'info',
            'created_at' => now()->subHours(2),
        ]);

        Notification::create([
            'organization_id' => $org->id,
            'title' => 'Inventory Alert',
            'message' => 'Organic Cotton T-Shirt (Large) is out of stock.',
            'type' => 'warning',
            'created_at' => now()->subMinutes(30),
        ]);

        Notification::create([
            'organization_id' => $org->id,
            'title' => 'Sync Successful',
            'message' => 'Successfully synced 45 orders from Salla Boutique.',
            'type' => 'success',
            'created_at' => now(),
        ]);
    }
}
