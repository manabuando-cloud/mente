import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { SharedProps } from '@/types';

type Props = { mode: 'google' | 'open'; devLogin: boolean; domain: string | null; adminPasscodeEnabled: boolean };

export default function Login({ mode, devLogin, domain, adminPasscodeEnabled }: Props) {
    const { flash } = usePage<SharedProps>().props;

    return (
        <div className="grid min-h-screen place-items-center px-4">
            <Head title="ログイン" />
            <div className="card w-full max-w-sm p-8 text-center">
                <div className="mx-auto mb-4 grid size-12 place-items-center rounded-xl bg-accent text-2xl text-white">🔧</div>
                <h1 className="text-lg font-bold">設備トラブルナビ</h1>
                <p className="mt-1 text-sm text-muted">設備トラブルの対応履歴を検索・登録し、AIで一次診断します</p>

                {flash.error && <p className="mt-4 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{flash.error}</p>}

                {mode === 'open' ? (
                    <OpenLogin adminPasscodeEnabled={adminPasscodeEnabled} />
                ) : (
                    <>
                        <a href="/auth/google" className="btn btn-primary mt-6 w-full py-2.5">
                            Googleアカウントでログイン
                        </a>
                        {domain && <p className="mt-2 text-xs text-muted">@{domain} のアカウントのみ利用できます</p>}
                    </>
                )}

                {devLogin && <DevLogin />}
            </div>
        </div>
    );
}

/** URLを知っている人なら誰でも使えるモード: 名前だけ入力（管理者はパスコードも） */
function OpenLogin({ adminPasscodeEnabled }: { adminPasscodeEnabled: boolean }) {
    const form = useForm({ name: '', passcode: '' });
    const [showPasscode, setShowPasscode] = useState(false);

    return (
        <form
            className="mt-6 space-y-3 text-left"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/auth/open');
            }}
        >
            <div>
                <label htmlFor="name" className="label">
                    お名前
                </label>
                <input
                    id="name"
                    className="input"
                    autoFocus
                    autoComplete="name"
                    placeholder="例: 安藤"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                />
                {form.errors.name ? <p className="mt-1 text-xs text-bad">{form.errors.name}</p> : <p className="mt-1 text-xs text-muted">登録者・評価の記録に使います</p>}
            </div>
            {adminPasscodeEnabled &&
                (showPasscode ? (
                    <div>
                        <label htmlFor="passcode" className="label">
                            管理者パスコード
                        </label>
                        <input id="passcode" type="password" className="input" autoComplete="current-password" value={form.data.passcode} onChange={(e) => form.setData('passcode', e.target.value)} />
                        {form.errors.passcode && <p className="mt-1 text-xs text-bad">{form.errors.passcode}</p>}
                    </div>
                ) : (
                    <button type="button" className="text-xs text-accent-ink hover:underline" onClick={() => setShowPasscode(true)}>
                        管理者としてログインする
                    </button>
                ))}
            <button className="btn btn-primary w-full py-2.5" disabled={form.processing}>
                はじめる
            </button>
        </form>
    );
}

function DevLogin() {
    const form = useForm({ email: '' });

    return (
        <form
            className="mt-6 border-t border-line pt-4 text-left"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/auth/dev');
            }}
        >
            <label className="label">開発用ログイン（local環境のみ）</label>
            <div className="flex gap-2">
                <input className="input" placeholder="dev@example.com" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                <button className="btn shrink-0" disabled={form.processing}>
                    入る
                </button>
            </div>
        </form>
    );
}
