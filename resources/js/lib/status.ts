/** 対応状況（旧GAS版のコード値をそのまま保存し、表示だけ日本語にする） */
export const STATUS_LABELS: Record<string, string> = {
    repaired: '修理完了',
    pending: '対応中',
    free: '無償対応',
    quote_only: '見積のみ',
    unknown: '未設定',
};

export const statusLabel = (s: string | null | undefined) => (s ? (STATUS_LABELS[s] ?? s) : '');
