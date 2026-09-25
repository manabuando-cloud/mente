import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import CaseCard from '@/Components/CaseCard';
import type { Case, SharedProps } from '@/types';
import { dateTime, yen } from '@/lib/format';
import { statusLabel } from '@/lib/status';

export default function CaseShow({ case: c, similar }: { case: Case; similar: Case[] }) {
    const { auth } = usePage<SharedProps>().props;

    return (
        <AppLayout
            title="対応履歴"
            actions={
                <>
                    <Link href={`/cases/${c.id}/edit`} className="btn">
                        ✎ 編集
                    </Link>
                    <Link href={`/consult?machine=${c.machine_id}&symptom=${encodeURIComponent(c.symptom)}`} className="btn">
                        🤖 AIに相談
                    </Link>
                    {auth.user?.is_admin && (
                        <button
                            className="btn btn-danger"
                            onClick={() => confirm('この対応履歴を削除しますか？（元に戻せません）') && router.delete(`/cases/${c.id}`)}
                        >
                            削除
                        </button>
                    )}
                </>
            }
        >
            <div className="grid gap-5 lg:grid-cols-[2fr_1fr]">
                <div className="space-y-5">
                    <CaseCard c={c} />
                    {c.parts.length > 0 && (
                        <section className="card overflow-x-auto p-4">
                            <h2 className="mb-2 text-xs font-semibold text-muted">交換部品</h2>
                            <table className="w-full text-sm">
                                <thead className="text-xs text-muted">
                                    <tr>
                                        <th className="py-1 text-left font-medium">部品名</th>
                                        <th className="py-1 text-left font-medium">品番</th>
                                        <th className="py-1 text-right font-medium">数量</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {c.parts.map((p, i) => (
                                        <tr key={i} className="border-t border-line">
                                            <td className="py-1.5 pr-3">{p.n}</td>
                                            <td className="py-1.5 pr-3 font-mono text-xs text-ink-2">{p.id ?? '—'}</td>
                                            <td className="py-1.5 text-right tabular-nums">{p.q ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>
                    )}
                    {c.note && (
                        <section className="card p-4">
                            <h2 className="mb-1 text-xs font-semibold text-muted">備考</h2>
                            <p className="text-sm whitespace-pre-wrap">{c.note}</p>
                        </section>
                    )}
                    <section>
                        <h2 className="mb-3 font-bold">似ている過去の事例</h2>
                        <div className="space-y-3">
                            {similar.length === 0 && <p className="card p-6 text-center text-sm text-muted">見つかりませんでした</p>}
                            {similar.map((s) => (
                                <CaseCard key={s.id} c={s} compact />
                            ))}
                        </div>
                    </section>
                </div>
                <aside className="card h-fit p-4 text-sm">
                    <dl className="space-y-2">
                        <Info label="機種" value={c.machine ? `${c.machine.model} ${c.machine.id}` : c.machine_id} />
                        <Info label="メーカー" value={c.machine?.maker} />
                        <Info label="拠点" value={c.machine?.site} />
                        <Info label="対応日" value={c.date} />
                        <Info label="担当者" value={c.engineer} />
                        <Info label="費用" value={c.cost != null ? yen(c.cost) : null} />
                        <Info label="停止日数" value={c.days != null ? `${c.days}日` : null} />
                        <Info label="状況" value={statusLabel(c.status)} />
                        <Info label="報告書番号" value={c.report_no} />
                        <Info label="見積書番号" value={c.quote_no} />
                        <Info label="登録者" value={c.submitted_by} />
                        <Info label="登録日時" value={dateTime(c.created_at)} />
                        <Info label="更新日時" value={dateTime(c.updated_at)} />
                        <Info label="ID" value={c.id} />
                    </dl>
                    {c.machine?.manuals?.length ? (
                        <div className="mt-4 border-t border-line pt-3">
                            {c.machine.manuals.map((m) => (
                                <a key={m.url} href={m.url} target="_blank" rel="noreferrer" className="block py-0.5 text-accent-ink hover:underline">
                                    📘 {m.title}
                                </a>
                            ))}
                        </div>
                    ) : null}
                </aside>
            </div>
        </AppLayout>
    );
}

function Info({ label, value }: { label: string; value: string | null | undefined }) {
    if (!value) return null;
    return (
        <div className="grid grid-cols-[6rem_1fr] gap-2">
            <dt className="text-muted">{label}</dt>
            <dd className="break-all">{value}</dd>
        </div>
    );
}
