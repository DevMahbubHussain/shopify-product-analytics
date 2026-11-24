<?php

declare(strict_types=1);

use App\Models\McpAuditLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:publish --age=30 --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('outbox:prune --days=14')
    ->daily()
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => [McpAuditLog::class]])
    ->daily()
    ->withoutOverlapping();
