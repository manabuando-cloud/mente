<?php

namespace App\Services\Gemini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiClient
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $endpoint,
        private int $timeout = 120,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('navi.gemini.api_key'),
            config('navi.gemini.model'),
            rtrim(config('navi.gemini.endpoint'), '/'),
            config('navi.gemini.timeout'),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    /** テキスト生成 */
    public function generateText(string $prompt, ?string $system = null): string
    {
        return $this->generate([['text' => $prompt]], $system);
    }

    /**
     * JSON生成。$attachments は [['mime' => 'application/pdf', 'data' => <binary>], ...]
     *
     * @return array<string, mixed>
     */
    public function generateJson(string $prompt, array $attachments = [], ?array $schema = null, ?string $system = null): array
    {
        $parts = [];
        foreach ($attachments as $a) {
            $parts[] = ['inline_data' => ['mime_type' => $a['mime'], 'data' => base64_encode($a['data'])]];
        }
        $parts[] = ['text' => $prompt];

        $config = ['responseMimeType' => 'application/json'];
        if ($schema) {
            $config['responseSchema'] = $schema;
        }

        $text = $this->generate($parts, $system, $config);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text));
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new GeminiException('GeminiのJSON応答を解釈できませんでした: '.mb_substr($text, 0, 200));
        }

        return $decoded;
    }

    private function generate(array $parts, ?string $system = null, array $generationConfig = []): string
    {
        if (! $this->isConfigured()) {
            throw new GeminiException('GEMINI_API_KEY が設定されていません（.env を確認してください）');
        }

        $payload = ['contents' => [['role' => 'user', 'parts' => $parts]]];
        if ($system) {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($generationConfig) {
            $payload['generationConfig'] = $generationConfig;
        }

        $url = "{$this->endpoint}/models/{$this->model}:generateContent";

        // 503（一時的な高負荷）は同一モデルで1回だけリトライ
        $response = $this->post($url, $payload);
        if ($response->status() === 503) {
            usleep(app()->runningUnitTests() ? 0 : 3_000_000);
            $response = $this->post($url, $payload);
        }

        if ($response->status() === 429) {
            throw new GeminiQuotaExceededException('Gemini APIのクォータを超過しました（429）。Google AI Studioで従量課金の有効化を検討してください。');
        }
        if ($response->failed()) {
            throw new GeminiException('Gemini APIエラー '.$response->status().': '.mb_substr($response->body(), 0, 300));
        }

        $text = collect($response->json('candidates.0.content.parts', []))->pluck('text')->implode('');
        if ($text === '') {
            throw new GeminiException('Geminiから空の応答が返されました（finishReason: '.$response->json('candidates.0.finishReason', '不明').'）');
        }

        return $text;
    }

    private function post(string $url, array $payload): Response
    {
        try {
            return Http::timeout($this->timeout)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->asJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new GeminiException('Gemini APIに接続できませんでした: '.$e->getMessage(), previous: $e);
        }
    }
}
