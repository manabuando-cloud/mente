import { Link, router, usePoll } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';

type TaskState = { status: 'queued' | 'running' | 'done'; at: string; output?: string } | null;
type Ambiguous = { case_id: string; machine_id: string; date: string | null; symptom: string; candidates: { name: string; url: string }[] };
type LinkResult = { linked: number; ambiguous: Ambiguous[]; no_folder?: number; not_found?: number; ran_at: string } | null;

const TASK_LABELS: Record<string, { title: string; desc: string }> = {
    ingest: { title: '作業報告書PDFの自動取込み', desc: '未処理のActivityReport PDFをAIで読み取り「取込レビュー」に追加します（毎日 2:10 に自動実行）' },
    'link-reports': { title: '報告書PDFの自動リンク', desc: 'ファイル名先頭の日付と機種で、報告書URL未設定の対応履歴にPDFを紐づけます（毎日 3:10 に自動実行）' },
    'link-quotes': { title: '見積書PDFの自動リンク', desc: '見積書番号（quote_no）を手がかりに「見積」フォルダ等からPDFを探して紐づけます' },
};

export default function DriveIndex({
    tasks,
    reports,
    quotes,
    stats,
    configured,
}: {
    tasks: { key: string; command: string; state: TaskState }[];
    reports: LinkResult;
    quotes: LinkResult;
    stats: { without_report: number; without_quote_no: number; processed_files: number; error_files: number };
    configured: { drive: boolean; gemini: boolean; slack: boolean };
}) {
    const busy = tasks.some((t) => t.state && t.state.status !== 'done');
    usePoll(5000, {}, { autoStart: busy });

    return (
        <AppLayout title="Drive連携">
            <div className="mb-5 flex flex-wrap gap-2 text-xs">
                <Badge ok={configured.drive} label="Google Drive（サービスアカウント）" />
                <Badge ok={configured.gemini} label="Gemini API" />
                <Badge ok={configured.slack} label="Slack Webhook" />
            </div>

            <div className="mb-6 grid gap-3 md:grid-cols-3">
                {tasks.map((t) => (
                    <section key={t.key} className="card flex flex-col p-4">
                        <h2 className="text-sm font-bold">{TASK_LABELS[t.key]?.title ?? t.key}</h2>
                        <p className="mt-1 flex-1 text-xs text-muted">{TASK_LABELS[t.key]?.desc}</p>
                        <code className="mt-2 text-[11px] text-muted">php artisan {t.command}</code>
                        {t.state && (
                            <div className="mt-2 text-xs">
                                <span className={t.state.status === 'done' ? 'text-good' : 'text-warn'}>
                                    {t.state.status === 'done' ? '✓ 完了' : t.state.status === 'running' ? '⏳ 実行中' : '⏳ 待機中'}
                                </span>{' '}
                                <span className="text-muted">{dateTime(t.state.at)}</span>
                                {t.state.output && <pre className="mt-1 rounded bg-surface-2 p-2 text-[11px] whitespace-pre-wrap text-ink-2">{t.state.output}</pre>}
                            </div>
                        )}
                        <button
                            className="btn mt-3"
                            disabled={!!t.state && t.state.status !== 'done'}
                            onClick={() => router.post('/drive/run', { task: t.key }, { preserveScroll: true })}
                        >
                            今すぐ実行
                        </button>
                    </section>
                ))}
            </div>

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Mini label="報告書URL未設定の履歴" value={stats.without_report} />
                <Mini label="見積書番号未入力の履歴" value={stats.without_quote_no} />
                <Mini label="取込み済みPDF" value={stats.processed_files} />
                <Mini label="取込みエラーのPDF" value={stats.error_files} hint={stats.error_files ? '--retry-errors で再処理' : undefined} />
            </div>

            <AmbiguousList kind="reports" title="報告書：自動リンクできなかった履歴" result={reports} />
            <AmbiguousList kind="quotes" title="見積書：候補が複数あった履歴" result={quotes} />
        </AppLayout>
    );
}

function AmbiguousList({ kind, title, result }: { kind: 'reports' | 'quotes'; title: string; result: LinkResult }) {
    return (
        <section className="mb-6">
            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-bold">{title}</h2>
                {result && (
                    <span className="text-xs text-muted">
                        前回 {dateTime(result.ran_at)}：リンク {result.linked}件 / 曖昧 {result.ambiguous.length}件
                        {result.no_folder != null && ` / フォルダ未検出 ${result.no_folder}件`}
                        {result.not_found != null && ` / 見つからず ${result.not_found}件`}
                    </span>
                )}
            </div>
            {!result && <p className="card p-6 text-center text-sm text-muted">まだ実行されていません</p>}
            {result && result.ambiguous.length === 0 && <p className="card p-6 text-center text-sm text-muted">曖昧なものはありません</p>}
            <div className="space-y-2">
                {result?.ambiguous.map((a) => (
                    <div key={a.case_id} className="card p-3 text-sm">
                        <div className="mb-1 flex flex-wrap gap-x-3 text-xs text-muted">
                            <span className="tabular-nums">{a.date}</span>
                            <span>{a.machine_id}</span>
                            <Link href={`/cases/${a.case_id}`} className="text-accent-ink hover:underline">
                                履歴を開く
                            </Link>
                        </div>
                        <div className="mb-2">{a.symptom}</div>
                        <div className="flex flex-wrap gap-2">
                            {a.candidates.map((f) => (
                                <span key={f.url} className="inline-flex items-center overflow-hidden rounded-md border border-line text-xs">
                                    <a href={f.url} target="_blank" rel="noreferrer" className="px-2 py-1 hover:bg-surface-2">
                                        📄 {f.name}
                                    </a>
                                    <button
                                        className="border-l border-line bg-accent-soft px-2 py-1 font-medium text-accent-ink"
                                        onClick={() => router.post('/drive/resolve', { kind, case_id: a.case_id, url: f.url }, { preserveScroll: true })}
                                    >
                                        これにする
                                    </button>
                                </span>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function Badge({ ok, label }: { ok: boolean; label: string }) {
    return <span className={`rounded-md px-2 py-1 font-medium ${ok ? 'bg-good-soft text-good' : 'bg-warn-soft text-warn'}`}>{ok ? '✓' : '⚠ 未設定'} {label}</span>;
}

function Mini({ label, value, hint }: { label: string; value: number; hint?: string }) {
    return (
        <div className="card p-3">
            <div className="text-xs text-muted">{label}</div>
            <div className="text-lg font-bold tabular-nums">{value.toLocaleString()}</div>
            {hint && <div className="text-[11px] text-muted">{hint}</div>}
        </div>
    );
}
