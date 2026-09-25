<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\TroubleCase;
use App\Services\Drive\DriveClient;
use App\Services\Drive\DriveLocator;
use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiQuotaExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 見積書番号の逆入力（旧版の未着手項目 #1）。
 *
 * 機械フォルダの「見積」サブフォルダにある estXXXXXXXX.pdf のうち、まだどの対応履歴にも
 * 紐づいていないものについて「どの対応履歴の見積か」の候補を挙げる。
 * ファイル名に日付が無いので、見積の日付（AIでPDFから読んだ発行日、無ければDriveの作成日時）と
 * 対応日の近さ、金額と費用の一致、見積品目と交換部品の重なりでスコアリングし、
 * 最終的な確定は人が画面で行う（自動では書き込まない）。
 */
class QuoteSuggester
{
    public const CACHE_RESULT = 'navi.quotes.suggest.last';

    public const CACHE_DISMISSED = 'navi.quotes.suggest.dismissed';

    /** 対応日と見積日がこれ以上離れていたら候補にしない */
    public const MAX_DAYS = 180;

    private const META_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'issue_date' => ['type' => 'STRING', 'description' => '見積書の発行日 YYYY-MM-DD'],
            'total_amount' => ['type' => 'INTEGER', 'description' => '見積合計金額（円、税込があれば税込）'],
            'items' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => '見積の品目・部品名・作業名'],
            'subject' => ['type' => 'STRING', 'description' => '件名・不具合内容'],
        ],
    ];

    public function __construct(
        private DriveClient $drive,
        private DriveLocator $locator,
        private GeminiClient $gemini,
    ) {}

    /**
     * @return array{suggestions: list<array>, unmatched: int, machines: int, ai_used: int, quota_exhausted: bool, ran_at: string}
     */
    public function run(bool $useAi = false, int $aiLimit = 30): array
    {
        $known = $this->knownQuotes();
        $dismissed = array_flip(Cache::get(self::CACHE_DISMISSED, []));
        $machines = Machine::all()->keyBy('id');
        $result = ['suggestions' => [], 'unmatched' => 0, 'machines' => 0, 'ai_used' => 0, 'quota_exhausted' => false];

        foreach ($this->locator->machineFolders() as $mf) {
            $machine = $machines[$mf['machine_id']] ?? null;
            if (! $machine) {
                continue;
            }
            $files = array_filter(
                $this->locator->quotes($mf['folder']['id']),
                fn ($f) => ! isset($dismissed[$f['id']])
                    && ! isset($known['files'][$f['id']])
                    && ! isset($known['numbers'][DriveLocator::normQuoteNo($f['name'])])
            );
            if (! $files) {
                continue;
            }
            $result['machines']++;

            $cases = TroubleCase::published()
                ->where('machine_id', $machine->id)
                ->whereNotNull('date')
                ->where(fn ($q) => $q->whereNull('quote_no')->orWhere('quote_no', ''))
                ->whereNull('quote_url')
                ->get();

            foreach ($files as $file) {
                $meta = null;
                if ($useAi && ! $result['quota_exhausted'] && $result['ai_used'] < $aiLimit) {
                    try {
                        [$meta, $fresh] = $this->meta($file);
                        $result['ai_used'] += $fresh ? 1 : 0;
                    } catch (GeminiQuotaExceededException) {
                        $result['quota_exhausted'] = true;
                    }
                } else {
                    $meta = Cache::get($this->metaKey($file['id']));
                }

                $entry = $this->entry($file, $machine, $meta, $cases);
                if ($entry['candidates']) {
                    $result['suggestions'][] = $entry;
                } else {
                    $result['unmatched']++;
                }
            }
        }

        // 自信のあるものから並べる
        usort($result['suggestions'], fn ($a, $b) => $b['candidates'][0]['score'] <=> $a['candidates'][0]['score']);

        $result['ran_at'] = now()->toIso8601String();
        Cache::forever(self::CACHE_RESULT, $result);

        return $result;
    }

    /** 候補を確定: 対応履歴に quote_no と quote_url を書き込む */
    public function assign(string $fileId, TroubleCase $case): bool
    {
        $entry = collect(Cache::get(self::CACHE_RESULT)['suggestions'] ?? [])->firstWhere('file_id', $fileId);
        if (! $entry) {
            return false;
        }

        $case->update([
            'quote_no' => $case->quote_no ?: $entry['quote_no'],
            'quote_url' => $entry['url'],
        ]);
        $this->forget($fileId, $case->id);

        return true;
    }

    /** 「該当なし」: 次回以降も候補に出さない */
    public function dismiss(string $fileId): void
    {
        Cache::forever(self::CACHE_DISMISSED, array_values(array_unique([...Cache::get(self::CACHE_DISMISSED, []), $fileId])));
        $this->forget($fileId);
    }

    /** ファイル名から表示用の見積書番号を取る。"est23102936_事後見積り.pdf" → "est23102936" */
    public static function quoteNoFromFileName(string $name): string
    {
        $base = (string) preg_replace('/\.pdf$/i', '', $name);

        return preg_match('/^(est[\s_\-]?\d{4,})/i', $base, $m)
            ? strtolower((string) preg_replace('/[\s_\-]/', '', $m[1]))
            : $base;
    }

    private function entry(array $file, Machine $machine, ?array $meta, Collection $cases): array
    {
        [$date, $dateSource] = $this->quoteDate($file, $meta);
        $amount = isset($meta['total_amount']) && is_numeric($meta['total_amount']) ? (int) $meta['total_amount'] : null;
        $items = array_values(array_filter(array_map('strval', (array) ($meta['items'] ?? []))));

        $candidates = $date ? $cases->map(function (TroubleCase $c) use ($date, $amount, $items) {
            $dayDiff = abs($c->date->diffInDays($date));
            if ($dayDiff > self::MAX_DAYS) {
                return null;
            }
            $costMatch = $amount && $c->cost && self::amountMatches($amount, $c->cost);
            $partsHit = $items ? self::partsOverlap($items, implode(' ', $c->partNames()).' '.$c->action) : 0;

            // 日付の近さを基本点に、金額一致・部品の重なりを加点
            $score = max(0, 1 - $dayDiff / self::MAX_DAYS) * 60 + ($costMatch ? 30 : 0) + min($partsHit, 2) * 5;

            return [
                'case_id' => $c->id,
                'date' => $c->date->format('Y-m-d'),
                'symptom' => mb_substr($c->symptom, 0, 80),
                'cost' => $c->cost,
                'day_diff' => (int) $dayDiff,
                'cost_match' => $costMatch,
                'parts_hit' => $partsHit,
                'score' => round($score, 1),
            ];
        })->filter()->sortByDesc('score')->take(3)->values()->all() : [];

        return [
            'file_id' => $file['id'],
            'name' => $file['name'],
            'url' => DriveLocator::webLink($file),
            'quote_no' => self::quoteNoFromFileName($file['name']),
            'machine_id' => $machine->id,
            'machine_name' => $machine->displayName(),
            'date' => $date?->format('Y-m-d'),
            'date_source' => $dateSource,
            'amount' => $amount,
            'subject' => $meta['subject'] ?? null,
            'candidates' => $candidates,
        ];
    }

    /** @return array{0: ?CarbonImmutable, 1: ?string} */
    private function quoteDate(array $file, ?array $meta): array
    {
        if (! empty($meta['issue_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $meta['issue_date'])) {
            return [CarbonImmutable::parse($meta['issue_date']), 'pdf'];
        }
        $drive = $file['createdTime'] ?? $file['modifiedTime'] ?? null;

        return $drive ? [CarbonImmutable::parse($drive)->setTimezone(config('app.timezone'))->startOfDay(), 'drive'] : [null, null];
    }

    /** @return array{0: ?array, 1: bool} [meta, 今回新たにAIを呼んだか] */
    private function meta(array $file): array
    {
        $key = $this->metaKey($file['id']);
        if (Cache::has($key)) {
            return [Cache::get($key), false];
        }
        try {
            $meta = $this->gemini->generateJson(
                '添付は設備修理の見積書です。発行日・合計金額・品目（部品名や作業名）・件名を抽出してJSONで返してください。記載が無い項目は空にしてください。',
                [['mime' => 'application/pdf', 'data' => $this->drive->download($file['id'])]],
                self::META_SCHEMA,
            );
        } catch (GeminiQuotaExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning("見積PDFの読み取りに失敗: {$file['name']}", ['error' => $e->getMessage()]);
            $meta = [];
        }
        Cache::forever($key, $meta);

        return [$meta, true];
    }

    private function metaKey(string $fileId): string
    {
        return "navi.quote.meta.{$fileId}";
    }

    /** @return array{numbers: array<string, true>, files: array<string, true>} */
    private function knownQuotes(): array
    {
        $numbers = [];
        $files = [];
        TroubleCase::query()->where(fn ($q) => $q->whereNotNull('quote_no')->orWhereNotNull('quote_url'))
            ->get(['quote_no', 'quote_url'])
            ->each(function ($c) use (&$numbers, &$files) {
                if ($n = DriveLocator::normQuoteNo($c->quote_no)) {
                    $numbers[$n] = true;
                }
                if ($c->quote_url && preg_match('#/d/([\w\-]+)#', $c->quote_url, $m)) {
                    $files[$m[1]] = true;
                }
            });

        return ['numbers' => $numbers, 'files' => $files];
    }

    /** 見積金額と費用: 一致、または税抜/税込（10%・8%）の関係なら一致とみなす（±1%） */
    public static function amountMatches(int $quote, int $cost): bool
    {
        foreach ([1.0, 1.1, 1 / 1.1, 1.08, 1 / 1.08] as $rate) {
            if (abs($quote * $rate - $cost) <= max(1, $cost * 0.01)) {
                return true;
            }
        }

        return false;
    }

    private static function partsOverlap(array $items, string $text): int
    {
        $text = mb_strtolower(mb_convert_kana($text, 'asKV'));
        $hits = 0;
        foreach ($items as $item) {
            $item = mb_strtolower(mb_convert_kana(trim($item), 'asKV'));
            if (mb_strlen($item) >= 2 && str_contains($text, $item)) {
                $hits++;
            }
        }

        return $hits;
    }

    private function forget(string $fileId, ?string $caseId = null): void
    {
        $last = Cache::get(self::CACHE_RESULT);
        if (! $last) {
            return;
        }
        $last['suggestions'] = collect($last['suggestions'])
            ->reject(fn ($s) => $s['file_id'] === $fileId)
            // 確定した対応履歴は他の見積の候補からも外す
            ->map(fn ($s) => $caseId ? [...$s, 'candidates' => array_values(array_filter($s['candidates'], fn ($c) => $c['case_id'] !== $caseId))] : $s)
            ->filter(fn ($s) => $s['candidates'])
            ->values()->all();
        Cache::forever(self::CACHE_RESULT, $last);
    }
}
