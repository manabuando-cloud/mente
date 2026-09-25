<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\TroubleCase;
use App\Models\VendorFolder;
use App\Services\Drive\DriveClient;
use App\Services\Drive\DriveLocator;
use App\Services\Drive\KnownDriveFiles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 「メーカー作業報告書見積り」フォルダ（業者別に整理）からの取込み。
 *
 * 機械の特定:
 *   1. フォルダの対応表が「1台専用（fixed）」ならその機械
 *   2. ファイル名に "#<機械番号>" か、登録済みの機械番号がそのまま含まれていればその機械
 *      （トルンプの報告書は "20260824-#B0702A0033_TruBend_7036_(B19)_x20ﾓｼﾞｭｰﾙ交換_作業報告書.pdf" の形）
 *   3. どちらも無ければ「機械を特定できないファイル」として一覧に出す（対応表を設定すれば次回取り込まれる）
 * ファイルの種類:
 *   - 点検チェックリスト・納品書・写真・請求書 → 対象外
 *   - 見積 → 同じ機械・同じ日付の対応履歴に見積書PDFとして紐づける（AIは使わない）
 *   - それ以外 → 作業報告書としてAIで読み取り、確認待ちの対応履歴にする
 */
class VendorReportIngestor
{
    public const CACHE_RESULT = 'navi.vendor.last';

    // 実際のフォルダにある報告書・見積以外の書類（2026-09 時点の一覧で確認）
    private const SKIP_PATTERN = '/チェックリスト|ﾁｪｯｸﾘｽﾄ|check\s*(list|sheet)|チェックシート|図面|点検表|納品書|請求書|写真|取説|取扱説明|カレンダー|案内|電力量|Thumbs\.db/iu';

    /** 既定で対象外にするフォルダ */
    private const DEFAULT_SKIP = ['カレンダー'];

    // 半角カナの「ﾐﾂﾓﾘ」、部品価格・金額の書類も見積として扱う
    private const QUOTE_PATTERN = '/見積|ﾐﾂﾓﾘ|ミツモリ|価格|ｶｶｸ|金額|ｷﾝｶﾞｸ|est[_\-]?\d{6,}|SQJ\d+/iu';

    /** 既定の対応表（フォルダ名 → 機械番号）。画面で変更したものが優先される */
    private const DEFAULT_FIXED = [
        '作業報告書-見積書(salvagnini_L3-30)' => 'L_0987',
        '作業報告書-見積書(TrulaserRobot)' => 'LEGACY-TRULASERROBOT-RS07',
        '2023-10-27太陽光発電システムPC故障' => 'LEGACY-SOLAR-PC',
    ];

    public function __construct(
        private DriveClient $drive,
        private DriveLocator $locator,
        private ReportIngestor $reports,
    ) {}

    /** 業者別フォルダを Drive から読み、対応表の行を作る（既存の設定は保つ） */
    public function syncFolders(): Collection
    {
        $root = config('navi.drive.vendor_folder_id');
        if (! $root) {
            return collect();
        }
        foreach ($this->drive->listChildren($root) as $f) {
            if ($f['mimeType'] !== DriveClient::FOLDER_MIME) {
                continue;
            }
            $folder = VendorFolder::find($f['id']);
            if ($folder) {
                $folder->update(['name' => $f['name']]);

                continue;
            }
            $fixed = self::DEFAULT_FIXED[$f['name']] ?? null;
            $fixed = $fixed && Machine::whereKey($fixed)->exists() ? $fixed : null;
            VendorFolder::create([
                'id' => $f['id'],
                'name' => $f['name'],
                'mode' => match (true) {
                    in_array($f['name'], self::DEFAULT_SKIP, true) => VendorFolder::MODE_SKIP,
                    (bool) $fixed => VendorFolder::MODE_FIXED,
                    default => VendorFolder::MODE_FILENAME,
                },
                'machine_id' => $fixed,
            ]);
        }

        return VendorFolder::orderBy('name')->get();
    }

    /**
     * @return array{created: int, quotes_linked: int, quotes_waiting: int, skipped: int, errors: int, quota_exhausted: bool, unresolved: list<array>, messages: list<string>}
     */
    public function ingest(?int $limit = null, bool $dryRun = false): array
    {
        $limit ??= config('navi.ingest_batch_limit');
        $result = ['created' => 0, 'linked_existing' => 0, 'quotes_linked' => 0, 'quotes_waiting' => 0, 'skipped' => 0, 'errors' => 0, 'quota_exhausted' => false, 'unresolved' => [], 'messages' => [], 'plan' => []];

        $folders = $this->syncFolders();
        $known = KnownDriveFiles::ids();
        $matcher = $this->machineMatcher();

        // 直下のファイル（フォルダに入っていないもの）も「ファイル名から判定」で扱う
        $targets = $folders->reject(fn ($f) => $f->mode === VendorFolder::MODE_SKIP)->values();
        $root = config('navi.drive.vendor_folder_id');
        $rootFiles = array_values(array_filter($this->drive->listChildren($root), fn ($f) => $f['mimeType'] !== DriveClient::FOLDER_MIME));

        $quotes = [];
        foreach ([[null, $rootFiles], ...$targets->map(fn ($f) => [$f, $this->locator->pdfsRecursive($f->id)])->all()] as [$folder, $files]) {
            foreach ($files as $file) {
                if (isset($known[$file['id']])) {
                    continue;
                }
                if (preg_match(self::SKIP_PATTERN, $file['name']) || ! str_ends_with(strtolower($file['name']), '.pdf')) {
                    $result['skipped']++;
                    $dryRun && $result['plan'][] = [$folder?->name ?? '（直下）', $file['name'], '', '対象外'];

                    continue;
                }
                $machine = $folder?->mode === VendorFolder::MODE_FIXED && $folder->machine
                    ? $folder->machine
                    : $matcher($file['name']);
                if ($dryRun) {
                    $kind = match (true) {
                        ! $machine => '機械不明',
                        (bool) preg_match(self::QUOTE_PATTERN, $file['name']) => '見積',
                        default => match ($this->reports->existingDecision($machine, $file)[0]) {
                            'link' => '既存にリンク',
                            'skip' => '既存あり（取込まない）',
                            default => '新規（AIで読む）',
                        },
                    };
                    $result['plan'][] = [$folder?->name ?? '（直下）', $file['name'], $machine?->id ?? '', $kind];
                    $kind === '機械不明' && $result['unresolved'][] = ['name' => $file['name'], 'url' => DriveLocator::webLink($file), 'folder' => $folder?->name ?? '（直下）', 'folder_id' => $folder?->id];

                    continue;
                }
                if (! $machine) {
                    if (count($result['unresolved']) < 300) {
                        $result['unresolved'][] = ['name' => $file['name'], 'url' => DriveLocator::webLink($file), 'folder' => $folder?->name ?? '（直下）', 'folder_id' => $folder?->id];
                    }

                    continue;
                }
                if (preg_match(self::QUOTE_PATTERN, $file['name'])) {
                    $quotes[] = [$machine, $file];

                    continue;
                }
                if ($limit <= $result['created'] + $result['errors']) {
                    $result['messages'][] = "報告書の読み取りが上限（{$limit}件）に達したため、残りは次回に処理します";

                    continue;
                }
                if ($result['quota_exhausted']) {
                    continue;
                }
                $context = $folder ? "業者フォルダ: {$folder->name}" : null;
                $this->reports->ingestOne($machine, $file, $result, $context);
            }
            $dryRun || $folder?->forceFill(['scanned_at' => now()])->save();
        }
        if ($dryRun) {
            return $result;
        }

        // 報告書を先に取り込んでから、見積を同じ日の履歴に紐づける
        foreach ($quotes as [$machine, $file]) {
            $this->linkQuote($machine, $file, $result);
        }

        Cache::forever(self::CACHE_RESULT, [...$result, 'ran_at' => now()->toIso8601String()]);

        return $result;
    }

    private function linkQuote(Machine $machine, array $file, array &$result): void
    {
        $date = DriveLocator::reportDate($file['name']);
        $candidates = $date ? TroubleCase::query()
            ->where('machine_id', $machine->id)
            ->whereDate('date', $date)
            ->whereIn('review_status', [TroubleCase::REVIEW_PUBLISHED, TroubleCase::REVIEW_PENDING])
            ->whereNull('quote_url')
            ->get() : collect();

        if ($candidates->count() !== 1) {
            // 同じ日の履歴がまだ無い（報告書が後から来る）か、複数ある → 次回また試す
            $result['quotes_waiting']++;

            return;
        }

        $quoteNo = preg_match('/(est[_\-]?\d{6,}|SQJ\d+)/i', $file['name'], $m) ? strtolower(str_replace(['_', '-'], '', $m[1])) : null;
        $candidates->first()->update(array_filter([
            'quote_url' => DriveLocator::webLink($file),
            'quote_no' => $candidates->first()->quote_no ?: $quoteNo,
        ]));
        $this->reports->markProcessed($file, $machine->id, 'quote_linked');
        $result['quotes_linked']++;
    }

    /**
     * ファイル名から機械を探す関数を返す。
     *   1. "#<機械番号>"（トルンプ: "20260824-#B0702A0033_..." / "Las18127#A0231A0105.pdf"）
     *   2. 登録済み機械番号（6文字以上）がそのまま含まれる（"SAS37VD-6E／J3E0AV0281"）
     *   3. 型式名が含まれ、その型式の機械が1台だけ（"AuDeBuMini" → AuDeBu Mini）
     * どれも1台に絞れなければ特定できないものとして扱う。
     *
     * @return \Closure(string): ?Machine
     */
    private function machineMatcher(): \Closure
    {
        $machines = Machine::all()->keyBy('id');
        $ids = $machines->keys()->filter(fn ($id) => strlen($id) >= 6 && ! str_starts_with($id, 'EQ-'))
            ->sortByDesc(fn ($id) => strlen($id))->values();
        // 型式名（英数字だけに正規化して8文字以上）→ 機械。同じ型式が複数台あるものは使わない
        $allModels = $machines->map(fn ($m) => self::normModel($m->model));
        $models = $machines->groupBy(fn ($m) => self::normModel($m->model))
            ->filter(fn ($g, $key) => strlen($key) >= 8 && $g->count() === 1
                // "trumatic6000fiber" のように他の型式名（"trumatic6000fiberk06"）に含まれるものは曖昧なので使わない
                && ! $allModels->contains(fn ($other) => $other !== $key && str_contains($other, $key)))
            ->map(fn ($g) => $g->first());

        return function (string $name) use ($machines, $ids, $models): ?Machine {
            $name = mb_convert_kana($name, 'as');
            $tagged = preg_match('/#\s*([A-Za-z0-9_\-]{6,}?)(?=[_\s(（.]|$)/u', $name, $m);
            if ($tagged && isset($machines[$m[1]])) {
                return $machines[$m[1]];
            }
            $upper = strtoupper($name);
            $hits = $ids->filter(fn ($id) => preg_match('/(?<![A-Z0-9])'.preg_quote(strtoupper($id), '/').'(?![A-Z0-9])/', $upper))->values();
            // 長い機械番号に含まれる短い機械番号の一致は除く
            $hits = $hits->reject(fn ($id) => $hits->contains(fn ($other) => $other !== $id && str_contains($other, $id)))->values();
            if ($hits->count() === 1) {
                return $machines[$hits[0]];
            }
            // "#<機械番号>" があるのに未登録なら打ち間違いの可能性があるので、型式名での推測はしない
            if ($hits->isEmpty() && ! $tagged) {
                $flat = self::normModel($name);
                $byModel = $models->filter(fn ($m, $key) => str_contains($flat, $key));
                // 長い型式名に含まれる短い型式名の一致は除く（"TruBend5170" と "TruBend5170+TM" など）
                $byModel = $byModel->reject(fn ($m, $key) => $byModel->keys()->contains(fn ($other) => $other !== $key && str_contains($other, $key)));

                return $byModel->count() === 1 ? $byModel->first() : null;
            }

            return null;
        };
    }

    private static function normModel(?string $v): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', mb_convert_kana((string) $v, 'as')));
    }
}
