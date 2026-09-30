export type Admin = {
    id: string;
    name: string;
    email: string;
    role: string;
    role_label: string;
    abilities: string[];
};

export type SharedProps = {
    auth: { admin: Admin | null };
    flash: { success?: string | null; error?: string | null };
    env: string;
    errors: Record<string, string>;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type PlanOption = { id: string; label: string; plan_key: string };

export type PlanMixRow = { key: string; name: string; companies: number; trialing: number; mrr_minor: number };

export type Kpis = {
    companies: number;
    active: number;
    suspended: number;
    new_30d: number;
    trialing: number;
    paying: number;
    past_due: number;
    mrr_minor: number;
    users: number;
};

export type Tone = 'good' | 'warn' | 'bad' | 'info' | 'brand' | 'grey';
