import { Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import CaseCard from '@/Components/CaseCard';
import MachineSelect from '@/Components/MachineSelect';
import { BarList, ColumnChart } from '@/Components/Charts';
import type { Case, Machine } from '@/types';
import { compact, num, yen } from '@/lib/format';
import { useState } from 'react';

type Stats = {
    summary: { count: number; machines: number; total_cost: number; total_days: number; last_date: string | null };
    codes: { label: string; count: number }[];
    parts: { label: string; count: number }[];
    by_year: { year: string; count: number; cost: number }[];
    by_machine: { machine_id: string; name: string; count: number; cost: number }[];
};

type Filters = { machine?: string; site?: string; category?: string };

export default function Dashboard({
    filters,
    stats,
    recent,
    machines,
    sites,
    categories,
}: {
    filters: Filters;
    stats: Stats;
    recent: Case[];
    machines: Machine[];
    sites: string[];
    categories: string[];
}) {
    const apply = (patch: Filters) => router.get('/', compact({ ...filters, ...patch }), { preserveState: true, preserveScroll: true, replace: true });
    const selected = machines.find((m) => m.id === filters.machine);
    const [costTable, setCostTable] = useState(false);

    return (
        <AppLayout
            title="履歴ダッシュボード"
            actions={
                <>
                    <Link href={`/cases/create${filters.machine ? `?machine=${filters.machine}` : ''}`} className="btn btn-primary">
                        ＋ 症状を登録
                    </Link>
                    <Link href={`/consult${filters.machine ? `?machine=${filters.machine}` : ''}`} className="btn">
                        🤖 AIに相談
                    </Link>
                </>
            }
        >
            {/* 絞り込みは1行に */}
            <div className="card mb-5 grid gap-3 p-3 sm:grid-cols-[2fr_1fr_1fr_auto]">
                <MachineSelect machines={machines} value={filters.machine} onChange={(v) => apply({ machine: v })} allowEmpty />
                <select className="input" value={filters.site ?? ''} onChange={(e) => apply({ site: e.target.value })} aria-label="拠点">
                    <option value="">すべての拠点</option>
                    {sites.map((s) => (
                        <option key={s}>{s}</option>
                    ))}
                </select>
                <select className="input" value={filters.category ?? ''} onChange={(e) => apply({ category: e.target.value })} aria-label="カテゴリ">
                    <option value="">すべてのカテゴリ</option>
                    {categories.map((s) => (
                        <option key={s}>{s}</option>
                    ))}
                </select>
                {(filters.machine || filters.site || filters.category) && (
                    <button className="btn" onClick={() => router.get('/')}>
                        解除
                    </button>
                )}
            </div>

            {selected && (
                <div className="card mb-5 flex flex-wrap items-center gap-x-6 gap-y-2 p-4 text-sm">
                    <div>
                        <div className="text-lg font-bold">
                            {selected.model} <span className="font-normal text-muted">{selected.id}</span>
                        </div>
                        <div className="text-muted">{[selected.maker, selected.site, selected.label].filter(Boolean).join(' / ')}</div>
                    </div>
                    {selected.manuals.map((m) => (
                        <a key={m.url} href={m.url} target="_blank" rel="noreferrer" className="text-accent-ink hover:underline">
                            📘 {m.title}
                        </a>
                    ))}
                </div>
            )}

            <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="対応件数" value={`${num(stats.summary.count)}件`} sub={`${stats.summary.machines}機種`} />
                <Stat label="累計費用" value={yen(stats.summary.total_cost)} />
                <Stat label="累計停止日数" value={`${num(stats.summary.total_days)}日`} />
                <Stat label="最新の対応日" value={stats.summary.last_date ?? '—'} />
            </div>

            <div className="mb-5 grid gap-4 lg:grid-cols-2">
                <Panel
                    title="年別の費用"
                    action={
                        <button className="text-xs text-accent-ink hover:underline" onClick={() => setCostTable(!costTable)}>
                            {costTable ? 'グラフで表示' : '表で表示'}
                        </button>
                    }
                >
                    {costTable ? (
                        <table className="w-full text-sm">
                            <thead className="text-xs text-muted">
                                <tr>
                                    <th className="py-1 text-left font-medium">年</th>
                                    <th className="py-1 text-right font-medium">件数</th>
                                    <th className="py-1 text-right font-medium">費用</th>
                                </tr>
                            </thead>
                            <tbody className="tabular-nums">
                                {stats.by_year.map((y) => (
                                    <tr key={y.year} className="border-t border-line">
                                        <td className="py-1">{y.year}</td>
                                        <td className="py-1 text-right">{y.count}</td>
                                        <td className="py-1 text-right">{yen(y.cost)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <ColumnChart data={stats.by_year.map((y) => ({ label: y.year, value: y.cost, sub: `${y.count}件` }))} format={yen} />
                    )}
                </Panel>
                <Panel title={filters.machine ? '対応件数（この機種）' : 'トラブルの多い機種'}>
                    <BarList items={stats.by_machine.map((m) => ({ label: m.name, count: m.count }))} />
                </Panel>
                <Panel title="エラーコードの頻度">
                    <BarList items={stats.codes} empty="エラーコードの記録がありません" />
                </Panel>
                <Panel title="交換部品の頻度">
                    <BarList items={stats.parts} empty="部品交換の記録がありません" />
                </Panel>
            </div>

            <div className="mb-3 flex items-center justify-between">
                <h2 className="font-bold">最近の対応履歴</h2>
                <Link href={`/cases${filters.machine ? `?machine=${filters.machine}` : ''}`} className="text-sm text-accent-ink hover:underline">
                    すべて見る →
                </Link>
            </div>
            <div className="space-y-3">
                {recent.length === 0 && <p className="card p-6 text-center text-sm text-muted">対応履歴がありません</p>}
                {recent.map((c) => (
                    <CaseCard key={c.id} c={c} />
                ))}
            </div>
        </AppLayout>
    );
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="card p-4">
            <div className="text-xs text-muted">{label}</div>
            <div className="mt-1 text-xl font-bold tabular-nums">{value}</div>
            {sub && <div className="text-xs text-muted">{sub}</div>}
        </div>
    );
}

function Panel({ title, action, children }: { title: string; action?: React.ReactNode; children: React.ReactNode }) {
    return (
        <section className="card p-4">
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-sm font-bold">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}
