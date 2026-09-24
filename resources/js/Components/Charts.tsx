import { useState } from 'react';

/**
 * 単一系列のチャート。色は --series-1 のみ（系列が1つなので凡例は不要、タイトルが系列名）。
 * 値ラベルはバー先端に、テキストは文字色トークンで描く。
 */

export function BarList({ items, unit = '件', empty = 'データがありません' }: { items: { label: string; count: number }[]; unit?: string; empty?: string }) {
    const max = Math.max(1, ...items.map((i) => i.count));
    if (items.length === 0) return <p className="py-6 text-center text-sm text-muted">{empty}</p>;

    return (
        <ul className="space-y-2">
            {items.map((i) => (
                <li key={i.label} className="grid grid-cols-[minmax(0,9rem)_1fr] items-center gap-3 text-sm" title={`${i.label}: ${i.count}${unit}`}>
                    <span className="truncate text-ink-2">{i.label}</span>
                    <span className="flex items-center gap-2">
                        <span className="h-3 rounded-r-[4px] bg-series-1" style={{ width: `${(i.count / max) * 85}%`, minWidth: 3 }} />
                        <span className="text-xs text-ink-2 tabular-nums">{i.count}</span>
                    </span>
                </li>
            ))}
        </ul>
    );
}

export function ColumnChart({
    data,
    format,
    height = 180,
}: {
    data: { label: string; value: number; sub?: string }[];
    format: (n: number) => string;
    height?: number;
}) {
    const [hover, setHover] = useState<number | null>(null);
    if (data.length === 0) return <p className="py-6 text-center text-sm text-muted">データがありません</p>;

    const max = niceMax(Math.max(...data.map((d) => d.value)));
    const ticks = [0, max / 2, max];

    return (
        <div className="relative">
            <div className="flex" style={{ height }}>
                {/* y軸目盛り（控えめ） */}
                <div className="relative w-14 shrink-0 text-right text-[11px] text-muted">
                    {ticks.map((t) => (
                        <span key={t} className="absolute right-2 -translate-y-1/2 tabular-nums" style={{ top: `${100 - (t / max) * 100}%` }}>
                            {compactYen(t)}
                        </span>
                    ))}
                </div>
                <div className="relative flex flex-1 items-end gap-[2px] border-b border-line">
                    {ticks.slice(1).map((t) => (
                        <div key={t} className="pointer-events-none absolute inset-x-0 border-t border-grid" style={{ top: `${100 - (t / max) * 100}%` }} />
                    ))}
                    {data.map((d, i) => (
                        <div
                            key={d.label}
                            className="relative flex h-full flex-1 cursor-default items-end justify-center"
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                            onFocus={() => setHover(i)}
                            onBlur={() => setHover(null)}
                            tabIndex={0}
                            aria-label={`${d.label}: ${format(d.value)}`}
                        >
                            <div
                                className={`w-full max-w-6 rounded-t-[4px] bg-series-1 ${hover !== null && hover !== i ? 'opacity-50' : ''}`}
                                style={{ height: `${(d.value / max) * 100}%`, minHeight: d.value > 0 ? 2 : 0 }}
                            />
                            {hover === i && (
                                <div className="pointer-events-none absolute z-10 mb-1 rounded-md border border-line bg-surface px-2 py-1 text-xs whitespace-nowrap shadow-md" style={{ bottom: `${(d.value / max) * 100}%` }}>
                                    <div className="font-semibold text-ink">{d.label}</div>
                                    <div className="text-ink-2 tabular-nums">{format(d.value)}</div>
                                    {d.sub && <div className="text-muted">{d.sub}</div>}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
            <div className="ml-14 flex gap-[2px] pt-1">
                {data.map((d) => (
                    <span key={d.label} className="flex-1 truncate text-center text-[11px] text-muted tabular-nums">
                        {d.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

function niceMax(v: number) {
    if (v <= 0) return 1;
    const p = 10 ** Math.floor(Math.log10(v));
    const n = v / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
}

function compactYen(n: number) {
    if (n >= 100_000_000) return `${+(n / 100_000_000).toFixed(1)}億`;
    if (n >= 10_000) return `${+(n / 10_000).toFixed(1)}万`;
    return `${n}`;
}
