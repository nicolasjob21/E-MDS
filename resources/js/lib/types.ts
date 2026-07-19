export type Role = 'admin' | 'staff';

export interface User {
    id: number;
    name: string;
    username: string;
    role: Role;
    is_active: boolean;
    created_at?: string;
}

export type ChequeStatus = 'available' | 'used';

export interface Cheque {
    id: number;
    cheque_number: number;
    payee_name?: string | null;
    amount?: string | null;
    cheque_date?: string | null;
    status: ChequeStatus;
    used_by?: { id: number; name: string; username: string } | null;
    used_by_name?: string | null;
    used_at?: string | null;
    teller_name?: string | null;
    cashed_at?: string | null;
    is_cashed?: boolean;
}

export interface ChequeDetails {
    payee_name: string;
    amount: number;
    cheque_date: string;
}

export interface EncashmentDetails {
    teller_name: string;
    cashed_at: string;
}

export interface Counts {
    total: number;
    available: number;
    used: number;
}

export interface Summary {
    counts: Counts;
    next: Cheque | null;
}

export interface ChequeLog {
    id: number;
    user_id: number | null;
    username: string;
    cheque_number: number | null;
    action: string;
    description: string | null;
    created_at: string;
}

export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface Paginated<T> {
    data: T[];
    meta: PageMeta;
}
