<?php

namespace App\Console\Commands;

use App\Services\ReportLinker;
use Illuminate\Console\Command;

class LinkQuotes extends Command
{
    protected $signature = 'navi:link-quotes';

    protected $description = '見積書番号(quote_no)を手がかりに、見積書PDFのURLを自動リンクする';

    public function handle(ReportLinker $linker): int
    {
        $r = $linker->linkQuotes();
        $this->info("リンク成功: {$r['linked']}件 / 見つからず: {$r['not_found']}件 / 曖昧: ".count($r['ambiguous']).'件');

        return self::SUCCESS;
    }
}
