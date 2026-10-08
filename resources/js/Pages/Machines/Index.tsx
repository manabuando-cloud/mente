import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import Field from '@/Components/Field';
import type { Machine, SharedProps } from '@/types';

type Row = Machine & { cases_count: number; source: string };
type Manual = { title: string; url: string };

export default function MachinesIndex({ filters, machines, sites }: { filters: { q: string }; machines: Row[]; sites: string[] }) {
    const { auth } = usePage<SharedProps>().props;
    const [q, setQ] = useState(filters.q ?? '');
    const [editing, setEditing] = useState<Row | null>(null);

    return (
        <AppLayout title="機械マスター">
            <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
                <div>
                    <form
                        className="mb-3 flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get('/machines', q ? { q } : {}, { preserveState: true, replace: true });
                        }}
                    >
                        <input className="input" type="search" placeholder="型式・機械番号・メーカーで検索" value={q} onChange={(e) => setQ(e.target.value)} />
                        <button className="btn shrink-0">検索</button>
                    </form>
                    <div className="card overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-surface-2 text-xs text-muted">
                                <tr>
                                    <th className="px-3 py-2 text-left font-medium">機種</th>
                                    <th className="px-3 py-2 text-left font-medium">メーカー</th>
                                    <th className="px-3 py-2 text-left font-medium">拠点</th>
                                    <th className="px-3 py-2 text-right font-medium">履歴</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {machines.map((m) => (
                                    <tr key={m.id} className="border-t border-line">
                                        <td className="px-3 py-2">
                                            <div className="font-medium">{m.model}</div>
                                            <div className="text-xs text-muted">
                                                {m.id}
                                                {m.equipment_no && ` · 設備NO ${m.equipment_no}`}
                                                {m.label && ` · ${m.label}`}
                                                {m.manuals.length > 0 && ` · 📘${m.manuals.length}`}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-ink-2">{m.maker}</td>
                                        <td className="px-3 py-2 text-ink-2">{m.site}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            <Link href={`/?machine=${m.id}`} className="text-accent-ink hover:underline">
                                                {m.cases_count}件
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {auth.user?.is_admin && (
                                                <button className="text-xs text-accent-ink hover:underline" onClick={() => setEditing(m)}>
                                                    編集
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {machines.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-3 py-8 text-center text-muted">
                                            該当する機種がありません
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
                <MachineForm key={editing?.id ?? 'new'} machine={editing} sites={sites} onDone={() => setEditing(null)} />
            </div>
        </AppLayout>
    );
}

function MachineForm({ machine, sites, onDone }: { machine: Row | null; sites: string[]; onDone: () => void }) {
    const form = useForm({
        id: machine?.id ?? '',
        model: machine?.model ?? '',
        maker: machine?.maker ?? '',
        label: machine?.label ?? '',
        site: machine?.site ?? '',
        category: machine?.category ?? '',
        manuals: (machine?.manuals ?? []) as Manual[],
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string>;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (machine) {
            form.transform(({ id: _id, ...rest }) => rest as never);
            form.put(`/machines/${machine.id}`, { preserveScroll: true, onSuccess: onDone });
        } else {
            form.post('/machines', { preserveScroll: true, onSuccess: () => form.reset() });
        }
    };

    return (
        <form onSubmit={submit} className="card h-fit space-y-3 p-5 lg:sticky lg:top-20">
            <h2 className="font-bold">{machine ? `${machine.model} を編集` : '機種を登録'}</h2>
            {!machine && (
                <Field label="機械番号 *" error={errors.id} htmlFor="m-id" hint="例: B1508I0077（Driveの機械フォルダ名の先頭と同じ）">
                    <input id="m-id" className="input font-mono" value={data.id} onChange={(e) => setData('id', e.target.value.trim())} required />
                </Field>
            )}
            <Field label="型式 *" error={errors.model} htmlFor="m-model">
                <input id="m-model" className="input" value={data.model} onChange={(e) => setData('model', e.target.value)} required />
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field label="メーカー" error={errors.maker} htmlFor="m-maker">
                    <input id="m-maker" className="input" value={data.maker} onChange={(e) => setData('maker', e.target.value)} />
                </Field>
                <Field label="拠点" error={errors.site} htmlFor="m-site">
                    <input id="m-site" className="input" list="site-list" value={data.site} onChange={(e) => setData('site', e.target.value)} />
                    <datalist id="site-list">
                        {sites.map((s) => (
                            <option key={s} value={s} />
                        ))}
                    </datalist>
                </Field>
                <Field label="カテゴリ" error={errors.category} htmlFor="m-cat">
                    <input id="m-cat" className="input" placeholder="レーザー / ベンダー…" value={data.category} onChange={(e) => setData('category', e.target.value)} />
                </Field>
                <Field label="通称・設置場所" error={errors.label} htmlFor="m-label">
                    <input id="m-label" className="input" value={data.label} onChange={(e) => setData('label', e.target.value)} />
                </Field>
            </div>
            <div>
                <span className="label">取扱説明書</span>
                <div className="space-y-2">
                    {data.manuals.map((m, i) => (
                        <div key={i} className="flex gap-1.5">
                            <input
                                className="input w-28 shrink-0"
                                placeholder="名前"
                                value={m.title}
                                onChange={(e) => setData('manuals', data.manuals.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))}
                            />
                            <input
                                className="input"
                                type="url"
                                placeholder="https://…"
                                value={m.url}
                                onChange={(e) => setData('manuals', data.manuals.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)))}
                            />
                            <button type="button" className="btn px-2" aria-label="削除" onClick={() => setData('manuals', data.manuals.filter((_, j) => j !== i))}>
                                ×
                            </button>
                        </div>
                    ))}
                    {Object.entries(err)
                        .filter(([k]) => k.startsWith('manuals.'))
                        .map(([k, v]) => (
                            <p key={k} className="text-xs text-bad">
                                {v}
                            </p>
                        ))}
                    <button type="button" className="text-xs text-accent-ink hover:underline" onClick={() => setData('manuals', [...data.manuals, { title: '', url: '' }])}>
                        ＋ 取説URLを追加
                    </button>
                </div>
            </div>
            <div className="flex gap-2 pt-1">
                <button className="btn btn-primary flex-1" disabled={form.processing}>
                    {machine ? '更新する' : '登録する'}
                </button>
                {machine && (
                    <button type="button" className="btn" onClick={onDone}>
                        キャンセル
                    </button>
                )}
            </div>
        </form>
    );
}
