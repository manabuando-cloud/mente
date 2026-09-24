import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import CaseCard from '@/Components/CaseCard';
import MachineSelect from '@/Components/MachineSelect';
import type { Case, Machine } from '@/types';
import { compact } from '@/lib/format';

type Filters = { q?: string; machine?: string; site?: string; from?: string; to?: string; sort?: string };

export default function CasesIndex({
    filters,
    cases,
    machines,
    sites,
}: {
    filters: Filters;
    cases: { data: Case[]; total: number; current_page: number; last_page: number; prev_url: string | null; next_url: string | null };
    machines: Machine[];
    sites: string[];
}) {
    const [f, setF] = useState<Filters>(filters);
    const search = (patch: Filters = {}) => {
        const next = { ...f, ...patch };
        setF(next);
        router.get('/cases', compact(next), { preserveState: true, replace: true });
    };

    return (
        <AppLayout
            title="症状検索"
            actions={
                <Link href="/cases/create" className="btn btn-primary">
                    ＋ 症状を登録
                </Link>
            }
        >
            <form
                className="card mb-5 space-y-3 p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    search();
                }}
            >
                <div className="flex gap-2">
                    <input
                        className="input text-base"
                        type="search"
                        autoFocus
                        placeholder="症状・エラーコード・部品名などで検索（スペース区切りでAND）"
                        value={f.q ?? ''}
                        onChange={(e) => setF({ ...f, q: e.target.value })}
                    />
                    <button className="btn btn-primary shrink-0 px-5">検索</button>
                </div>
                <div className="grid gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_1fr]">
                    <MachineSelect machines={machines} value={f.machine} onChange={(v) => search({ machine: v })} allowEmpty />
                    <select className="input" value={f.site ?? ''} onChange={(e) => search({ site: e.target.value })} aria-label="拠点">
                        <option value="">すべての拠点</option>
                        {sites.map((s) => (
                            <option key={s}>{s}</option>
                        ))}
                    </select>
                    <input className="input" type="date" aria-label="対応日（から）" value={f.from ?? ''} onChange={(e) => search({ from: e.target.value })} />
                    <input className="input" type="date" aria-label="対応日（まで）" value={f.to ?? ''} onChange={(e) => search({ to: e.target.value })} />
                    <select className="input" value={f.sort ?? ''} onChange={(e) => search({ sort: e.target.value })} aria-label="並び順">
                        <option value="">新しい順</option>
                        <option value="rating">評価の高い順</option>
                    </select>
                </div>
            </form>

            <p className="mb-3 text-sm text-muted">{cases.total.toLocaleString()}件</p>

            <div className="space-y-3">
                {cases.data.length === 0 && (
                    <div className="card p-8 text-center text-sm text-muted">
                        該当する対応履歴がありません。
                        {f.q && (
                            <>
                                <br />
                                <Link href={`/consult?symptom=${encodeURIComponent(f.q)}${f.machine ? `&machine=${f.machine}` : ''}`} className="mt-2 inline-block text-accent-ink hover:underline">
                                    🤖 この症状をAIに相談する
                                </Link>
                            </>
                        )}
                    </div>
                )}
                {cases.data.map((c) => (
                    <CaseCard key={c.id} c={c} />
                ))}
            </div>

            {cases.last_page > 1 && (
                <nav className="mt-5 flex items-center justify-center gap-3 text-sm">
                    {cases.prev_url ? (
                        <Link href={cases.prev_url} className="btn" preserveScroll={false}>
                            ← 前へ
                        </Link>
                    ) : (
                        <span className="btn opacity-40">← 前へ</span>
                    )}
                    <span className="text-muted tabular-nums">
                        {cases.current_page} / {cases.last_page}
                    </span>
                    {cases.next_url ? (
                        <Link href={cases.next_url} className="btn">
                            次へ →
                        </Link>
                    ) : (
                        <span className="btn opacity-40">次へ →</span>
                    )}
                </nav>
            )}
        </AppLayout>
    );
}
