<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\RefreshTokenJob;
use App\Jobs\GenerateDailyAnalyticsJob;
use App\Jobs\SyncInventoryJob;
use App\Jobs\AmortizeExpensesJob;

Schedule::job(new RefreshTokenJob)->hourly();
Schedule::job(new GenerateDailyAnalyticsJob)->dailyAt('00:00');
Schedule::job(new SyncInventoryJob)->everyFiveMinutes();
// Keep expense_allocations current so the P&L's operating-expenses line stays accurate (spec 01).
Schedule::job(new AmortizeExpensesJob)->dailyAt('00:15');
