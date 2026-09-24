import { Link, useForm } from '@inertiajs/react';
import Markdown from 'react-markdown';
import AppLayout from '@/Layouts/AppLayout';
import CaseCard from '@/Components/CaseCard';
import Field from '@/Components/Field';
import MachineSelect from '@/Components/MachineSelect';
import type { Case, Machine } from '@/types';
import { dateTime } from '@/lib/format';

type Current = { id: number; machine_id: string | null; symptom: string; answer: string | null; created_at: string; similar: Case[] };

export default function ConsultIndex({
    machines,
    history,
    current,
    defaults,
}: {
    machines: Machine[];
    history: { id: number; symptom: string; machine: string | null; created_at: string }[];
    current: Current | null;
    defaults: { machine_id: string | null; symptom: string | null };
}) {
    const form = useForm({
        machine_id: current?.machine_id ?? defaults.machine_id ?? '',
        symptom: current?.symptom ?? defaults.symptom ?? '',
    });

    return (
        <AppLayout title="AI一次相談">
            <div className="grid gap-5 lg:grid-cols-[2fr_1fr]">
                <div className="space-y-5">
                    <form
                        className="card space-y-4 p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/consult');
                        }}
                    >
                        <Field label="機種" error={form.errors.machine_id} htmlFor="machine_id" hint="指定するとその機種の過去事例と取扱説明書を優先して参照します">
                            <MachineSelect id="machine_id" machines={machines} value={form.data.machine_id} onChange={(v) => form.setData('machine_id', v)} allowEmpty emptyLabel="機種を指定しない" />
                        </Field>
                        <Field label="症状" error={form.errors.symptom} htmlFor="symptom">
                            <textarea
                                id="symptom"
                                className="input min-h-28"
                                placeholder="例: 加工中に「E2105 レーザー出力低下」が出て停止する。再起動すると一時的に復旧する。"
                                value={form.data.symptom}
                                onChange={(e) => form.setData('symptom', e.target.value)}
                                required
                            />
                        </Field>
                        <button className="btn btn-primary w-full py-2.5" disabled={form.processing}>
                            {form.processing ? 'AIが過去事例を調べています…（数十秒かかることがあります）' : '🤖 AIに相談する'}
                        </button>
                    </form>

                    {current && (
                        <>
                            <section className="card p-5">
                                <div className="mb-2 flex items-center justify-between text-xs text-muted">
                                    <span>AIの一次診断（参考情報です。最終判断は担当者が行ってください）</span>
                                    <span>{dateTime(current.created_at)}</span>
                                </div>
                                <div className="prose-answer text-sm text-ink-2">
                                    <Markdown>{current.answer ?? ''}</Markdown>
                                </div>
                                <div className="mt-4 border-t border-line pt-3 text-sm">
                                    解決したら{' '}
                                    <Link
                                        href={`/cases/create?${new URLSearchParams({ symptom: current.symptom, ...(current.machine_id ? { machine: current.machine_id } : {}) })}`}
                                        className="font-medium text-accent-ink hover:underline"
                                    >
                                        対応履歴として登録
                                    </Link>
                                    してください。次に同じ症状が出たときの手がかりになります。
                                </div>
                            </section>
                            <section>
                                <h2 className="mb-3 font-bold">参照した過去事例</h2>
                                <div className="space-y-3">
                                    {current.similar.length === 0 && <p className="card p-6 text-center text-sm text-muted">類似する過去事例はありませんでした</p>}
                                    {current.similar.map((c) => (
                                        <CaseCard key={c.id} c={c} compact />
                                    ))}
                                </div>
                            </section>
                        </>
                    )}
                </div>

                <aside className="card h-fit p-4">
                    <h2 className="mb-2 text-sm font-bold">最近の相談</h2>
                    <ul className="divide-y divide-line text-sm">
                        {history.length === 0 && <li className="py-2 text-muted">まだ相談はありません</li>}
                        {history.map((h) => (
                            <li key={h.id}>
                                <Link href={`/consult?id=${h.id}`} className={`block py-2 hover:text-accent-ink ${current?.id === h.id ? 'text-accent-ink' : ''}`}>
                                    <div className="line-clamp-2">{h.symptom}</div>
                                    <div className="text-xs text-muted">
                                        {h.machine ?? '機種未指定'} · {dateTime(h.created_at)}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </aside>
            </div>
        </AppLayout>
    );
}
