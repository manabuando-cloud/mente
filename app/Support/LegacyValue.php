<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * 旧スプレッドシート由来の値の正規化。
 * Sheets は ISO日付文字列を Date 型に、全角数字文字列を Number 型に勝手に変換するため、
 * エクスポートされた値は "2023/10/05" "2023-10-05T00:00:00.000Z" "45204"（シリアル値）など揺れる。
 */
class LegacyValue
{
    public static function str(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = implode(', ', array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v)));
        }
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }

    public static function date(mixed $v): ?string
    {
        $s = self::str($v);
        if ($s === null) {
            return null;
        }
        $s = mb_convert_kana($s, 'as');

        // スプレッドシートのシリアル値（1899-12-30 起点）
        if (preg_match('/^\d{5}(\.\d+)?$/', $s)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $s)->format('Y-m-d');
        }
        // ISO日時（UTC保存）: 日本時間に直してから日付を取る
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $s)) {
            try {
                return CarbonImmutable::parse($s)->setTimezone('Asia/Tokyo')->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }
        // 2023年10月5日 / 2023/10/5 / 2023.10.5 / 20231005
        if (preg_match('/^(\d{4})\s*[年\/\.\-]?\s*(\d{1,2})\s*[月\/\.\-]?\s*(\d{1,2})/u', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        try {
            return CarbonImmutable::parse($s, 'Asia/Tokyo')->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    public static function datetime(mixed $v): ?string
    {
        $s = self::str($v);
        if ($s === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($s, config('app.timezone'))->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** "¥12,300" "１２３００円" → 12300 */
    public static function int(mixed $v): ?int
    {
        $s = self::str($v);
        if ($s === null) {
            return null;
        }
        $s = preg_replace('/[^\d\.\-]/', '', mb_convert_kana($s, 'n'));

        return is_numeric($s) ? max(0, (int) round((float) $s)) : null;
    }

    public static function bool(mixed $v): bool
    {
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'y', '済', 'done'], true);
    }

    /** @return list<array{title: string, url: string}> */
    public static function manuals(mixed $v): array
    {
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : preg_split('/[\s,、]+/u', $v, -1, PREG_SPLIT_NO_EMPTY);
        }
        $out = [];
        foreach ((array) $v as $i => $m) {
            if (is_string($m) && $m !== '') {
                $out[] = ['title' => '取扱説明書'.(count((array) $v) > 1 ? ' '.($i + 1) : ''), 'url' => $m];
            } elseif (is_array($m) && ($m['url'] ?? null)) {
                $out[] = ['title' => (string) ($m['title'] ?? $m['name'] ?? '取扱説明書'), 'url' => (string) $m['url']];
            }
        }

        return $out;
    }
}
