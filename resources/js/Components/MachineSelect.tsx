import { useEffect, useMemo, useRef, useState } from 'react';
import type { Machine } from '@/types';

/** 約250機種から型式・機械番号・メーカー・拠点で絞り込んで選ぶコンボボックス */
export default function MachineSelect({
    machines,
    value,
    onChange,
    placeholder = '機種を選択（型式・機械番号で検索）',
    allowEmpty = false,
    emptyLabel = 'すべての機種',
    id,
}: {
    machines: Machine[];
    value: string | null | undefined;
    onChange: (id: string) => void;
    placeholder?: string;
    allowEmpty?: boolean;
    emptyLabel?: string;
    id?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [cursor, setCursor] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const selected = machines.find((m) => m.id === value);

    const filtered = useMemo(() => {
        const terms = query.toLowerCase().split(/\s+/).filter(Boolean);
        const list = machines.filter((m) => {
            const hay = `${m.model} ${m.id} ${m.maker ?? ''} ${m.site ?? ''} ${m.label ?? ''}`.toLowerCase();
            return terms.every((t) => hay.includes(t));
        });
        return list.slice(0, 80);
    }, [machines, query]);

    useEffect(() => {
        const close = (e: MouseEvent) => !root.current?.contains(e.target as Node) && setOpen(false);
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    const options: (Machine | null)[] = allowEmpty && !query ? [null, ...filtered] : filtered;

    const pick = (m: Machine | null) => {
        onChange(m?.id ?? '');
        setOpen(false);
        setQuery('');
    };

    return (
        <div ref={root} className="relative">
            <input
                id={id}
                className="input pr-8"
                role="combobox"
                aria-expanded={open}
                autoComplete="off"
                value={open ? query : selected ? `${selected.model}  ${selected.id}` : allowEmpty ? '' : ''}
                placeholder={selected ? `${selected.model}  ${selected.id}` : allowEmpty ? emptyLabel : placeholder}
                onFocus={() => {
                    setOpen(true);
                    setCursor(0);
                }}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setCursor(0);
                    setOpen(true);
                }}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setCursor((c) => Math.min(c + 1, options.length - 1));
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setCursor((c) => Math.max(c - 1, 0));
                    } else if (e.key === 'Enter' && open) {
                        e.preventDefault();
                        if (options[cursor] !== undefined) pick(options[cursor]);
                    } else if (e.key === 'Escape') {
                        setOpen(false);
                    }
                }}
            />
            <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted">▾</span>
            {open && (
                <ul role="listbox" className="absolute z-40 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-line bg-surface py-1 shadow-lg">
                    {options.length === 0 && <li className="px-3 py-2 text-sm text-muted">該当する機種がありません</li>}
                    {options.map((m, i) => (
                        <li
                            key={m?.id ?? '__all'}
                            role="option"
                            aria-selected={(m?.id ?? '') === (value ?? '')}
                            onMouseDown={(e) => {
                                e.preventDefault();
                                pick(m);
                            }}
                            onMouseEnter={() => setCursor(i)}
                            className={`cursor-pointer px-3 py-1.5 text-sm ${i === cursor ? 'bg-accent-soft' : ''}`}
                        >
                            {m ? (
                                <div className="flex items-baseline justify-between gap-3">
                                    <span>
                                        <span className="font-medium">{m.model}</span> <span className="text-muted">{m.id}</span>
                                    </span>
                                    <span className="shrink-0 text-xs text-muted">{[m.maker, m.site].filter(Boolean).join(' / ')}</span>
                                </div>
                            ) : (
                                <span className="text-ink-2">{emptyLabel}</span>
                            )}
                        </li>
                    ))}
                    {filtered.length === 80 && <li className="px-3 py-1.5 text-xs text-muted">上位80件を表示中。キーワードで絞り込んでください</li>}
                </ul>
            )}
        </div>
    );
}
