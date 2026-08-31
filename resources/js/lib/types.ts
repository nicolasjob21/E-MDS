export type Role = 'admin' | 'staff' | 'teller';

export interface User {
    id: number;
    name: string;
    username: string;
    role: Role;
    is_active: boolean;
    created_at?: string;
}

export type ChequeStatus = 'available' | 'used' | 'received' | 'approved' | 'complies' | 'disapproved';

/** The three outcomes an admin review can produce. */
export type ReviewOutcome = Extract<ChequeStatus, 'approved' | 'complies' | 'disapproved'>;

export interface Cheque {
    id: number;
    cheque_number: number;
    payee_name?: string | null;
    amount?: string | null;
    cheque_date?: string | null;
    acic_id?: number | null;
    acic_number?: number | null;
    acic_status?: AcicStatus | null;
    status: ChequeStatus;
    used_by?: { id: number; name: string; username: string } | null;
    used_by_name?: string | null;
    used_at?: string | null;
    received_by?: { id: number; name: string; username: string } | null;
    received_by_name?: string | null;
    received_at?: string | null;
    is_received?: boolean;
    reviewed_by?: { id: number; name: string; username: string } | null;
    reviewed_by_name?: string | null;
    reviewed_at?: string | null;
    review_note?: string | null;
    is_reviewed?: boolean;
    is_final?: boolean;
    awaits_compliance?: boolean;
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

/** The LDDAP's own lifecycle. It draws on an independent check series, not on the cheque register. */
export type LddapStatus = 'used' | 'received' | 'approved' | 'compliance' | 'cancelled';

/** The three outcomes an admin LDDAP review can produce. */
export type LddapReviewOutcome = Extract<LddapStatus, 'approved' | 'compliance' | 'cancelled'>;

/** One row of the LDDAP table. */
export interface Lddap {
    id: number;
    /** From the LDDAP's own check series — unrelated to any `cheque_number` or `acic_number`. */
    check_no: number | null;
    check_date?: string | null;

    lddap_no: string;
    obj_no?: string | null;
    amount: string;
    payee_name?: string | null;
    status: LddapStatus;

    used_by?: { id: number; name: string; username: string } | null;
    used_at?: string | null;
    received_by?: { id: number; name: string; username: string } | null;
    received_at?: string | null;
    is_received?: boolean;
    reviewed_by?: { id: number; name: string; username: string } | null;
    reviewed_at?: string | null;
    review_note?: string | null;
    is_reviewed?: boolean;
    is_final?: boolean;
    awaits_compliance?: boolean;

    /** ACIC No. and Forward To / Date. */
    acic_id?: number | null;
    acic_number?: number | null;
    acic_status?: AcicStatus | null;
    forwarded_at?: string | null;
    forwarded_to?: string | null;

    /** True while a staff correction is awaiting admin approval — the record is on hold. */
    has_pending_update?: boolean;

    created_at: string;
}

/** A staff-proposed correction to an LDDAP's details, awaiting admin approval. */
export interface LddapUpdateRequest {
    id: number;
    reason: string;
    status: RequestStatus;
    proposed_lddap_no: string;
    proposed_obj_no?: string | null;
    proposed_payee_name?: string | null;
    proposed_amount: string;
    /** True when an admin changed the details directly instead of reviewing a request. */
    applied_directly?: boolean;
    requested_by?: { id: number; name: string; username: string } | null;
    reviewed_by?: { id: number; name: string; username: string } | null;
    reviewed_at?: string | null;
    review_note?: string | null;
    created_at: string;
    lddap?: Lddap | null;
}

/** The correctable fields of an LDDAP, plus the reason for the change. */
export interface ProposedLddapUpdate {
    lddap_no: string;
    obj_no: string;
    payee_name: string;
    amount: number;
    reason: string;
}

/** One row of the "Use Check Number" modal — a document to register against a check number. */
export interface LddapDraft {
    lddap_no: string;
    obj_no: string;
    payee_name: string;
    amount: string;
}

/** Counts for the LDDAP check series. */
export interface LddapSeries {
    registered: number;
    available: number;
    used: number;
}

/** The next check numbers in the LDDAP series, previewed for the "Use Check Number" modal. */
export interface NextCheckNumbers {
    numbers: number[];
    available: number;
    max_batch: number;
}

/** Counts for the ACIC number series. */
export interface AcicSeries {
    registered: number;
    available: number;
    used: number;
}

export type AcicStatus = 'open' | 'used' | 'approved' | 'forwarded' | 'completed';

/** The header block, totals and signatories printed on the ACIC form. */
export interface AcicForm {
    bank_name: string;
    bank_branch: string;
    bank_address: string;
    agency_name: string;
    agency_address: string;
    date_prepared: string | null;
    /** The form's own number format, YY-MM-SEQ. */
    acic_no: string;
    org_code: string;
    funding_source: string;
    area_code: string;
    allocation_no: string;
    account_no: string;
    total_amount: string;
    total_checks: number;
    amount_in_words: string;
    certified_by: string;
    approved_by: string;
    filename: string;
}

export interface Acic {
    id: number;
    acic_number: number;
    status: AcicStatus;
    used_by?: { id: number; name: string; username: string } | null;
    used_at?: string | null;
    created_at: string;
    forwarded_at?: string | null;
    received_by?: { id: number; name: string; username: string } | null;
    /** Typed-in recipient, used when the ACIC was handed to someone who is not a user. */
    received_name?: string | null;
    /** The receiving user's name, or `received_name` — whichever applies. */
    forwarded_to?: string | null;
    completed_at?: string | null;
    completed_by?: { id: number; name: string; username: string } | null;
    created_by?: { id: number; name: string; username: string } | null;
    awaits_teller?: boolean;
    cheque_count?: number;
    lddap_count?: number;
    cheques?: Cheque[];
    lddaps?: Lddap[];
    /** Everything the printed ACIC form needs beyond the record itself. Detail view only. */
    form?: AcicForm;
}

export interface Counts {
    total: number;
    available: number;
    used: number;
    received: number;
    approved: number;
    complies: number;
    disapproved: number;
}

export interface Summary {
    counts: Counts;
    next: Cheque | null;
}

export type NotificationKind = 'request' | 'approved' | 'rejected' | 'used';

export interface AppNotification {
    id: string;
    kind: NotificationKind;
    title: string;
    message: string;
    url?: string | null;
    cheque_number?: number | null;
    read: boolean;
    created_at: string;
}

export interface NotificationFeed {
    data: AppNotification[];
    unread_count: number;
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
