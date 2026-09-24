export const yen = (n: number | null | undefined) => (n == null ? '—' : `¥${n.toLocaleString('ja-JP')}`);

export const num = (n: number | null | undefined) => (n == null ? '—' : n.toLocaleString('ja-JP'));

export const dateTime = (iso: string | null | undefined) =>
    iso ? new Date(iso).toLocaleString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';

/** URLクエリ用に空の値を落とす */
export const compact = <T extends Record<string, unknown>>(o: T) =>
    Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v != null)) as Partial<T>;
