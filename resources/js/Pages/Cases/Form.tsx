import { Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import Field from '@/Components/Field';
import MachineSelect from '@/Components/MachineSelect';
import type { Case, Machine } from '@/types';

type FormData = {
    machine_id: string;
    date: string;
    engineer: string;
    symptom: string;
    cause: string;
    action: string;
    codes: string;
    parts: string;
    cost: string;
    days: string;
    status: string;
    report_no: string;
    quote_no: string;
    report_url: string;
    quote_url: string;
    note: string;
    photos: File[];
    remove_photo_ids: number[];
    _method?: string;
};

const STATUSES = ['完了', '対応中', '部品待ち', 'メーカー対応待ち', '経過観察'];

export default function CaseForm({
    case: c,
    machines,
    defaults,
}: {
    case: Case | null;
    machines: Machine[];
    defaults: { machine_id?: string | null; symptom?: string | null; date?: string; engineer?: string } | null;
}) {
    const editing = !!c;
    const form = useForm<FormData>({
        machine_id: c?.machine_id ?? defaults?.machine_id ?? '',
        date: c?.date ?? defaults?.date ?? '',
        engineer: c?.engineer ?? defaults?.engineer ?? '',
        symptom: c?.symptom ?? defaults?.symptom ?? '',
        cause: c?.cause ?? '',
        action: c?.action ?? '',
        codes: c?.codes_raw ?? '',
        parts: c?.parts_raw ?? '',
        cost: c?.cost?.toString() ?? '',
        days: c?.days?.toString() ?? '',
        status: c?.status ?? '完了',
        report_no: c?.report_no ?? '',
        quote_no: c?.quote_no ?? '',
        report_url: c?.report_url ?? '',
        quote_url: c?.quote_url ?? '',
        note: c?.note ?? '',
        photos: [],
        remove_photo_ids: [],
        ...(editing ? { _method: 'put' } : {}),
    });
    const { data, setData, errors, processing } = form;

    const previews = useMemo(() => data.photos.map((f) => URL.createObjectURL(f)), [data.photos]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(editing ? `/cases/${c!.id}` : '/cases', { forceFormData: true });
    };

    const text = (key: keyof FormData) => ({
        id: key,
        value: data[key] as string,
        onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => setData(key, e.target.value as never),
    });

    return (
        <AppLayout title={editing ? '対応履歴の編集' : '症状登録'}>
            <form onSubmit={submit} className="grid gap-5 lg:grid-cols-[2fr_1fr]">
                <div className="card space-y-4 p-5">
                    <Field label="機種 *" error={errors.machine_id} htmlFor="machine_id">
                        <MachineSelect id="machine_id" machines={machines} value={data.machine_id} onChange={(v) => setData('machine_id', v)} />
                    </Field>
                    <Field label="症状 *" error={errors.symptom} htmlFor="symptom" hint="現象・発生タイミング・表示されたメッセージなど">
                        <textarea className="input min-h-28" {...text('symptom')} required />
                    </Field>
                    <Field label="原因" error={errors.cause} htmlFor="cause">
                        <textarea className="input min-h-20" {...text('cause')} />
                    </Field>
                    <Field label="対処" error={errors.action} htmlFor="action">
                        <textarea className="input min-h-20" {...text('action')} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="エラーコード" error={errors.codes} htmlFor="codes" hint="複数ある場合はカンマ区切り">
                            <input className="input font-mono" {...text('codes')} placeholder="E1234, A-56" />
                        </Field>
                        <Field label="交換部品" error={errors.parts} htmlFor="parts" hint="複数ある場合はカンマ区切り">
                            <input className="input" {...text('parts')} />
                        </Field>
                    </div>
                    <Field label="備考" error={errors.note} htmlFor="note">
                        <textarea className="input min-h-16" {...text('note')} />
                    </Field>

                    <Field label="写真" error={errors.photos ?? (errors as Record<string, string>)['photos.0']} hint="10枚まで・1枚10MBまで">
                        <input
                            type="file"
                            accept="image/*"
                            multiple
                            className="block w-full text-sm text-ink-2 file:mr-3 file:rounded-md file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-sm file:font-medium"
                            onChange={(e) => setData('photos', [...data.photos, ...Array.from(e.target.files ?? [])])}
                        />
                        <div className="mt-2 flex flex-wrap gap-2">
                            {c?.photos
                                .filter((p) => !data.remove_photo_ids.includes(p.id))
                                .map((p) => (
                                    <Thumb key={p.id} src={p.url} onRemove={() => setData('remove_photo_ids', [...data.remove_photo_ids, p.id])} />
                                ))}
                            {previews.map((src, i) => (
                                <Thumb key={src} src={src} onRemove={() => setData('photos', data.photos.filter((_, j) => j !== i))} />
                            ))}
                        </div>
                    </Field>
                </div>

                <div className="space-y-5">
                    <div className="card space-y-4 p-5">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="対応日" error={errors.date} htmlFor="date" className="col-span-2">
                                <input type="date" className="input" {...text('date')} />
                            </Field>
                            <Field label="担当者" error={errors.engineer} htmlFor="engineer" className="col-span-2">
                                <input className="input" {...text('engineer')} />
                            </Field>
                            <Field label="費用（円）" error={errors.cost} htmlFor="cost">
                                <input className="input text-right tabular-nums" inputMode="numeric" {...text('cost')} />
                            </Field>
                            <Field label="停止日数" error={errors.days} htmlFor="days">
                                <input className="input text-right tabular-nums" inputMode="numeric" {...text('days')} />
                            </Field>
                            <Field label="状況" error={errors.status} htmlFor="status" className="col-span-2">
                                <input className="input" list="statuses" {...text('status')} />
                                <datalist id="statuses">
                                    {STATUSES.map((s) => (
                                        <option key={s} value={s} />
                                    ))}
                                </datalist>
                            </Field>
                        </div>
                    </div>

                    <div className="card space-y-4 p-5">
                        <h2 className="text-sm font-bold">報告書・見積書</h2>
                        <Field label="作業報告書番号" error={errors.report_no} htmlFor="report_no">
                            <input className="input" {...text('report_no')} />
                        </Field>
                        <Field label="報告書PDFのURL" error={errors.report_url} htmlFor="report_url" hint="DriveのPDFを開いてURLを貼り付け">
                            <input type="url" className="input" {...text('report_url')} placeholder="https://drive.google.com/file/d/…" />
                        </Field>
                        <Field label="見積書番号" error={errors.quote_no} htmlFor="quote_no" hint="例: est23102936（入力するとPDFを自動リンクできます）">
                            <input className="input font-mono" {...text('quote_no')} />
                        </Field>
                        <Field label="見積書PDFのURL" error={errors.quote_url} htmlFor="quote_url">
                            <input type="url" className="input" {...text('quote_url')} placeholder="https://drive.google.com/file/d/…" />
                        </Field>
                    </div>

                    <div className="flex gap-2">
                        <button className="btn btn-primary flex-1 py-2.5" disabled={processing}>
                            {processing ? '保存中…' : editing ? '更新する' : '登録する'}
                        </button>
                        <Link href={editing ? `/cases/${c!.id}` : '/cases'} className="btn py-2.5">
                            キャンセル
                        </Link>
                    </div>
                    {form.progress && <progress className="w-full" value={form.progress.percentage} max={100} />}
                    {!editing && <p className="text-xs text-muted">登録するとSlackに通知されます。</p>}
                </div>
            </form>
        </AppLayout>
    );
}

function Thumb({ src, onRemove }: { src: string; onRemove: () => void }) {
    return (
        <div className="relative">
            <img src={src} alt="" className="size-20 rounded-md border border-line object-cover" />
            <button type="button" aria-label="写真を削除" onClick={onRemove} className="absolute -top-2 -right-2 grid size-6 place-items-center rounded-full bg-ink text-xs text-surface">
                ×
            </button>
        </div>
    );
}
