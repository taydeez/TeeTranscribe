<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:publish')->everyMinute()->withoutOverlapping(5);
Schedule::command('uploads:cleanup')->hourly()->withoutOverlapping();
Schedule::command('billing:reconcile')->everyTenMinutes()->withoutOverlapping();
Schedule::command('activities:recover')->everyMinute()->withoutOverlapping(5);
Schedule::command('privacy:cleanup')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('logs:prune')->daily()->withoutOverlapping();

Artisan::command('logs:prune', function (): void {
    $cutoff = now()->subDays(max(1, (int) config('observability.retention_days', 30)));
    do {
        $ids = DB::table('operational_logs')->where('created_at', '<', $cutoff)->limit(1000)->pluck('id');
        DB::table('operational_logs')->whereIn('id', $ids)->delete();
    } while ($ids->count() === 1000);
    $this->info('Expired operational logs removed.');
})->purpose('Remove expired internal operational logs');
