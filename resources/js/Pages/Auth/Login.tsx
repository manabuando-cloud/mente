import { Head, useForm, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

export default function Login({ devLogin, domain }: { devLogin: boolean; domain: string | null }) {
    const { flash } = usePage<SharedProps>().props;
    const form = useForm({ email: '' });

    return (
        <div className="grid min-h-screen place-items-center px-4">
            <Head title="ログイン" />
            <div className="card w-full max-w-sm p-8 text-center">
                <div className="mx-auto mb-4 grid size-12 place-items-center rounded-xl bg-accent text-2xl text-white">🔧</div>
                <h1 className="text-lg font-bold">設備トラブルナビ</h1>
                <p className="mt-1 text-sm text-muted">設備トラブルの対応履歴を検索・登録し、AIで一次診断します</p>

                {flash.error && <p className="mt-4 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{flash.error}</p>}

                <a href="/auth/google" className="btn btn-primary mt-6 w-full py-2.5">
                    Googleアカウントでログイン
                </a>
                {domain && <p className="mt-2 text-xs text-muted">@{domain} のアカウントのみ利用できます</p>}

                {devLogin && (
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
                )}
            </div>
        </div>
    );
}
