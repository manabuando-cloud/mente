import { Link, router, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import MachineSelect from '@/Components/MachineSelect';
import type { Machine } from '@/types';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime, yen } from '@/lib/format';

type TaskState = { status: 'queued' | 'running' | 'done'; at: string; output?: string } | null;
type Ambiguous = { case_id: string; machine_id: string; date: string | null; symptom: string; candidates: { name: string; url: string }[] };
type LinkResult = { linked: number; ambiguous: Ambiguous[]; no_folder?: number; not_found?: number; ran_at: string } | null;

const TASK_LABELS: Record<string, { title: string; desc: string }> = {
    'sync-machines': { title: '機種マスタの補完', desc: '拠点フォルダ内の機械フォルダ名（機械番号_型式）から、機種マスタの型式・拠点・フォルダを補完します' },
    'ingest-vendor': {
        title: '業者別フォルダの取込み',
        desc: '「メーカー作業報告書見積り」の報告書をAIで読み取り「取込レビュー」に追加し、見積書は同じ日の履歴に紐づけます（毎日 2:40 に自動実行）',
    },
    ingest: { title: '作業報告書PDFの自動取込み', desc: '未処理のActivityReport PDFをAIで読み取り「取込レビュー」に追加します（毎日 2:10 に自動実行）' },
    'link-reports': { title: '報告書PDFの自動リンク', desc: 'ファイル名先頭の日付と機種で、報告書URL未設定の対応履歴にPDFを紐づけます（毎日 3:10 に自動実行）' },
    'link-quotes': { title: '見積書PDFの自動リンク', desc: '見積書番号（quote_no）を手がかりに「見積」フォルダ等からPDFを探して紐づけます' },
    'suggest-quotes': {
        title: '見積書番号の逆入力（候補づくり）',
        desc: 'どの履歴にも紐づいていない見積PDFについて、日付・金額・部品の近い対応履歴を候補に挙げます。確定は下の一覧で行います',
    },
};

type QuoteSuggestion = {
    file_id: string;
    name: string;
    url: string;
    quote_no: string;
    machine_id: string;
    machine_name: string;
    date: string | null;
    date_source: 'pdf' | 'drive' | null;
    amount: number | null;
    subject: string | null;
    candidates: { case_id: string; date: string; symptom: string; cost: number | null; day_diff: number; cost_match: boolean; parts_hit: number; score: number }[];
};
type VendorFolder = { id: string; name: string; mode: 'filename' | 'fixed' | 'skip'; machine_id: string | null; scanned_at: string | null };
type VendorResult = {
    created: number;
    quotes_linked: number;
    quotes_waiting: number;
    skipped: number;
    errors: number;
    quota_exhausted: boolean;
    unresolved: { name: string; url: string; folder: string; folder_id: string | null }[];
    ran_at: string;
} | null;
type SuggestResult = { suggestions: QuoteSuggestion[]; unmatched: number; ai_used: number; quota_exhausted: boolean; ran_at: string } | null;

export default function DriveIndex({
    tasks,
    reports,
    quotes,
    quoteSuggestions,
    vendor,
    vendorFolders,
    machines,
    stats,
    configured,
}: {
    tasks: { key: string; command: string; state: TaskState }[];
    reports: LinkResult;
    quotes: LinkResult;
    quoteSuggestions: SuggestResult;
    vendor: VendorResult;
    vendorFolders: VendorFolder[];
    machines: Machine[];
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

            <div className="mb-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
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

            <VendorSection folders={vendorFolders} result={vendor} machines={machines} />
            <AmbiguousList kind="reports" title="報告書：自動リンクできなかった履歴" result={reports} />
            <AmbiguousList kind="quotes" title="見積書：候補が複数あった履歴" result={quotes} />
            <QuoteSuggestions result={quoteSuggestions} />
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

const MODE_LABELS = { filename: 'ファイル名から判定', fixed: '1台専用（機種を固定）', skip: '対象外' } as const;

function VendorSection({ folders, result, machines }: { folders: VendorFolder[]; result: VendorResult; machines: Machine[] }) {
    return (
        <section className="mb-6">
            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-bold">業者別フォルダの対応表</h2>
                <button className="text-xs text-accent-ink hover:underline" onClick={() => router.post('/drive/vendor-folders/sync', {}, { preserveScroll: true })}>
                    Driveからフォルダ一覧を読み直す
                </button>
            </div>
            <p className="mb-3 text-xs text-muted">
                トルンプのようにファイル名に「#機械番号」が入っている報告書は自動で機械を判定します。1台専用のフォルダ（例: salvagnini_L3-30）は機種を固定してください。社内点検・カレンダーなど取り込まないフォルダは「対象外」にします。
            </p>
            {result && (
                <p className="mb-3 text-xs text-muted">
                    前回 {dateTime(result.ran_at)}：確認待ちに追加 {result.created}件 / 見積の紐づけ {result.quotes_linked}件（保留 {result.quotes_waiting}件） / 対象外 {result.skipped}件 / 機械を特定できない {result.unresolved.length}件
                    {result.quota_exhausted && ' / ⚠ AIクォータ超過で中断'}
                </p>
            )}
            {folders.length === 0 ? (
                <p className="card p-6 text-center text-sm text-muted">まだ読み込んでいません。「Driveからフォルダ一覧を読み直す」を押してください</p>
            ) : (
                <div className="card divide-y divide-line">
                    {folders.map((f) => (
                        <VendorFolderRow key={f.id} folder={f} machines={machines} unresolved={result?.unresolved.filter((u) => u.folder_id === f.id).length ?? 0} />
                    ))}
                </div>
            )}
            {result && result.unresolved.length > 0 && (
                <details className="mt-3">
                    <summary className="cursor-pointer text-sm font-medium text-ink-2">機械を特定できなかったファイル（{result.unresolved.length}件）</summary>
                    <ul className="card mt-2 max-h-80 divide-y divide-line overflow-auto text-sm">
                        {result.unresolved.map((u) => (
                            <li key={u.url} className="flex flex-wrap items-center gap-x-3 px-3 py-1.5">
                                <span className="w-56 shrink-0 truncate text-xs text-muted">{u.folder}</span>
                                <a href={u.url} target="_blank" rel="noreferrer" className="min-w-0 flex-1 truncate text-accent-ink hover:underline">
                                    📄 {u.name}
                                </a>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}

function VendorFolderRow({ folder, machines, unresolved }: { folder: VendorFolder; machines: Machine[]; unresolved: number }) {
    const [mode, setMode] = useState(folder.mode);
    const [machineId, setMachineId] = useState(folder.machine_id ?? '');
    const dirty = mode !== folder.mode || (mode === 'fixed' && machineId !== (folder.machine_id ?? ''));

    return (
        <div className="grid items-center gap-2 px-3 py-2 text-sm md:grid-cols-[1fr_12rem_16rem_auto]">
            <div className="min-w-0">
                <a href={`https://drive.google.com/drive/folders/${folder.id}`} target="_blank" rel="noreferrer" className="block truncate hover:text-accent-ink">
                    📁 {folder.name}
                </a>
                {unresolved > 0 && <span className="text-[11px] text-warn">機械を特定できないファイル {unresolved}件</span>}
            </div>
            <select className="input py-1.5" value={mode} onChange={(e) => setMode(e.target.value as VendorFolder['mode'])} aria-label="判定方法">
                {Object.entries(MODE_LABELS).map(([v, l]) => (
                    <option key={v} value={v}>
                        {l}
                    </option>
                ))}
            </select>
            <div>{mode === 'fixed' ? <MachineSelect machines={machines} value={machineId} onChange={setMachineId} /> : <span className="text-xs text-muted">—</span>}</div>
            <button
                className="btn px-3 py-1.5 text-xs"
                disabled={!dirty || (mode === 'fixed' && !machineId)}
                onClick={() => router.put(`/drive/vendor-folders/${folder.id}`, { mode, machine_id: machineId || null }, { preserveScroll: true })}
            >
                保存
            </button>
        </div>
    );
}

function QuoteSuggestions({ result }: { result: SuggestResult }) {
    const assign = (file_id: string, case_id: string) => router.post('/drive/quote-assign', { file_id, case_id }, { preserveScroll: true });
    const dismiss = (file_id: string) => router.post('/drive/quote-assign', { file_id, dismiss: true }, { preserveScroll: true });

    return (
        <section className="mb-6">
            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-bold">見積書番号の逆入力：候補の確認</h2>
                {result && (
                    <span className="text-xs text-muted">
                        前回 {dateTime(result.ran_at)}：候補あり {result.suggestions.length}件 / 候補なし {result.unmatched}件
                        {result.ai_used > 0 && ` / AIで読んだ見積 ${result.ai_used}件`}
                        {result.quota_exhausted && ' / ⚠ AIクォータ超過で一部はDriveの日付のみ'}
                    </span>
                )}
            </div>
            <p className="mb-3 text-xs text-muted">
                見積の日付（PDFの発行日、読めなければDriveの作成日時）と対応日の近さ、金額と費用の一致、品目と交換部品の重なりで並べています。正しいものを選ぶと、その履歴に見積書番号と見積書PDFが登録されます。
            </p>
            {!result && <p className="card p-6 text-center text-sm text-muted">まだ実行されていません。上の「見積書番号の逆入力」を実行してください</p>}
            {result && result.suggestions.length === 0 && <p className="card p-6 text-center text-sm text-muted">確認待ちの候補はありません</p>}
            <div className="space-y-2">
                {result?.suggestions.map((q) => (
                    <div key={q.file_id} className="card p-3 text-sm">
                        <div className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <a href={q.url} target="_blank" rel="noreferrer" className="font-medium text-accent-ink hover:underline">
                                🧾 {q.name}
                            </a>
                            <span className="text-xs text-muted">{q.machine_name}</span>
                            <span className="text-xs text-muted tabular-nums">
                                {q.date ?? '日付不明'}
                                {q.date_source === 'drive' && '（Drive作成日）'}
                                {q.amount != null && ` · ${yen(q.amount)}`}
                            </span>
                            <button className="ml-auto text-xs text-muted hover:text-bad" onClick={() => dismiss(q.file_id)}>
                                該当なし
                            </button>
                        </div>
                        {q.subject && <div className="mb-2 text-xs text-ink-2">件名: {q.subject}</div>}
                        <ul className="divide-y divide-line rounded-md border border-line">
                            {q.candidates.map((c) => (
                                <li key={c.case_id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                    <span className="w-24 shrink-0 text-xs text-ink-2 tabular-nums">{c.date}</span>
                                    <Link href={`/cases/${c.case_id}`} className="min-w-0 flex-1 truncate hover:text-accent-ink">
                                        {c.symptom}
                                    </Link>
                                    <span className="flex flex-wrap gap-1 text-[11px]">
                                        <span className="chip">{c.day_diff}日差</span>
                                        {c.cost != null && <span className={`chip ${c.cost_match ? 'bg-good-soft text-good' : ''}`}>{c.cost_match ? '✓ 金額一致' : yen(c.cost)}</span>}
                                        {c.parts_hit > 0 && <span className="chip bg-good-soft text-good">✓ 部品一致</span>}
                                    </span>
                                    <button className="btn px-2.5 py-1 text-xs" onClick={() => assign(q.file_id, c.case_id)}>
                                        この履歴の見積
                                    </button>
                                </li>
                            ))}
                        </ul>
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
