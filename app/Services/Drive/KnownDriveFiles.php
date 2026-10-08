<?php

namespace App\Services\Drive;

use App\Models\ProcessedReportFile;
use App\Models\TroubleCase;

/**
 * すでに取り込み済み・紐づけ済みのDriveファイルID。
 * 旧GAS版から移行した履歴には報告書URLが入っているものがある（139件）ので、
 * ProcessedReportFile だけでなく report_url / quote_url / source_file_id も見て二重登録を防ぐ。
 */
class KnownDriveFiles
{
    /** @return array<string, true> */
    public static function ids(bool $retryErrors = false): array
    {
        $ids = ProcessedReportFile::query()
            ->when($retryErrors, fn ($q) => $q->where('result', 'not like', 'error%'))
            ->pluck('file_id')->flip()->map(fn () => true)->all();

        TroubleCase::query()
            ->where(fn ($q) => $q->whereNotNull('report_url')->orWhereNotNull('quote_url')->orWhereNotNull('source_file_id'))
            ->select(['report_url', 'quote_url', 'source_file_id'])
            ->each(function ($c) use (&$ids) {
                foreach ([$c->report_url, $c->quote_url] as $url) {
                    if ($id = self::idFromUrl($url)) {
                        $ids[$id] = true;
                    }
                }
                if ($c->source_file_id) {
                    $ids[$c->source_file_id] = true;
                }
            });

        return $ids;
    }

    public static function idFromUrl(?string $url): ?string
    {
        return $url && preg_match('#(?:/d/|[?&]id=)([\w\-]{3,})#', $url, $m) ? $m[1] : null;
    }
}
