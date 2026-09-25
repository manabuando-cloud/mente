<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** 画面の「今すぐ実行」から Drive 連携コマンドをバックグラウンド実行する */
class RunDriveTask implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const COMMANDS = [
        'sync-machines' => 'navi:sync-drive-machines',
        'ingest' => 'navi:ingest-reports',
        'ingest-vendor' => 'navi:ingest-vendor-reports',
        'link-reports' => 'navi:link-reports',
        'link-quotes' => 'navi:link-quotes',
        'suggest-quotes' => 'navi:suggest-quotes',
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
        // 見積候補づくりは Gemini が使えるなら PDF の中身も読む
        $params = $this->task === 'suggest-quotes' && filled(config('navi.gemini.api_key')) ? ['--ai' => true] : [];
        try {
            Artisan::call(self::COMMANDS[$this->task], $params);
        } catch (Throwable $e) {
            // 失敗しても画面が「実行中」のまま残らないよう、結果を記録してから投げ直す
            $this->record('failed', $e->getMessage());

            throw $e;
        }
        $this->record('done', trim(Artisan::output()));
    }

    private function record(string $status, string $output): void
    {
        Cache::forever("navi.task.{$this->task}", ['status' => $status, 'at' => now()->toIso8601String(), 'output' => mb_substr($output, 0, 2000)]);
    }
}
