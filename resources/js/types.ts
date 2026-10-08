export type Machine = {
    id: string;
    model: string;
    maker: string | null;
    label: string | null;
    site: string | null;
    category: string | null;
    manuals: { title: string; url: string }[];
    equipment_no?: string | null;
    name: string;
};

/** 交換部品: n=部品名, id=品番, q=数量 */
export type Part = { n: string; id?: string; q?: number };

export type Case = {
    id: string;
    machine_id: string;
    machine: Machine | null;
    date: string | null;
    engineer: string | null;
    symptom: string;
    report_no: string | null;
    quote_no: string | null;
    cause: string | null;
    action: string | null;
    codes: string[];
    parts: Part[];
    codes_raw: string | null;
    cost: number | null;
    status: string | null;
    note: string | null;
    days: number | null;
    submitted_by: string | null;
    report_url: string | null;
    quote_url: string | null;
    review_status: 'published' | 'pending' | 'approved' | 'rejected';
    source: string;
    source_url: string | null;
    review_note: string | null;
    photos: { id: number; url: string }[];
    rating: { up: number; down: number; mine: number };
    similarity?: number | null;
    created_at: string | null;
    updated_at: string | null;
};

export type SharedProps = {
    auth: { user: { id: number; name: string; email: string; avatar: string | null; is_admin: boolean } | null };
    flash: { success?: string | null; error?: string | null };
    pendingCount: number;
};
