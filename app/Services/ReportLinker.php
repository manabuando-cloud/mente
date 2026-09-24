<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\TroubleCase;
use App\Services\Drive\DriveLocator;
use Illuminate\Support\Facades\Cache;

/**
 * 既存の対応履歴とDrive上のPDFを紐づける（旧: linkReportsToDrive / linkQuotesToDrive）。
 * 何度実行しても差分のみ処理する。曖昧だったものは候補付きで保存し、画面から手動で選べる。
 */
class ReportLinker
{
    public const CACHE_REPORTS = 'navi.link.reports.last';

    public const CACHE_QUOTES = 'navi.link.quotes.last';

    public function __construct(private DriveLocator $locator) {}

    /**
     * 報告書ファイル名先頭の日付(YYYYMMDD) × 対応履歴の date・機種 で突き合わせる。
     * 同じ機種・同じ日付に候補が複数ある場合は曖昧としてスキップ。
     */
    public function linkReports(): array
    {
        $result = ['linked' => 0, 'ambiguous' => [], 'no_folder' => 0];

        $cases = TroubleCase::published()->whereNull('report_url')->whereNotNull('date')->get()->groupBy('machine_id');
        $machines = Machine::whereIn('id', $cases->keys())->get()->keyBy('id');

        foreach ($cases as $machineId => $machineCases) {
            $machine = $machines[$machineId] ?? null;
            $folderId = $machine ? $this->locator->folderFor($machine) : null;
            if (! $folderId) {
                $result['no_folder'] += $machineCases->count();

                continue;
            }

            $filesByDate = collect($this->locator->activityReports($folderId))
                ->groupBy(fn ($f) => DriveLocator::reportDate($f['name']));
            // 同日に同じ機種の対応履歴が複数あっても曖昧
            $casesByDate = $machineCases->groupBy(fn ($c) => $c->date->format('Y-m-d'));

            foreach ($casesByDate as $date => $sameDay) {
                $files = $filesByDate[$date] ?? collect();
                if ($files->isEmpty()) {
                    continue;
                }
                if ($files->count() === 1 && $sameDay->count() === 1) {
                    $sameDay->first()->update(['report_url' => DriveLocator::webLink($files->first())]);
                    $result['linked']++;

                    continue;
                }
                foreach ($sameDay as $case) {
                    $result['ambiguous'][] = $this->ambiguousEntry($case, $files->all());
                }
            }
        }

        Cache::forever(self::CACHE_REPORTS, [...$result, 'ran_at' => now()->toIso8601String()]);

        return $result;
    }

    /**
     * quote_no（見積書番号）を手がかりに見積PDFを探す。
     * ① 機械フォルダ内「見積」サブフォルダ（ファイル名＝見積書番号）
     * ② 見つからなければ「メーカー作業報告書見積り」フォルダを再帰探索
     */
    public function linkQuotes(): array
    {
        $result = ['linked' => 0, 'not_found' => 0, 'ambiguous' => []];
        $cases = TroubleCase::published()->whereNull('quote_url')->whereNotNull('quote_no')->where('quote_no', '!=', '')->with('machine')->get();
        $vendorPdfs = null;

        foreach ($cases as $case) {
            $key = DriveLocator::normQuoteNo($case->quote_no);
            if ($key === '') {
                continue;
            }

            $folderId = $case->machine ? $this->locator->folderFor($case->machine) : null;
            $matches = $folderId ? $this->matchQuotes($this->locator->quotes($folderId), $key) : [];

            if (! $matches && ($vendorFolder = config('navi.drive.vendor_folder_id'))) {
                $vendorPdfs ??= $this->locator->pdfsRecursive($vendorFolder);
                $matches = array_values(array_filter($vendorPdfs, fn ($f) => DriveLocator::normQuoteNo($f['name']) === $key || str_contains($this->alnum($f['name']), $key)));
            }

            if (count($matches) === 1) {
                $case->update(['quote_url' => DriveLocator::webLink($matches[0])]);
                $result['linked']++;
            } elseif (count($matches) > 1) {
                $result['ambiguous'][] = $this->ambiguousEntry($case, $matches);
            } else {
                $result['not_found']++;
            }
        }

        Cache::forever(self::CACHE_QUOTES, [...$result, 'ran_at' => now()->toIso8601String()]);

        return $result;
    }

    /**
     * 見積サブフォルダのファイル名から見積書番号を採取する（quote_no 逆入力機能の下準備）。
     *
     * @return list<array{quote_no: string, name: string, url: string, modified: ?string}>
     */
    public function quoteFilesFor(Machine $machine): array
    {
        $folderId = $this->locator->folderFor($machine);

        return $folderId ? array_map(fn ($f) => [
            'quote_no' => DriveLocator::normQuoteNo($f['name']),
            'name' => $f['name'],
            'url' => DriveLocator::webLink($f),
            'modified' => $f['modifiedTime'] ?? null,
        ], $this->locator->quotes($folderId)) : [];
    }

    private function matchQuotes(array $files, string $key): array
    {
        return array_values(array_filter($files, fn ($f) => DriveLocator::normQuoteNo($f['name']) === $key));
    }

    private function alnum(string $s): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $s));
    }

    private function ambiguousEntry(TroubleCase $case, array $files): array
    {
        return [
            'case_id' => $case->id,
            'machine_id' => $case->machine_id,
            'date' => $case->date?->format('Y-m-d'),
            'symptom' => mb_substr($case->symptom, 0, 80),
            'candidates' => array_map(fn ($f) => ['name' => $f['name'], 'url' => DriveLocator::webLink($f)], $files),
        ];
    }
}
