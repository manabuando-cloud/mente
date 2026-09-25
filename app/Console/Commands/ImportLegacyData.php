<?php

namespace App\Console\Commands;

use App\Models\CasePhoto;
use App\Models\CaseRating;
use App\Models\Consultation;
use App\Models\Machine;
use App\Models\TroubleCase;
use App\Models\User;
use App\Support\LegacyValue as V;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GAS版（スプレッドシート + Data.gs + index.html内蔵MACHINES）からのデータ移行。
 *
 * 各シートを「ファイル → ダウンロード → CSV」で書き出したもの、または
 * Data.gs の SEED_CASES / index.html の MACHINES を JSON にしたものを読み込む。
 * IDベースで upsert するので何度実行しても重複しない（冪等）。
 */
class ImportLegacyData extends Command
{
    protected $signature = 'navi:import
        {--machines= : 機種マスタ（MACHINES の JSON、または Machines シートのCSV）}
        {--soft= : 全社の設備マスタ（「設備マスタ一覧（SOFTエクスポート）」のCSV）}
        {--cases= : 対応履歴（Cases シートのCSV、または SEED_CASES の JSON）}
        {--pending= : 確認待ち（PendingCases シートのCSV）}
        {--ratings= : 評価（Ratings シートのCSV）}
        {--consultations= : AI相談履歴（Consultations シートのCSV）}
        {--source=seed : 対応履歴の source 列に入れる値}';

    protected $description = '旧GAS版のスプレッドシート/JSONからデータを取り込む（冪等）';

    public function handle(): int
    {
        if (! array_filter($this->options(), fn ($v, $k) => $v && $k !== 'source', ARRAY_FILTER_USE_BOTH)) {
            $this->error('取り込むファイルを1つ以上指定してください（--soft / --machines / --cases / --pending / --ratings / --consultations）');

            return self::INVALID;
        }

        DB::transaction(function () {
            if ($f = $this->option('soft')) {
                $this->importSoftMaster($this->read($f));
            }
            if ($f = $this->option('machines')) {
                $this->importMachines($this->read($f));
            }
            if ($f = $this->option('cases')) {
                $this->importCases($this->read($f), TroubleCase::REVIEW_PUBLISHED);
            }
            if ($f = $this->option('pending')) {
                $this->importCases($this->read($f), null);
            }
            if ($f = $this->option('ratings')) {
                $this->importRatings($this->read($f));
            }
            if ($f = $this->option('consultations')) {
                $this->importConsultations($this->read($f));
            }
        });

        return self::SUCCESS;
    }

    /**
     * 設備マスタ一覧（SOFTエクスポート）。Driveの機械フォルダ名の先頭と同じ「シリアルNO」を機械番号にする。
     * シリアルNOが空・壊れている（Excelの指数表記化など）ものは "EQ-<設備NO>" にする。
     */
    private function importSoftMaster(array $rows): void
    {
        $seen = [];
        $n = 0;
        $dup = [];
        foreach ($rows as $r) {
            $no = V::id($r['設備NO'] ?? null);
            $serial = $this->softSerial($r);
            if (! $no && ! $serial) {
                continue;
            }
            // シリアルNOの重複は、最初の行（新しい設備順）だけシリアルそのものを機械番号にする
            $id = match (true) {
                $serial && ! isset($seen[$serial]) => $serial,
                (bool) $serial => $dup[] = "{$serial}-{$no}",
                default => "EQ-{$no}",
            };
            if ($serial) {
                $seen[$serial] = true;
            }

            Machine::updateOrCreate(['id' => $id], array_filter([
                'model' => V::str($r['設備名'] ?? null) ?? V::str($r['機械番号'] ?? null) ?? $id,
                'maker' => V::stripCode($r['メーカー'] ?? null),
                'label' => V::stripCode($r['呼称'] ?? null),
                'site' => V::site($r['事業所'] ?? null),
                'category' => V::stripCode($r['設備分類'] ?? null),
                'equipment_no' => $no,
                'spec' => V::str($r['仕様'] ?? null),
                'installed_on' => V::date($r['導入日'] ?? null),
                'source' => 'master',
            ], fn ($v) => $v !== null));
            $n++;
        }
        $this->info("設備マスタ: {$n}件");
        if ($dup) {
            $this->warn('シリアルNOが重複していたため、2件目以降を「シリアルNO-設備NO」で登録しました（機械マスター画面で確認してください）: '.implode(', ', $dup));
        }
    }

    private function softSerial(array $r): ?string
    {
        $s = V::id($r['シリアルNO'] ?? null);
        // "1.41E-185" のように数値化で壊れたものや、"S/N 003282" の接頭辞を扱う
        if ($s === null || preg_match('/^\d+(\.\d+)?E[+\-]?\d+$/i', $s)) {
            return null;
        }

        return (string) preg_replace('#^S/N\s*#i', '', $s);
    }

    private function importMachines(array $rows): void
    {
        $n = 0;
        foreach ($rows as $key => $r) {
            $id = V::id($r['id'] ?? (is_string($key) ? $key : null));
            if (! $id) {
                continue;
            }
            $existing = Machine::find($id);
            Machine::updateOrCreate(['id' => $id], array_filter([
                'model' => V::str($r['model'] ?? $r['name'] ?? null) ?? $existing?->model ?? $id,
                'maker' => V::str($r['maker'] ?? null),
                'label' => V::str($r['label'] ?? null),
                'site' => V::site($r['site'] ?? null),
                'category' => V::str($r['category'] ?? $r['cat'] ?? null),
                'manuals' => V::manuals($r['manuals'] ?? $r['manual'] ?? []) ?: null,
                'source' => isset($r['submittedBy']) ? 'user' : 'master',
                'submitted_by' => V::str($r['submittedBy'] ?? null),
            ], fn ($v) => $v !== null));
            $n++;
        }
        $this->info("機種マスタ: {$n}件");
    }

    private function importCases(array $rows, ?string $reviewStatus): void
    {
        $n = 0;
        $missingMachines = [];
        foreach ($rows as $r) {
            $id = V::str($r['id'] ?? null);
            $machineId = V::id($r['m'] ?? $r['machine_id'] ?? null);
            if (! $id || ! $machineId) {
                continue;
            }
            // 見積のみ・移設工事などは症状欄が「—」のことがある。記録自体は残す
            $symptom = V::str($r['symptom'] ?? null) ?? '（症状の記録なし）';
            if (! Machine::whereKey($machineId)->exists()) {
                // マスタに無い機種を参照している履歴も失わないよう仮登録する
                Machine::create(['id' => $machineId, 'model' => $machineId, 'source' => 'import']);
                $missingMachines[] = $machineId;
            }

            $status = $reviewStatus ?? match (V::str($r['reviewStatus'] ?? null)) {
                'approved', 'promoted' => TroubleCase::REVIEW_PUBLISHED,
                'rejected' => TroubleCase::REVIEW_REJECTED,
                default => TroubleCase::REVIEW_PENDING,
            };
            // PendingCases の promoted 行は Cases 側に同じIDで入っているので上書きしない
            if ($reviewStatus === null && V::str($r['reviewStatus'] ?? null) === 'promoted' && TroubleCase::whereKey($id)->exists()) {
                continue;
            }

            $case = TroubleCase::updateOrCreate(['id' => $id], [
                'machine_id' => $machineId,
                'date' => V::date($r['date'] ?? null),
                'engineer' => V::str($r['eng'] ?? null),
                'symptom' => $symptom,
                'report_no' => V::str($r['reportNo'] ?? null),
                'quote_no' => V::str($r['quoteNo'] ?? null),
                'cause' => V::str($r['cause'] ?? null),
                'action' => V::str($r['action'] ?? null),
                'codes' => V::list($r['codes'] ?? null),
                'parts' => TroubleCase::normalizeParts(V::str($r['parts'] ?? null) ?? []),
                'cost' => V::int($r['cost'] ?? null),
                'status' => V::str($r['status'] ?? null),
                'note' => V::str($r['note'] ?? null),
                'days' => V::int($r['days'] ?? null),
                'submitted_by' => V::str($r['submittedBy'] ?? null),
                'slack_notified_at' => V::bool($r['slackNotified'] ?? '') ? (V::datetime($r['createdAt'] ?? null) ?? now()) : null,
                'report_url' => V::str($r['reportUrl'] ?? null),
                'quote_url' => V::str($r['quoteUrl'] ?? null),
                'review_status' => $status,
                'source' => $reviewStatus === null ? 'ai_ingest' : $this->option('source'),
                'source_file_id' => V::str($r['sourceFileId'] ?? null),
                'source_url' => V::str($r['sourceUrl'] ?? null),
                'review_note' => V::str($r['reviewNote'] ?? null),
            ]);
            if ($created = V::datetime($r['createdAt'] ?? null)) {
                $case->created_at = $created;
                $case->updated_at = V::datetime($r['updatedAt'] ?? null) ?? $created;
                $case->timestamps = false;
                $case->saveQuietly();
            }

            // photos: Drive上の写真URL（カンマ区切り or JSON配列）は外部URLのまま保持
            $photos = $r['photos'] ?? null;
            $photos = is_string($photos) ? (json_decode($photos, true) ?? preg_split('/[\s,]+/', $photos, -1, PREG_SPLIT_NO_EMPTY)) : (array) $photos;
            foreach ($photos as $p) {
                $url = is_array($p) ? ($p['url'] ?? null) : $p;
                if ($url && preg_match('#^https?://#', $url)) {
                    CasePhoto::firstOrCreate(['trouble_case_id' => $case->id, 'path' => $url]);
                }
            }
            $n++;
        }
        $label = $reviewStatus ? '対応履歴' : '確認待ち';
        $this->info("{$label}: {$n}件");
        if ($missingMachines) {
            $this->warn('マスタに無い機種を仮登録しました: '.implode(', ', array_unique($missingMachines)));
        }
    }

    private function importRatings(array $rows): void
    {
        $n = 0;
        foreach ($rows as $r) {
            $caseId = V::str($r['caseId'] ?? null);
            $uid = V::str($r['uid'] ?? null);
            $value = (int) ($r['value'] ?? 0);
            if (! $caseId || ! $uid || ! in_array($value, [1, -1], true)) {
                continue;
            }
            $email = str_contains($uid, '@') ? strtolower($uid) : $uid.'@legacy.invalid';
            $user = User::firstOrCreate(['email' => $email], ['name' => strstr($email, '@', true)]);
            CaseRating::updateOrCreate(['trouble_case_id' => $caseId, 'user_id' => $user->id], ['value' => $value]);
            $n++;
        }
        $this->info("評価: {$n}件");
    }

    private function importConsultations(array $rows): void
    {
        $n = 0;
        foreach ($rows as $r) {
            $symptom = V::str($r['symptom'] ?? null);
            if (! $symptom) {
                continue;
            }
            $similar = $r['similarCaseIds'] ?? [];
            $similar = is_string($similar) ? (json_decode($similar, true) ?? preg_split('/[\s,]+/', $similar, -1, PREG_SPLIT_NO_EMPTY)) : $similar;
            $createdAt = V::datetime($r['createdAt'] ?? null);
            $attrs = [
                'trouble_case_id' => V::str($r['caseId'] ?? null),
                'machine_id' => V::id($r['m'] ?? null),
                'site' => V::site($r['site'] ?? null),
                'symptom' => $symptom,
                'answer' => V::str($r['answer'] ?? null),
                'similar_case_ids' => array_values((array) $similar),
                'source' => V::str($r['source'] ?? null) ?? 'gas',
            ];
            // 旧データのIDは数値でない可能性があるので、症状+日時で冪等化
            $c = Consultation::firstOrNew(['symptom' => $symptom, 'created_at' => $createdAt]);
            $c->fill($attrs);
            if ($createdAt) {
                $c->created_at = $createdAt;
                $c->updated_at = $createdAt;
            }
            $c->save();
            $n++;
        }
        $this->info("AI相談: {$n}件");
    }

    /** CSV（1行目ヘッダー）または JSON（配列 or {id: {...}} オブジェクト）を読む */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("ファイルが見つかりません: {$path}");
        }
        $raw = file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM

        if (preg_match('/\.json$/i', $path) || in_array(ltrim($raw)[0] ?? '', ['[', '{'], true)) {
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                throw new RuntimeException("JSONとして読めません: {$path}");
            }

            return $data;
        }

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $headers = array_map('trim', fgetcsv($fh, escape: '') ?: []);
        $rows = [];
        while (($line = fgetcsv($fh, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($headers, array_pad(array_slice($line, 0, count($headers)), count($headers), null));
        }
        fclose($fh);

        return $rows;
    }
}
