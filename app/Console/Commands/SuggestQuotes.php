<?php

namespace App\Console\Commands;

use App\Services\QuoteSuggester;
use Illuminate\Console\Command;

class SuggestQuotes extends Command
{
    protected $signature = 'navi:suggest-quotes {--ai : 見積PDFをGeminiで読み、発行日・金額・品目も使って候補を絞る} {--ai-limit=30 : 1回にAIで読む見積PDFの上限}';

    protected $description = '見積フォルダのPDFについて、紐づけ先となる対応履歴の候補を作る（確定は Drive連携 画面で行う）';

    public function handle(QuoteSuggester $suggester): int
    {
        $r = $suggester->run((bool) $this->option('ai'), (int) $this->option('ai-limit'));
        $this->info('候補あり: '.count($r['suggestions']).'件 / 候補なし: '.$r['unmatched'].'件'.($this->option('ai') ? " / AI読み取り: {$r['ai_used']}件" : ''));
        if ($r['quota_exhausted']) {
            $this->warn('Gemini APIのクォータ超過のためAI読み取りを途中で止めました（残りはDriveの作成日時で候補を出しています）');
        }
        $this->line('候補の確定は「Drive連携」画面で行ってください。');

        return self::SUCCESS;
    }
}
