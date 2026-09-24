<?php

namespace App\Services;

use App\Models\TroubleCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Slack Incoming Webhook 通知。
 * 旧GAS版ではダミーのWebhook URLがハードコードされていて一度も届いていなかったため、
 * URLは必ず .env の SLACK_WEBHOOK_URL から読む（デフォルト値は持たない）。
 */
class SlackNotifier
{
    public function isConfigured(): bool
    {
        return filled(config('navi.slack.webhook_url'));
    }

    public function notifyCase(TroubleCase $case): bool
    {
        $case->loadMissing('machine');
        $machine = $case->machine?->displayName() ?? $case->machine_id;
        $url = route('cases.show', $case);

        $lines = array_filter([
            '*🔧 新しい対応履歴が登録されました*',
            "*機種:* {$machine}".($case->machine?->site ? "（{$case->machine->site}）" : ''),
            $case->date ? '*対応日:* '.$case->date->format('Y-m-d') : null,
            '*症状:* '.$case->symptom,
            $case->cause ? '*原因:* '.$case->cause : null,
            $case->action ? '*対処:* '.$case->action : null,
            $case->codes ? '*エラーコード:* '.$case->codes : null,
            $case->submitted_by ? '*登録者:* '.$case->submitted_by : null,
            "<{$url}|詳細を開く>",
        ]);

        if ($this->send(implode("\n", $lines))) {
            $case->forceFill(['slack_notified_at' => now()])->save();

            return true;
        }

        return false;
    }

    public function send(string $text): bool
    {
        if (! $this->isConfigured()) {
            Log::info('SLACK_WEBHOOK_URL 未設定のためSlack通知をスキップしました');

            return false;
        }

        try {
            $response = Http::timeout(10)->post(config('navi.slack.webhook_url'), ['text' => $text]);
            if ($response->failed()) {
                Log::warning('Slack通知に失敗しました', ['status' => $response->status(), 'body' => $response->body()]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            // 通知失敗で登録自体を失敗させない
            Log::warning('Slack通知に失敗しました: '.$e->getMessage());

            return false;
        }
    }
}
