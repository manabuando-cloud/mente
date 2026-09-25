<?php

namespace App\Http\Requests;

use App\Models\TroubleCase;
use Illuminate\Foundation\Http\FormRequest;

class CaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        // 全角数字で入力された費用・日数も受け付ける
        foreach (['cost', 'days'] as $k) {
            if (is_string($this->input($k))) {
                $v = preg_replace('/[^\d]/', '', mb_convert_kana($this->input($k), 'n'));
                $this->merge([$k => $v === '' ? null : $v]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'machine_id' => ['required', 'string', 'exists:machines,id'],
            'date' => ['nullable', 'date'],
            'engineer' => ['nullable', 'string', 'max:100'],
            'symptom' => ['required', 'string', 'max:5000'],
            'report_no' => ['nullable', 'string', 'max:100'],
            'quote_no' => ['nullable', 'string', 'max:100'],
            'cause' => ['nullable', 'string', 'max:5000'],
            'action' => ['nullable', 'string', 'max:5000'],
            'codes' => ['nullable', 'string', 'max:1000'],
            'parts' => ['nullable', 'array', 'max:50'],
            'parts.*.n' => ['required', 'string', 'max:300'],
            'parts.*.id' => ['nullable', 'string', 'max:100'],
            'parts.*.q' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:5000'],
            'days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'report_url' => ['nullable', 'url', 'max:2000'],
            'quote_url' => ['nullable', 'url', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['image', 'max:10240'],
            'remove_photo_ids' => ['nullable', 'array'],
            'remove_photo_ids.*' => ['integer'],
        ];
    }

    public function attributes(): array
    {
        return [
            'machine_id' => '機種', 'date' => '対応日', 'symptom' => '症状', 'cost' => '費用',
            'days' => '停止日数', 'parts.*.n' => '部品名', 'parts.*.q' => '数量', 'report_url' => '報告書PDFのURL', 'quote_url' => '見積書PDFのURL', 'photos.*' => '写真',
        ];
    }

    public function caseAttributes(): array
    {
        $data = collect($this->validated())->except(['photos', 'remove_photo_ids'])->all();
        // 部品を全部消すと multipart では parts キー自体が送られないので、無ければ空として扱う
        $data['parts'] = TroubleCase::normalizeParts($data['parts'] ?? []);

        return $data;
    }
}
