<?php

namespace Database\Seeders;

use Illuminate\Support\Str;

/**
 * Resolves the demo admin credentials for the seeders, so no weak known password ever ships.
 *
 * Order of precedence for the password:
 *   1. DEMO_ADMIN_PASSWORD from the environment (set this for a real demo login);
 *   2. in production with no env override → a random, unguessable password (there is deliberately
 *      no default login in production);
 *   3. locally → a documented, non-trivial demo default.
 */
class DemoAdmin
{
    public static function email(): string
    {
        return env('DEMO_ADMIN_EMAIL', 'admin@hubbyglobal.com');
    }

    public static function password(): string
    {
        $configured = env('DEMO_ADMIN_PASSWORD');
        if ($configured) {
            return $configured;
        }

        if (app()->environment('production')) {
            return Str::password(24);
        }

        return 'HubbyDemo!2026';
    }
}
