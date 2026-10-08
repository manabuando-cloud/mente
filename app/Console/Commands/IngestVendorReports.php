<?php

namespace App\Console\Commands;

use App\Services\VendorReportIngestor;
use Illuminate\Console\Command;

class IngestVendorReports extends Command
{
    protected $signature = 'navi:ingest-vendor-reports {--limit= : 1回にAIで読む報告書PDFの上限} {--dry-run : AIを使わず、各ファイルをどう扱うかだけ表示する}';

    protected $description = '「メーカー作業報告書見積り」フォルダの報告書を確認待ちに取り込み、見積書を同じ日の履歴に紐づける';

    public function handle(VendorReportIngestor $ingestor): int
    {
        if ($this->option('dry-run')) {
            $r = $ingestor->ingest(null, true);
            $this->table(['フォルダ', 'ファイル', '機械番号', '扱い'], array_map(fn ($row) => [mb_strimwidth($row[0], 0, 40, '…'), mb_strimwidth($row[1], 0, 70, '…'), $row[2], $row[3]], $r['plan']));
            $counts = array_count_values(array_column($r['plan'], 3));
            $this->info(collect($counts)->map(fn ($n, $k) => "{$k}: {$n}件")->implode(' / '));

            return self::SUCCESS;
        }
        $r = $ingestor->ingest($this->option('limit') ? (int) $this->option('limit') : null);
        $this->info("確認待ちに追加: {$r['created']}件 / 既存の履歴にリンク: {$r['linked_existing']}件 / 見積の紐づけ: {$r['quotes_linked']}件（保留 {$r['quotes_waiting']}件） / 対象外: {$r['skipped']}件 / 機械を特定できない: ".count($r['unresolved']).'件 / エラー: '.$r['errors'].'件');
        foreach ($r['messages'] as $m) {
            $this->line('  - '.$m);
        }
        if ($r['quota_exhausted']) {
            $this->warn('Gemini APIのクォータ超過のため報告書の読み取りを中断しました。残りは次回実行時に処理されます。');
        }

        return self::SUCCESS;
    }
}
