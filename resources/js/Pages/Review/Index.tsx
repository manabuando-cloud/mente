import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import type { Case } from '@/types';

export default function ReviewIndex({ status, cases, counts }: { status: string; cases: Case[]; counts: Record<string, number> }) {
    return (
        <AppLayout title="取込レビュー">
            <p className="mb-4 text-sm text-ink-2">
                Driveの作業報告書PDFからAIが自動抽出した対応履歴です。内容を確認し、必要なら修正してから承認してください。承認すると正式な対応履歴になり、Slackに通知されます。
            </p>
            <div className="mb-4 flex gap-1">
                {[
                    ['pending', '確認待ち'],
                    ['rejected', '却下済み'],
                ].map(([key, label]) => (
                    <Link
                        key={key}
                        href={`/review?status=${key}`}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium ${status === key ? 'bg-accent-soft text-accent-ink' : 'text-ink-2 hover:bg-surface-2'}`}
                    >
                        {label} <span className="tabular-nums">{counts[key] ?? 0}</span>
                    </Link>
                ))}
            </div>
            <div className="space-y-3">
                {cases.length === 0 && <p className="card p-8 text-center text-sm text-muted">対象の対応履歴はありません</p>}
                {cases.map((c) => (
                    <ReviewItem key={c.id} c={c} />
                ))}
            </div>
        </AppLayout>
    );
}

function ReviewItem({ c }: { c: Case }) {
    const [note, setNote] = useState(c.review_note ?? '');
    const decide = (decision: 'approve' | 'reject' | 'reopen') => router.post(`/review/${c.id}`, { decision, review_note: note }, { preserveScroll: true });

    return (
        <article className="card p-4">
            <header className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                <span className="font-semibold text-ink-2 tabular-nums">{c.date ?? '日付不明'}</span>
                <span>
                    <span className="font-semibold text-ink">{c.machine?.model ?? c.machine_id}</span> {c.machine_id}
                </span>
                {c.source_url && (
                    <a href={c.source_url} target="_blank" rel="noreferrer" className="font-medium text-accent-ink hover:underline">
                        📄 元のPDFを開く
                    </a>
                )}
            </header>
            <h3 className="font-semibold">{c.symptom}</h3>
            <dl className="mt-2 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <Item label="原因" value={c.cause} />
                <Item label="対処" value={c.action} />
                <Item label="エラーコード" value={c.codes_raw} />
                <Item label="交換部品" value={c.parts_raw} />
                <Item label="担当者" value={c.engineer} />
                <Item label="報告書番号" value={c.report_no} />
            </dl>
            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                <input className="input min-w-48 flex-1" placeholder="レビューメモ（任意）" value={note} onChange={(e) => setNote(e.target.value)} />
                <Link href={`/cases/${c.id}/edit`} className="btn">
                    ✎ 修正
                </Link>
                {c.review_status === 'pending' ? (
                    <>
                        <button className="btn btn-danger" onClick={() => decide('reject')}>
                            却下
                        </button>
                        <button className="btn btn-primary" onClick={() => decide('approve')}>
                            ✓ 承認
                        </button>
                    </>
                ) : (
                    <button className="btn" onClick={() => decide('reopen')}>
                        確認待ちに戻す
                    </button>
                )}
            </div>
        </article>
    );
}

function Item({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs font-semibold text-muted">{label}</dt>
            <dd className={`whitespace-pre-wrap ${value ? 'text-ink-2' : 'text-muted'}`}>{value || '（空）'}</dd>
        </div>
    );
}
