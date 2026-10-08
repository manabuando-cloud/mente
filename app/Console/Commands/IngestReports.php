<?php

namespace App\Console\Commands;

use App\Services\ReportIngestor;
use Illuminate\Console\Command;

class IngestReports extends Command
{
    protected $signature = 'navi:ingest-reports {--limit= : 1回に処理するPDFの上限} {--retry-errors : 前回エラーになったPDFも再処理する}';

    protected $description = 'Drive上の未処理の作業報告書PDFをAIで読み取り、確認待ちの対応履歴として登録する';

    public function handle(ReportIngestor $ingestor): int
    {
        $r = $ingestor->ingest($this->option('limit') ? (int) $this->option('limit') : null, (bool) $this->option('retry-errors'));
        $this->info("確認待ちに追加: {$r['created']}件 / 既存の履歴にリンク: {$r['linked_existing']}件 / 既存の履歴があり対象外: {$r['skipped']}件 / エラー: {$r['errors']}件");
        foreach ($r['messages'] as $m) {
            $this->line('  - '.$m);
        }
        if ($r['quota_exhausted']) {
            $this->warn('Gemini APIのクォータ超過のため中断しました。残りは次回実行時に処理されます。');
        }

        return self::SUCCESS;
    }
}
