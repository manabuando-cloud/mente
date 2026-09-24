<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/** 画面の「今すぐ実行」から Drive 連携コマンドをバックグラウンド実行する */
class RunDriveTask implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const COMMANDS = [
        'ingest' => 'navi:ingest-reports',
        'link-reports' => 'navi:link-reports',
        'link-quotes' => 'navi:link-quotes',
    ];

    public int $timeout = 1800;

    public function __construct(public string $task) {}

    public function uniqueId(): string
    {
        return $this->task;
    }

    public function handle(): void
    {
        Cache::put("navi.task.{$this->task}", ['status' => 'running', 'at' => now()->toIso8601String()]);
        Artisan::call(self::COMMANDS[$this->task]);
        Cache::forever("navi.task.{$this->task}", [
            'status' => 'done',
            'at' => now()->toIso8601String(),
            'output' => trim(Artisan::output()),
        ]);
    }
}
