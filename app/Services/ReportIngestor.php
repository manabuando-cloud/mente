<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\ProcessedReportFile;
use App\Models\TroubleCase;
use App\Services\Drive\DriveClient;
use App\Services\Drive\DriveLocator;
use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiQuotaExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 作業報告書PDFの自動取込み（旧: ingestNewReports）。
 *
 * 各拠点の機械フォルダから未処理の ActivityReport PDF を探し、Gemini にPDFを直接読ませて
 * 構造化データを抽出、review_status=pending の対応履歴として登録する（人の承認待ち）。
 * 429（クォータ超過）を受けたらバッチを即中断する。
 */
class ReportIngestor
{
    private const SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'date' => ['type' => 'STRING', 'description' => '対応日 YYYY-MM-DD'],
            'engineer' => ['type' => 'STRING', 'description' => '作業者・サービスエンジニア名'],
            'report_no' => ['type' => 'STRING'],
            'symptom' => ['type' => 'STRING', 'description' => '発生していた症状・不具合内容'],
            'cause' => ['type' => 'STRING'],
            'action' => ['type' => 'STRING', 'description' => '実施した処置・対応内容'],
            'codes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'アラーム・エラーコード'],
            'parts' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => '交換・使用した部品'],
            'status' => ['type' => 'STRING', 'description' => '完了 / 継続対応 など'],
            'note' => ['type' => 'STRING'],
        ],
        'required' => ['symptom'],
    ];

    public function __construct(
        private DriveClient $drive,
        private DriveLocator $locator,
        private GeminiClient $gemini,
    ) {}

    /**
     * @return array{created: int, skipped: int, errors: int, quota_exhausted: bool, messages: list<string>}
     */
    public function ingest(?int $limit = null, bool $retryErrors = false): array
    {
        $limit ??= config('navi.ingest_batch_limit');
        $result = ['created' => 0, 'skipped' => 0, 'errors' => 0, 'quota_exhausted' => false, 'messages' => []];

        $processed = ProcessedReportFile::query()
            ->when($retryErrors, fn ($q) => $q->where('result', 'not like', 'error%'))
            ->pluck('file_id')->flip();

        foreach ($this->locator->machineFolders() as $mf) {
            foreach ($this->locator->activityReports($mf['folder']['id']) as $file) {
                if (isset($processed[$file['id']])) {
                    continue;
                }
                if ($limit <= $result['created'] + $result['errors']) {
                    $result['messages'][] = "上限（{$limit}件）に達したため残りは次回に処理します";

                    return $result;
                }

                try {
                    $this->ingestFile($mf, $file);
                    $result['created']++;
                } catch (GeminiQuotaExceededException $e) {
                    // 処理済みにはしない（次回リトライ）。無駄な失敗の連続を避けて即中断。
                    $result['quota_exhausted'] = true;
                    $result['messages'][] = $e->getMessage();

                    return $result;
                } catch (Throwable $e) {
                    Log::warning("報告書取込みに失敗: {$file['name']}", ['error' => $e->getMessage()]);
                    $this->markProcessed($file, $mf['machine_id'], 'error: '.mb_substr($e->getMessage(), 0, 200));
                    $result['errors']++;
                    $result['messages'][] = "{$file['name']}: {$e->getMessage()}";
                }
            }
        }

        return $result;
    }

    private function ingestFile(array $mf, array $file): TroubleCase
    {
        $machine = Machine::firstOrCreate(
            ['id' => $mf['machine_id']],
            ['model' => $mf['model'] ?: $mf['machine_id'], 'site' => $mf['site'], 'source' => 'drive', 'drive_folder_id' => $mf['folder']['id']],
        );

        $pdf = $this->drive->download($file['id']);
        $data = $this->gemini->generateJson(
            "添付は設備保守の作業報告書（ActivityReport）です。機種: {$machine->displayName()}。\n".
            '記載内容から対応履歴の項目を抽出してJSONで返してください。記載が無い項目は空文字にしてください。'.
            '推測で埋めないこと。',
            [['mime' => 'application/pdf', 'data' => $pdf]],
            self::SCHEMA,
        );

        $str = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $list = fn (string $k) => (is_array($data[$k] ?? null)
            ? implode(', ', array_filter(array_map('trim', $data[$k])))
            : trim((string) ($data[$k] ?? ''))) ?: null;
        $date = DriveLocator::reportDate($file['name']) ?? $this->validDate($data['date'] ?? null);

        $case = TroubleCase::create([
            'machine_id' => $machine->id,
            'date' => $date,
            'engineer' => $str('engineer'),
            'report_no' => $str('report_no'),
            'symptom' => $str('symptom') ?? '（報告書から症状を抽出できませんでした）',
            'cause' => $str('cause'),
            'action' => $str('action'),
            'codes' => $list('codes'),
            'parts' => $list('parts'),
            'status' => $str('status'),
            'note' => $str('note'),
            'report_url' => DriveLocator::webLink($file),
            'review_status' => TroubleCase::REVIEW_PENDING,
            'source' => 'ai_ingest',
            'source_file_id' => $file['id'],
            'source_url' => DriveLocator::webLink($file),
            'submitted_by' => 'AI自動取込み',
        ]);

        $this->markProcessed($file, $machine->id, 'pending_created');

        return $case;
    }

    private function markProcessed(array $file, string $machineId, string $result): void
    {
        ProcessedReportFile::updateOrCreate(
            ['file_id' => $file['id']],
            ['machine_id' => $machineId, 'file_name' => $file['name'], 'result' => $result, 'processed_at' => now()],
        );
    }

    private function validDate(?string $v): ?string
    {
        return $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    }
}
