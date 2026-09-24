<?php

namespace App\Console\Commands;

use App\Services\SlackNotifier;
use Illuminate\Console\Command;

class TestSlack extends Command
{
    protected $signature = 'navi:test-slack';

    protected $description = 'Slack Incoming Webhook にテストメッセージを送る';

    public function handle(SlackNotifier $slack): int
    {
        if (! $slack->isConfigured()) {
            $this->error('SLACK_WEBHOOK_URL が .env に設定されていません');

            return self::FAILURE;
        }
        if ($slack->send('✅ 設備トラブルナビからのテスト通知です')) {
            $this->info('送信しました。Slackチャンネルを確認してください。');

            return self::SUCCESS;
        }
        $this->error('送信に失敗しました。storage/logs/laravel.log を確認してください。');

        return self::FAILURE;
    }
}
