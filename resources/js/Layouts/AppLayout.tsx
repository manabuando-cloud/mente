import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useState } from 'react';
import type { SharedProps } from '@/types';

const NAV = [
    { href: '/', label: 'ダッシュボード', match: (u: string) => u === '/' || u.startsWith('/?') },
    { href: '/cases', label: '症状検索', match: (u: string) => u.startsWith('/cases') && !u.startsWith('/cases/create') },
    { href: '/cases/create', label: '症状登録', match: (u: string) => u.startsWith('/cases/create') },
    { href: '/consult', label: 'AI相談', match: (u: string) => u.startsWith('/consult') },
    { href: '/machines', label: '機械マスター', match: (u: string) => u.startsWith('/machines') },
];

const ADMIN_NAV = [
    { href: '/review', label: '取込レビュー', match: (u: string) => u.startsWith('/review') },
    { href: '/drive', label: 'Drive連携', match: (u: string) => u.startsWith('/drive') },
];

export default function AppLayout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    const { auth, flash, pendingCount } = usePage<SharedProps>().props;
    const url = usePage().url;
    const [menuOpen, setMenuOpen] = useState(false);
    const [toast, setToast] = useState<{ kind: 'success' | 'error'; text: string } | null>(null);

    useEffect(() => {
        if (flash.success) setToast({ kind: 'success', text: flash.success });
        else if (flash.error) setToast({ kind: 'error', text: flash.error });
        else return;
        const t = setTimeout(() => setToast(null), 6000);
        return () => clearTimeout(t);
    }, [flash.success, flash.error]);

    const items = [...NAV, ...(auth.user?.is_admin ? ADMIN_NAV : [])];

    return (
        <div className="min-h-screen">
            <Head title={title} />
            <header className="sticky top-0 z-30 border-b border-line bg-surface/95 backdrop-blur">
                <div className="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4">
                    <Link href="/" className="flex shrink-0 items-center gap-2 font-bold text-ink">
                        <span className="grid size-7 place-items-center rounded-md bg-accent text-sm text-white">🔧</span>
                        <span className="hidden sm:inline">設備トラブルナビ</span>
                    </Link>
                    <nav className="hidden flex-1 items-center gap-1 md:flex">
                        {items.map((n) => (
                            <NavLink key={n.href} {...n} active={n.match(url)} badge={n.href === '/review' ? pendingCount : 0} />
                        ))}
                    </nav>
                    <div className="ml-auto flex items-center gap-2">
                        {auth.user && (
                            <span className="hidden text-xs text-muted lg:inline" title={auth.user.email}>
                                {auth.user.name}
                            </span>
                        )}
                        <button className="btn hidden px-2.5 py-1.5 text-xs md:inline-flex" onClick={() => router.post('/logout')}>
                            ログアウト
                        </button>
                        <button className="btn px-2.5 py-1.5 md:hidden" aria-label="メニュー" onClick={() => setMenuOpen(!menuOpen)}>
                            ☰
                        </button>
                    </div>
                </div>
                {menuOpen && (
                    <nav className="flex flex-col gap-1 border-t border-line px-4 py-2 md:hidden">
                        {items.map((n) => (
                            <NavLink key={n.href} {...n} active={n.match(url)} badge={n.href === '/review' ? pendingCount : 0} />
                        ))}
                        <button className="btn mt-1" onClick={() => router.post('/logout')}>
                            ログアウト
                        </button>
                    </nav>
                )}
            </header>

            <main className="mx-auto max-w-6xl px-4 py-6">
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-bold">{title}</h1>
                    {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                </div>
                {children}
            </main>

            {toast && (
                <div
                    role="status"
                    className={`fixed right-4 bottom-4 left-4 z-50 mx-auto max-w-md rounded-lg border px-4 py-3 text-sm shadow-lg sm:left-auto ${
                        toast.kind === 'success' ? 'border-good/30 bg-good-soft text-good' : 'border-bad/30 bg-bad-soft text-bad'
                    }`}
                    onClick={() => setToast(null)}
                >
                    {toast.kind === 'success' ? '✓ ' : '⚠ '}
                    {toast.text}
                </div>
            )}
        </div>
    );
}

function NavLink({ href, label, active, badge }: { href: string; label: string; active: boolean; badge: number }) {
    return (
        <Link
            href={href}
            className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${
                active ? 'bg-accent-soft text-accent-ink' : 'text-ink-2 hover:bg-surface-2'
            }`}
        >
            {label}
            {badge > 0 && <span className="rounded-full bg-warn px-1.5 text-[11px] leading-4 font-bold text-white">{badge}</span>}
        </Link>
    );
}
