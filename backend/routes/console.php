<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\RefreshTokenJob;
use App\Jobs\GenerateDailyAnalyticsJob;
use App\Jobs\SyncInventoryJob;

Schedule::job(new RefreshTokenJob)->hourly();
Schedule::job(new GenerateDailyAnalyticsJob)->dailyAt('00:00');
Schedule::job(new SyncInventoryJob)->everyFiveMinutes();
