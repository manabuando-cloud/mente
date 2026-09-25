<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\Machine;
use App\Models\TroubleCase;
use App\Models\User;
use App\Services\Gemini\GeminiClient;

/** AI一次相談（Gemini）: 過去事例と取扱説明書を参照して一次診断を返す。 */
class ConsultationService
{
    public function __construct(
        private GeminiClient $gemini,
        private SimilarCaseFinder $finder,
    ) {}

    public function consult(string $symptom, ?string $machineId, ?User $user = null): Consultation
    {
        $machine = $machineId ? Machine::find($machineId) : null;
        $similar = $this->finder->find($symptom, $machineId, 8);

        $answer = $this->gemini->generateText(
            $this->buildPrompt($symptom, $machine, $similar->all()),
            <<<'SYS'
            あなたは工場設備（レーザー加工機・ベンディングマシン等）の保全エンジニアのアシスタントです。
            現場担当者からの症状報告に対して、過去の対応履歴と取扱説明書を根拠に一次診断を行います。
            - 安全を最優先し、感電・挟まれ・レーザー光など危険が伴う作業は必ず注意喚起する
            - 根拠にした過去事例は [事例ID] の形で明記する
            - 推測の場合は推測であると明記し、メーカー連絡が必要なケースはそう伝える
            - 回答は日本語、Markdown。「考えられる原因」「まず確認すること」「対処の手順」「メーカー連絡の目安」の順
            SYS
        );

        return Consultation::create([
            'machine_id' => $machine?->id,
            'site' => $machine?->site,
            'symptom' => $symptom,
            'answer' => $answer,
            'similar_case_ids' => $similar->pluck('id')->all(),
            'source' => 'web',
            'user_id' => $user?->id,
        ]);
    }

    /** @param  list<TroubleCase>  $similar */
    private function buildPrompt(string $symptom, ?Machine $machine, array $similar): string
    {
        $lines = ['# 相談内容'];
        $lines[] = $machine
            ? "機種: {$machine->displayName()}（メーカー: {$machine->maker}、拠点: {$machine->site}）"
            : '機種: 未指定';
        $lines[] = "症状: {$symptom}";

        if ($machine && $machine->manuals) {
            $lines[] = "\n# 取扱説明書";
            foreach ($machine->manuals as $m) {
                $lines[] = '- '.($m['title'] ?? '取説').': '.($m['url'] ?? '');
            }
        }

        $lines[] = "\n# 類似する過去の対応履歴";
        if (! $similar) {
            $lines[] = '（該当なし）';
        }
        foreach ($similar as $c) {
            $lines[] = sprintf(
                "## [%s] %s %s\n症状: %s\n原因: %s\n対処: %s\nエラーコード: %s\n交換部品: %s",
                $c->id,
                $c->date?->format('Y-m-d') ?? '日付不明',
                $c->machine?->displayName() ?? $c->machine_id,
                $c->symptom,
                $c->cause ?: '-',
                $c->action ?: '-',
                $c->codes ?: '-',
                implode('、', $c->partNames()) ?: '-',
            );
        }

        return implode("\n", $lines);
    }
}
