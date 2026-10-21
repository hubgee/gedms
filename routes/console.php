<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recover upload assemblies whose worker died, and expire sessions that ran
// past their TTL. Runs often so a stuck assembly is picked up within minutes;
// every step is idempotent, so overlapping runs are harmless.
Schedule::command('edms:prune-uploads --ttl')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
