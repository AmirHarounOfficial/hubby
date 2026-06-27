<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;

abstract class BaseIntegrationService implements IntegrationServiceInterface
{
    protected function getHttpClient(Integration $integration)
    {
        return Http::withToken($integration->access_token);
    }

    // Common logic for all services can go here
}
