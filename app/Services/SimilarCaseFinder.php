<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\TroubleCase;
use Illuminate\Database\Eloquent\Collection;

/**
 * AI相談・登録時の「似た過去事例」探索。
 * 日本語は分かち書きが無いので、文字バイグラム + 英数字トークン（エラーコード等）の重なりでスコアリングする。
 */
class SimilarCaseFinder
{
    /** @return Collection<int, TroubleCase> */
    public function find(string $symptom, ?string $machineId = null, int $limit = 8, ?string $excludeId = null): Collection
    {
        $queryTokens = self::tokens($symptom);
        if (! $queryTokens) {
            return new Collection;
        }

        $machine = $machineId ? Machine::find($machineId) : null;

        $ranked = TroubleCase::published()
            ->with(['machine', 'photos', 'ratings'])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get()
            ->map(function (TroubleCase $c) use ($queryTokens, $machine) {
                $caseTokens = self::tokens(implode(' ', [$c->symptom, $c->cause, $c->codes, $c->parts]));
                $overlap = count(array_intersect_key($queryTokens, $caseTokens));
                if ($overlap === 0) {
                    return null;
                }
                $score = $overlap / sqrt(count($queryTokens) * max(count($caseTokens), 1));
                if ($machine && $c->machine_id === $machine->id) {
                    $score *= 1.6;
                } elseif ($machine && $c->machine?->model === $machine->model) {
                    $score *= 1.3;
                }
                $c->setAttribute('similarity', round($score, 4));

                return $c;
            })
            ->filter()
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();

        return new Collection($ranked->all());
    }

    /** @return array<string, true> */
    public static function tokens(string $text): array
    {
        $text = mb_strtolower(mb_convert_kana($text, 'asKV'));
        $tokens = [];

        // 英数字の連続（エラーコード、部品番号など）
        preg_match_all('/[a-z0-9][a-z0-9\-]{1,}/', $text, $m);
        foreach ($m[0] as $t) {
            $tokens[$t] = true;
        }

        // 日本語部分は文字バイグラム
        preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}ー]+/u', $text, $m);
        foreach ($m[0] as $run) {
            $chars = mb_str_split($run);
            if (count($chars) === 1) {
                continue;
            }
            for ($i = 0; $i < count($chars) - 1; $i++) {
                $bigram = $chars[$i].$chars[$i + 1];
                // ひらがなだけのバイグラム（助詞など）はノイズなので除外
                if (! preg_match('/^\p{Hiragana}+$/u', $bigram)) {
                    $tokens[$bigram] = true;
                }
            }
        }

        return $tokens;
    }
}
