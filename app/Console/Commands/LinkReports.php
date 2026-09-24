<?php

namespace App\Console\Commands;

use App\Services\ReportLinker;
use Illuminate\Console\Command;

class LinkReports extends Command
{
    protected $signature = 'navi:link-reports';

    protected $description = '報告書PDFのURLが未設定の対応履歴を、ファイル名の日付と機種で自動リンクする';

    public function handle(ReportLinker $linker): int
    {
        $r = $linker->linkReports();
        $this->info("リンク成功: {$r['linked']}件 / 曖昧でスキップ: ".count($r['ambiguous']).'件 / Driveフォルダ未検出: '.$r['no_folder'].'件');
        if ($r['ambiguous']) {
            $this->line('曖昧なものは「Drive連携」画面から候補を選んでリンクできます。');
        }

        return self::SUCCESS;
    }
}
