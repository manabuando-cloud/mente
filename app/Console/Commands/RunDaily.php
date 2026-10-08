<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 毎日の自動処理をまとめて実行する。
 * Docker（compose）では scheduler コンテナが routes/console.php の時刻どおりに個別に実行する。
 * Cloud Run ではスケジューラ用の常駐プロセスを置かず、Cloud Scheduler が1日1回この
 * コマンドを Cloud Run ジョブとして起動する。
 */
class RunDaily extends Command
{
    protected $signature = 'navi:daily';

    protected $description = '毎日の自動処理（報告書の取込み・業者別フォルダ・報告書の自動リンク）をまとめて実行する';

    private const STEPS = [
        'navi:sync-drive-machines' => '機種マスタの補完',
        'navi:ingest-reports' => '作業報告書PDFの取込み',
        'navi:ingest-vendor-reports' => '業者別フォルダの取込み',
        'navi:link-reports' => '報告書PDFの自動リンク',
    ];

    public function handle(): int
    {
        $failed = 0;
        foreach (self::STEPS as $command => $label) {
            $this->info("▶ {$label}（{$command}）");
            try {
                $this->call($command);
            } catch (\Throwable $e) {
                // 1つ失敗しても残りは続ける（例: Drive の一時的なエラー）
                report($e);
                $this->error("  失敗: {$e->getMessage()}");
                $failed++;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
