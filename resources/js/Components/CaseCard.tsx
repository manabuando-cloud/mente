import { Link, router, usePage } from '@inertiajs/react';
import type { Case, SharedProps } from '@/types';
import { yen } from '@/lib/format';
import { statusLabel } from '@/lib/status';

/** 対応履歴カード（検索結果・ダッシュボード・類似事例で共通） */
export default function CaseCard({ c, compact = false }: { c: Case; compact?: boolean }) {
    const { auth } = usePage<SharedProps>().props;

    const rate = (v: number) =>
        router.post(`/cases/${c.id}/rating`, { value: c.rating.mine === v ? 0 : v }, { preserveScroll: true, preserveState: true });

    return (
        <article className="card p-4">
            <header className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                <span className="font-semibold text-ink-2 tabular-nums">{c.date ?? '日付不明'}</span>
                <span>
                    <span className="font-semibold text-ink">{c.machine?.model ?? c.machine_id}</span> {c.machine_id}
                </span>
                {c.machine?.site && <span className="chip">{c.machine.site}</span>}
                {c.status && <span className="chip">{statusLabel(c.status)}</span>}
                {c.review_status !== 'published' && <span className="chip bg-warn-soft text-warn">確認待ち</span>}
                {c.similarity != null && <span className="ml-auto">類似度 {Math.round(Math.min(c.similarity, 1) * 100)}%</span>}
            </header>

            <Link href={`/cases/${c.id}`} className="block">
                <h3 className="font-semibold leading-snug text-ink hover:text-accent-ink">{c.symptom}</h3>
            </Link>

            {!compact && (
                <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    {c.cause && <Row label="原因" value={c.cause} />}
                    {c.action && <Row label="対処" value={c.action} />}
                </dl>
            )}

            {(c.codes.length > 0 || c.parts.length > 0) && (
                <div className="mt-3 flex flex-wrap gap-1.5">
                    {c.codes.map((x) => (
                        <span key={`c-${x}`} className="chip font-mono">
                            ⚠ {x}
                        </span>
                    ))}
                    {c.parts.map((p, i) => (
                        <span key={`p-${i}`} className="chip" title={p.id ? `品番 ${p.id}` : undefined}>
                            🔩 {p.n}
                            {p.q != null && p.q !== 1 && <span className="ml-1 text-muted">×{p.q}</span>}
                        </span>
                    ))}
                </div>
            )}

            {!compact && c.photos.length > 0 && (
                <div className="mt-3 flex gap-2 overflow-x-auto">
                    {c.photos.map((p) => (
                        <a key={p.id} href={p.url} target="_blank" rel="noreferrer">
                            <img src={p.url} alt="" className="size-20 rounded-md border border-line object-cover" loading="lazy" />
                        </a>
                    ))}
                </div>
            )}

            <footer className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted">
                {c.cost != null && <span>費用 {yen(c.cost)}</span>}
                {c.days != null && <span>停止 {c.days}日</span>}
                {c.engineer && <span>担当 {c.engineer}</span>}
                {c.report_url && (
                    <a href={c.report_url} target="_blank" rel="noreferrer" className="font-medium text-accent-ink hover:underline">
                        📄 報告書PDFを開く
                    </a>
                )}
                {c.quote_url && (
                    <a href={c.quote_url} target="_blank" rel="noreferrer" className="font-medium text-accent-ink hover:underline">
                        🧾 見積書PDFを開く
                    </a>
                )}
                {auth.user && c.review_status === 'published' && (
                    <span className="ml-auto flex gap-1">
                        <RateButton active={c.rating.mine === 1} onClick={() => rate(1)} label="役に立った">
                            👍 {c.rating.up}
                        </RateButton>
                        <RateButton active={c.rating.mine === -1} onClick={() => rate(-1)} label="役に立たなかった">
                            👎 {c.rating.down}
                        </RateButton>
                    </span>
                )}
            </footer>
        </article>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs font-semibold text-muted">{label}</dt>
            <dd className="whitespace-pre-wrap text-ink-2">{value}</dd>
        </div>
    );
}

function RateButton({ active, onClick, label, children }: { active: boolean; onClick: () => void; label: string; children: React.ReactNode }) {
    return (
        <button
            type="button"
            title={label}
            aria-pressed={active}
            onClick={onClick}
            className={`rounded-md border px-2 py-0.5 tabular-nums ${active ? 'border-accent bg-accent-soft text-accent-ink' : 'border-line hover:bg-surface-2'}`}
        >
            {children}
        </button>
    );
}
