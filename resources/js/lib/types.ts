export type Role = 'admin' | 'staff' | 'teller';

export interface User {
    id: number;
    name: string;
    username: string;
    email: string | null;
    role: Role;
    is_active: boolean;
    created_at?: string;
}

/** What the cheque view prints — the cheque's fields, formatted as the cheque shows them. */
export interface ChequePrintData {
    cheque_number: number;
    cheque_date: string | null;
    payee_name: string | null;
    amount: string;
    /** "₱185,369.86" */
    amount_figures: string;
    /** "One Hundred Eighty-Five Thousand … Pesos and 86/100 Only" */
    amount_in_words: string;
    account_no: string;
    bank_name: string;
    bank_branch: string;
    acic_number: number | null;
    /** A cheque carries no LDDAP number; the slot is here for the reference line. */
    lddap_no: string | null;
}

/**
 * Where a cheque is in its life — one ordered flow:
 *
 *   registered → out_for_signature → (received) → for_acic → approved
 *     ├─ released_to_payee
 *     └─ forwarded_to_teller → accepted_by_teller → deposited
 *
 * `available` sits outside it: a number registered as part of a book, not yet claimed.
 */
export type ChequeStatus =
    | 'available'
    | 'registered'
    | 'out_for_signature'
    | 'received'
    | 'for_acic'
    | 'approved'
    | 'released_to_payee'
    | 'forwarded_to_teller'
    | 'accepted_by_teller'
    | 'forwarded_to_land_bank'
    | 'returned_by_bank'
    | 'completed'
    | 'cancelled'
    | 'voided'
    | 'stale'
    | 'replaced';

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

    status_label?: string;
    /**
     * What the cheque is **now** — the same as `status`, except that one past its validity
     * reads as `stale` whether or not the nightly sweep has run. This is the one the UI obeys.
     */
    effective_status?: ChequeStatus;
    effective_status_label?: string;
    validity_until?: string | null;
    days_left?: number | null;
    /** "12 days left" · "Expires today" · "Stale — 5 days ago". */
    countdown?: string | null;
    is_stale?: boolean;
    is_expiring_soon?: boolean;
    expiring_tag?: string | null;
    stale_at?: string | null;

    // What this viewer may do next, decided server-side.
    can_route?: boolean;
    can_receive?: boolean;
    can_assign?: boolean;
    can_release?: boolean;
    can_rts?: boolean;
    can_cancel?: boolean;
    can_void?: boolean;
    can_replace?: boolean;

    /** Step 2 — out for signature. */
    routing?: {
        forward_to_name: string | null;
        forward_unit_name: string | null;
        forwarded_by?: { id: number; name: string } | null;
        date_forwarded: string | null;
        note: string | null;
    } | null;
    /** Step 3 — signed and back. */
    receipt?: {
        received_by_name: string | null;
        date_received: string | null;
        from_unit_name: string | null;
    } | null;
    /** The reason behind an RTS, a cancel or a void. */
    exception_reason?: string | null;

    /** Branch A. */
    release?: {
        received_by_name: string | null;
        date_received: string | null;
        released_by?: { id: number; name: string; username: string } | null;
        released_at: string | null;
        note: string | null;
    } | null;

    /** Branch B, carried from the ACIC the cheque sits on. */
    acic_teller?: {
        forwarded_at: string | null;
        accepted_by?: { id: number; name: string } | null;
        accepted_at: string | null;
        deposit_date: string | null;
        deposit_bank: string | null;
        deposit_reference: string | null;
        return_reason: string | null;
    } | null;

    replaces?: { id: number; cheque_number: number } | null;
    replaced_by?: { id: number; cheque_number: number } | null;
}

/** Where a signed cheque physically is — the second axis, alongside `ChequeStatus`. */
/** The cheque page's tabs: the two derived views, or any status in the flow. */
export type ChequeTab = 'all' | 'valid' | 'expiring' | ChequeStatus;

/** What the validity banner and the deposit queue are drawn from. */
export interface ChequeValiditySummary {
    expiring_soon: {
        total: number;
        /** Pending signature. */
        assigned: number;
        /** Out with the payee. */
        released: number;
        /** Sitting with a teller. */
        for_deposit: number;
    };
    stale: number;
    /** Cheques awaiting *this* viewer's deposit (all of them, for an admin). */
    for_deposit_mine: number;
    tellers: { id: number; name: string }[];
    bank_name: string;
    validity_days: number;
    alert_days: number;
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
/** The routing, in order: Registered → For Out → Returned for ACIC → Approved | RTS | Canceled. */
export type LddapStatus = 'registered' | 'for_out' | 'returned_for_acic' | 'rts' | 'approved' | 'canceled';

/** One step of a record's routing trail. */
/** One step in a cheque's life, as the status history records it. */
export interface ChequeStatusStep {
    id: number;
    from_status: ChequeStatus | null;
    from_status_label: string | null;
    to_status: ChequeStatus;
    to_status_label: string;
    action: string;
    user?: { id: number; name: string } | null;
    acic_number?: number | null;
    details?: Record<string, unknown> | null;
    note: string | null;
    created_at: string | null;
}

/** One edit of a record through "Edit LDDAP Record": who, when, and each field's before/after. */
export interface LddapEdit {
    id: number;
    user: { id: number; name: string; username: string } | null;
    changes: Record<string, { from: string | number | null; to: string | number | null }>;
    created_at: string | null;
}

export interface LddapRoutingStep {
    id: number;
    action: 'registered' | 'forwarded' | 'received' | 'approved' | 'rts' | 'canceled';
    action_label: string;
    from_status: LddapStatus | null;
    to_status: LddapStatus;
    to_status_label: string;
    user: { id: number; name: string; username: string } | null;
    unit_name: string | null;
    counterparty: string | null;
    /** RTS only: who received the record and when, before it was returned. */
    received_by_name: string | null;
    received_on: string | null;
    /** The step's date: forwarded / received / RTS'd on. */
    acted_on: string | null;
    note: string | null;
    created_at: string;
}

/** One row of the LDDAP table. */
export interface Lddap {
    id: number;
    /** From the LDDAP's own check series — unrelated to any `cheque_number` or `acic_number`. */
    check_no: number | null;
    check_date?: string | null;

    lddap_no: string;

    /** The references the disbursement is drawn against. */
    nca_no?: string | null;
    orb_no?: string | null;
    dv_no?: string | null;
    nature_of_payment?: string | null;
    /** The nature spelled out, in caps, as the forms carry it. */
    nature_of_payment_label?: string | null;
    unit_id?: number | null;
    unit_name?: string | null;

    /** The UACS object code — prints as OBJ CODE on the ACIC. */
    obj_no?: string | null;
    amount: string;

    /** The payee and account as they stood when the record was registered. */
    payee_id?: number | null;
    payee_name?: string | null;
    payee_account_id?: number | null;
    payee_account_no?: string | null;
    payee_bank?: string | null;
    /** The ACIC number written on the form at registration (distinct from `acic_number`). */
    acic_ref?: string | null;

    /** The payment breakdown. `amount` is the net payable: gross less all of these. */
    gross_amount?: string;
    wtax?: Record<string, string>;
    vat?: Record<string, string>;
    retention?: string;
    liquidated_damages?: string;
    advance_payment?: string;

    fwd_to_lbp_at?: string | null;
    date_loaded?: string | null;
    note?: string | null;
    remarks?: string | null;
    status: LddapStatus;

    used_by?: { id: number; name: string; username: string } | null;
    used_at?: string | null;
    received_by?: { id: number; name: string; username: string } | null;
    received_at?: string | null;
    is_received?: boolean;
    reviewed_by?: { id: number; name: string; username: string } | null;
    reviewed_at?: string | null;
    review_note?: string | null;
    is_final?: boolean;
    status_label?: string;
    /** Which single next step the status allows. */
    /** "Edit LDDAP Record" is offered: Registered or RTS only. */
    can_edit?: boolean;
    can_forward?: boolean;
    can_receive?: boolean;
    awaits_action?: boolean;

    /** The most recent forward and return. */
    forward_to?: string | null;
    forward_unit_name?: string | null;
    forwarded_by?: { id: number; name: string; username: string } | null;
    date_forwarded?: string | null;
    return_unit_name?: string | null;
    returned_by?: { id: number; name: string; username: string } | null;
    date_returned?: string | null;

    /** The cancellation, when there is one. */
    canceled_by?: { id: number; name: string; username: string } | null;
    date_canceled?: string | null;
    cancel_reason?: string | null;

    /** ACIC No. and Forward To / Date. */
    acic_id?: number | null;
    acic_number?: number | null;
    acic_status?: AcicStatus | null;
    forwarded_at?: string | null;
    forwarded_to?: string | null;

    /** True while a staff correction is awaiting admin approval — the record is on hold. */
    has_pending_update?: boolean;
    /** How many times the record has been returned to sender. */
    rts_count?: number;

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

/** What the "Register LDDAP record" dialog collects. No check number — that comes after routing. */
export interface LddapDraft {
    lddap_no: string;
    nca_no: string;
    orb_no: string;
    dv_no: string;
    nature_of_payment: string;
    /** The UACS object code. */
    obj_no: string;
    unit_id: string;
    check_date: string;
    /** Chosen from the payee lookup; the pick carries its accounts for the account select. */
    payee: Payee | null;
    payee_account_id: number | null;
    acic_ref: string;

    /** The payment breakdown; `amount` (net) is derived server-side from these. */
    gross_amount: string;
    wtax: Record<string, string>;
    vat: Record<string, string>;
    retention: string;
    liquidated_damages: string;
    advance_payment: string;

    fwd_to_lbp_at: string;
    date_loaded: string;
    note: string;
    remarks: string;
}

/** An office unit an LDDAP is drawn for. */
export interface Unit {
    id: number;
    name: string;
}

/** One of a payee's bank accounts, labelled "account number – bank" for a select. */
export interface PayeeAccount {
    id: number;
    account_no: string;
    bank: string;
    label: string;
}

/** A registered payee with the accounts a payment can go to. */
export interface Payee {
    id: number;
    name: string;
    accounts: PayeeAccount[];
}

/** The LDDAP table's filter bar, as sent to `GET /lddaps` (and kept in the page's URL). */
export interface LddapListFilters {
    search?: string;
    /** "all" (or empty) for every status. */
    status?: LddapStatus | 'all' | '';
    /** "all" (or empty) for every nature of payment. */
    nature?: string;
    page?: number;
    perPage?: number;
}

/** What the register dialog's selects offer. */
export interface LddapOptions {
    natures: { value: string; label: string }[];
    units: Unit[];
}

/** Counts for the LDDAP check series. */
export interface LddapSeries {
    registered: number;
    available: number;
    used: number;
}

/** The next check numbers in the LDDAP series; the first is what "Add Check Number" assigns. */
export interface NextCheckNumbers {
    numbers: number[];
    available: number;
}

/** Counts for the ACIC number series. */
export interface AcicSeries {
    registered: number;
    available: number;
    used: number;
}

/**
 * Where an ACIC stands with the tellers and the bank:
 *
 *   pending → accepted_by_teller → forwarded_to_land_bank → completed
 *                                          └─▶ returned_by_bank → lodged again
 */
export type AcicTellerStatus =
    | 'pending'
    | 'accepted_by_teller'
    | 'forwarded_to_land_bank'
    | 'returned_by_bank'
    | 'completed';

/** The teller dashboard's five lists. */
export interface TellerQueue {
    pending: Acic[];
    accepted: Acic[];
    forwarded: Acic[];
    returned: Acic[];
    completed: Acic[];
    bank_name: string;
}

/** One step of an ACIC's teller life. */
export interface AcicHistoryStep {
    id: number;
    from_status: AcicTellerStatus | null;
    from_status_label: string | null;
    to_status: AcicTellerStatus | null;
    to_status_label: string | null;
    action: string;
    user?: { id: number; name: string } | null;
    details?: Record<string, unknown> | null;
    note: string | null;
    created_at: string | null;
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

    /** What the ACIC carries. Fixed by the first record on it. */
    type?: 'cheque' | 'lddap' | null;
    type_label?: string | null;
    /** Where it stands with the tellers and the bank. Null until it is forwarded. */
    teller_status?: AcicTellerStatus | null;
    teller_status_label?: string | null;
    forwarded_to_land_bank_at?: string | null;
    transmittal_no?: string | null;
    land_bank_note?: string | null;
    returned_by_bank_at?: string | null;
    bank_return_reason?: string | null;
    credited_at?: string | null;
    bank_confirmation_no?: string | null;
    confirmed_by?: { id: number; name: string } | null;
    completion_note?: string | null;
    total_records?: number;

    // Branch B — the ACIC as a whole goes to the tellers.
    forwarded_to_teller_at?: string | null;
    forwarded_to_teller_by?: { id: number; name: string } | null;
    forward_note?: string | null;
    /** Null while it is still Pending for every teller; the first to accept claims it. */
    accepted_by?: { id: number; name: string } | null;
    accepted_at?: string | null;
    deposit_date?: string | null;
    deposit_bank?: string | null;
    deposit_reference?: string | null;
    deposit_note?: string | null;
    returned_to_admin_at?: string | null;
    return_reason?: string | null;
}

/** How many cheques sit on each status, plus the total. Keyed by `ChequeStatus`. */
export type Counts = Record<ChequeStatus, number> & { total: number };

export interface Summary {
    counts: Counts;
    next: Cheque | null;
}

/** One thing waiting on the signed-in user, with where to go to deal with it. */
export interface AttentionItem {
    key: string;
    label: string;
    hint: string;
    count: number;
    to: string;
    /** Colours the tile: `accent` (act now), `warn` (returned to you), `brand` (next up). */
    tone: 'accent' | 'warn' | 'brand';
}

/** A number series as the dashboard shows it. */
export interface SeriesGlance {
    available: number;
    next: number | null;
    /** Fewer than DashboardService::LOW_SERIES numbers left. */
    low: boolean;
    registered?: number;
    used?: number;
}

/** `GET /dashboard`: attention items by role, then every register's counts. */
export interface Dashboard {
    attention: AttentionItem[];
    cheques: Summary;
    lddaps: {
        counts: Record<'total' | LddapStatus, number>;
        awaiting_acic: number;
        on_acic: number;
    };
    acics: {
        counts: Record<'total' | AcicStatus, number>;
    };
    series: {
        cheques: SeriesGlance;
        lddap_checks: SeriesGlance;
        acic_numbers: SeriesGlance;
    };
    /** Admin only: the latest audit rows, newest first. Empty for other roles. */
    recent: Pick<ChequeLog, 'id' | 'username' | 'action' | 'cheque_number' | 'description' | 'created_at'>[];
}

export type NotificationKind = 'request' | 'approved' | 'rejected' | 'used' | 'expiring' | 'stale';

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
    /** The first and last row numbers on this page; null when the page is empty. */
    from: number | null;
    to: number | null;
}

export interface Paginated<T> {
    data: T[];
    meta: PageMeta;
}
