<?php

namespace App\Console\Commands;

use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiException;
use Illuminate\Console\Command;

class TestGemini extends Command
{
    protected $signature = 'navi:test-gemini';

    protected $description = 'Gemini API に短い問い合わせをして、キーとモデルが使えるか確認する';

    public function handle(GeminiClient $gemini): int
    {
        if (! $gemini->isConfigured()) {
            $this->error('GEMINI_API_KEY が .env に設定されていません');

            return self::FAILURE;
        }
        $this->line('モデル: '.config('navi.gemini.model'));
        try {
            $answer = $gemini->generateText('動作確認です。「OK」とだけ返してください。');
        } catch (GeminiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('応答: '.trim($answer));

        return self::SUCCESS;
    }
}
