export type Role = 'admin' | 'staff' | 'teller';

export interface User {
    id: number;
    name: string;
    username: string;
    role: Role;
    is_active: boolean;
    created_at?: string;
}

export type ChequeStatus = 'available' | 'used' | 'received';

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
    received_by?: { id: number; name: string; username: string } | null;
    received_by_name?: string | null;
    received_at?: string | null;
    is_received?: boolean;
    has_pending_update?: boolean;
}

export type RequestStatus = 'pending' | 'approved' | 'rejected';

export interface UpdateRequest {
    id: number;
    reason: string;
    status: RequestStatus;
    proposed_payee_name?: string | null;
    proposed_amount?: string | null;
    proposed_cheque_date?: string | null;
    requested_by?: { id: number; name: string; username: string } | null;
    reviewed_by?: { id: number; name: string; username: string } | null;
    reviewed_at?: string | null;
    review_note?: string | null;
    created_at: string;
    cheque?: Cheque | null;
}

export interface ChequeDetails {
    payee_name: string;
    amount: number;
    cheque_date: string;
}

export interface Counts {
    total: number;
    available: number;
    used: number;
    received: number;
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
