import axios, { AxiosError } from 'axios';
import type {
    AppNotification,
    Cheque,
    ChequeDetails,
    ChequePrintData,
    ChequeLog,
    Dashboard,
    Lddap,
    LddapDraft,
    LddapEdit,
    LddapListFilters,
    LddapOptions,
    LddapRoutingStep,
    LddapSeries,
    LddapUpdateRequest,
    ProposedLddapUpdate,
    NextCheckNumbers,
    NotificationFeed,
    Acic,
    AcicSeries,
    Paginated,
    Payee,
    ReviewOutcome,
    Summary,
    UpdateRequest,
    User,
} from './types';

/**
 * Same-origin Sanctum SPA client. The browser holds the session + XSRF cookies;
 * axios echoes the XSRF-TOKEN cookie back as an X-XSRF-TOKEN header automatically.
 */
export const http = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
});

let csrfReady = false;

/** Prime the XSRF-TOKEN cookie before the first state-changing request. */
export async function ensureCsrf(): Promise<void> {
    if (csrfReady) return;
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
    csrfReady = true;
}

/** Extract a human-readable message + field errors from an axios error. */
export interface ApiError {
    message: string;
    errors: Record<string, string[]>;
    status?: number;
}

export function toApiError(err: unknown): ApiError {
    if (err instanceof AxiosError && err.response) {
        const data = err.response.data as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        return {
            message: data?.message ?? 'Something went wrong.',
            errors: data?.errors ?? {},
            status: err.response.status,
        };
    }
    return { message: 'Network error. Please try again.', errors: {} };
}

export const AuthApi = {
    async login(username: string, password: string): Promise<User> {
        await ensureCsrf();
        const { data } = await http.post('/login', { username, password });
        return data.data as User;
    },
    async logout(): Promise<void> {
        await http.post('/logout');
        csrfReady = false;
    },
    async me(): Promise<User> {
        const { data } = await http.get('/me');
        return data.data as User;
    },
    /** Profile: the signed-in user's own full name and email. */
    async updateProfile(payload: { name: string; email: string | null }): Promise<User> {
        await ensureCsrf();
        const { data } = await http.put('/me', payload);
        return data.data as User;
    },
    /** Change Password. The session stays signed in afterwards. */
    async changePassword(payload: {
        current_password: string;
        password: string;
        password_confirmation: string;
    }): Promise<string> {
        await ensureCsrf();
        const { data } = await http.put('/me/password', payload);
        return (data.data as { message: string }).message;
    },
};

export const ChequeApi = {
    async summary(): Promise<Summary> {
        const { data } = await http.get('/cheques/summary');
        return data.data as Summary;
    },
    /** `search` matches the cheque number or the number of the ACIC it sits on. */
    async list(status: string, page = 1, perPage = 50, search = ''): Promise<Paginated<Cheque>> {
        const { data } = await http.get('/cheques', {
            params: { status, page, per_page: perPage, ...(search ? { search } : {}) },
        });
        return data as Paginated<Cheque>;
    },
    async consumeNext(chequeNumber: number, details: ChequeDetails): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post('/cheques/use', {
            cheque_number: chequeNumber,
            ...details,
        });
        return data.data as Cheque;
    },
    /** The cheque view's data. Refused unless the cheque is approved. */
    async printData(chequeId: number): Promise<ChequePrintData> {
        const { data } = await http.get(`/cheques/${chequeId}/print`);
        return data.data as ChequePrintData;
    },
    async confirmReceipt(chequeId: number): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${chequeId}/receive`);
        return data.data as Cheque;
    },
    /** Register a newly issued cheque book by the first and last serial printed on it. */
    async addRange(
        startAt: number,
        endAt: number,
    ): Promise<{ from: number; to: number; count: number; message: string }> {
        await ensureCsrf();
        const { data } = await http.post('/cheques/add-range', {
            start_at: startAt,
            end_at: endAt,
        });
        return data.data;
    },
    /** Admin only: record the review outcome (approved | complies | disapproved). */
    async review(chequeId: number, status: ReviewOutcome, reviewNote?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${chequeId}/review`, {
            status,
            review_note: reviewNote ?? null,
        });
        return data.data as Cheque;
    },
    async requestUpdate(chequeId: number, payload: ProposedUpdate): Promise<UpdateRequest> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${chequeId}/update-requests`, payload);
        return data.data as UpdateRequest;
    },
};

export interface ProposedUpdate {
    payee_name: string;
    amount: number;
    cheque_date: string;
    reason: string;
}

export const AcicApi = {
    /**
     * `status` filters on the ACIC's lifecycle; `category` on what it carries (`cheques` /
     * `lddaps`). They are separate dimensions and combine.
     */
    async list(
        status = 'all',
        page = 1,
        perPage = 50,
        category: 'all' | 'cheques' | 'lddaps' = 'all',
    ): Promise<Paginated<Acic>> {
        const { data } = await http.get('/acics', {
            params: { status, page, per_page: perPage, category },
        });
        return data as Paginated<Acic>;
    },
    async show(id: number): Promise<Acic> {
        const { data } = await http.get(`/acics/${id}`);
        return data.data as Acic;
    },
    /** The lowest unused ACIC number, or null when the registered series is exhausted. */
    async next(): Promise<number | null> {
        const { data } = await http.get('/acics/next');
        return (data.data.acic_number ?? null) as number | null;
    },
    /** How much of the ACIC series is registered, and how much is still free. */
    async series(): Promise<AcicSeries> {
        const { data } = await http.get('/acics/series');
        return data.data as AcicSeries;
    },
    /** Admin only: register a block of ACIC numbers. */
    async addRange(
        startAt: number,
        endAt: number,
    ): Promise<{ from: number; to: number; count: number; message: string }> {
        await ensureCsrf();
        const { data } = await http.post('/acics/add-range', { start_at: startAt, end_at: endAt });
        return data.data;
    },
    /** Approved cheques not yet on any ACIC. */
    async linkableCheques(): Promise<Cheque[]> {
        const { data } = await http.get('/acics/linkable-cheques');
        return data.data as Cheque[];
    },
    async create(): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post('/acics');
        return data.data as Acic;
    },
    async assignCheques(id: number, chequeIds: number[]): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/cheques`, { cheque_ids: chequeIds });
        return data.data as Acic;
    },
    /** Admin only: sign an ACIC off, so it can be forwarded and printed. */
    async approve(id: number): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/approve`);
        return data.data as Acic;
    },
    /**
     * Admin/staff: swap one record on an ACIC for another. The released record goes back to the
     * pool and can be put on a later ACIC.
     */
    async reassign(
        id: number,
        payload: { type: 'cheque' | 'lddap'; releaseId: number; assignId: number },
    ): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/reassign`, {
            type: payload.type,
            release_id: payload.releaseId,
            assign_id: payload.assignId,
        });
        return data.data as Acic;
    },
    /** Teller only: mark a forwarded ACIC as completed. */
    async complete(id: number): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/complete`);
        return data.data as Acic;
    },
    /**
     * Forward an ACIC. The recipient is either a system user (`receivedBy`) or, when the ACIC
     * is handed to someone with no account, the typed-in name of whoever accepted it.
     */
    async forward(id: number, recipient: { receivedBy: number } | { receivedName: string }): Promise<Acic> {
        await ensureCsrf();
        const body =
            'receivedBy' in recipient
                ? { received_by: recipient.receivedBy }
                : { received_name: recipient.receivedName };
        const { data } = await http.post(`/acics/${id}/forward`, body);
        return data.data as Acic;
    },
    /**
     * Put approved LDDAP records on this ACIC. Each takes the next check number; the previewed
     * block goes along so a stale preview is refused rather than silently renumbered.
     */
    async assignLddaps(id: number, lddapIds: number[], expectedCheckNos: number[] = []): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/lddaps`, {
            lddap_ids: lddapIds,
            expected_check_nos: expectedCheckNos,
        });
        return data.data as Acic;
    },
};


export const PayeeApi = {
    /** Registered payees matching a name or account number. Empty term lists the first page. */
    async search(term: string): Promise<Payee[]> {
        const { data } = await http.get('/payees', { params: term ? { search: term } : {} });
        return data.data as Payee[];
    },
    /** One payee with its accounts — pre-fills the picker in "Edit LDDAP Record". */
    async get(id: number): Promise<Payee> {
        const { data } = await http.get(`/payees/${id}`);
        return data.data as Payee;
    },
};

/** The register/edit form's fields as `POST /lddaps` and `PUT /lddaps/{id}` take them. */
function lddapPayload(draft: LddapDraft): Record<string, unknown> {
    return {
        lddap_no: draft.lddap_no.trim(),
        nca_no: draft.nca_no.trim(),
        orb_no: draft.orb_no.trim(),
        dv_no: draft.dv_no.trim(),
        nature_of_payment: draft.nature_of_payment,
        obj_no: draft.obj_no.trim() || null,
        unit_id: draft.unit_id ? Number(draft.unit_id) : null,
        check_date: draft.check_date,
        payee_id: draft.payee?.id ?? null,
        payee_account_id: draft.payee_account_id,
        acic_ref: draft.acic_ref.trim() || null,
        gross_amount: draft.gross_amount,
        wtax_1: draft.wtax['0.01'] || '0',
        wtax_2: draft.wtax['0.02'] || '0',
        wtax_3: draft.wtax['0.03'] || '0',
        wtax_5: draft.wtax['0.05'] || '0',
        vat_1: draft.vat['0.01'] || '0',
        vat_2: draft.vat['0.02'] || '0',
        vat_3: draft.vat['0.03'] || '0',
        vat_5: draft.vat['0.05'] || '0',
        vat_10: draft.vat['0.10'] || '0',
        vat_12: draft.vat['0.12'] || '0',
        vat_30: draft.vat['0.30'] || '0',
        retention: draft.retention || '0',
        liquidated_damages: draft.liquidated_damages || '0',
        advance_payment: draft.advance_payment || '0',
        fwd_to_lbp_at: draft.fwd_to_lbp_at || null,
        date_loaded: draft.date_loaded || null,
        note: draft.note.trim() || null,
        remarks: draft.remarks.trim() || null,
    };
}

export const LddapApi = {
    /**
     * The table, filtered. `search` matches part of the LDDAP number or check number, or the
     * gross amount exactly (typed with or without ₱ and commas); `status` and `nature` take
     * "all" or one value. The filters combine.
     */
    async list(filters: LddapListFilters): Promise<Paginated<Lddap>> {
        const { data } = await http.get('/lddaps', {
            params: {
                status: filters.status || 'all',
                nature: filters.nature || 'all',
                page: filters.page ?? 1,
                per_page: filters.perPage ?? 50,
                ...(filters.search ? { search: filters.search } : {}),
            },
        });
        return data as Paginated<Lddap>;
    },
    /**
     * The block of `count` consecutive unused check numbers a batch of that size would take —
     * empty when the series holds no run that long.
     */
    async nextNumbers(count: number): Promise<NextCheckNumbers> {
        const { data } = await http.get('/lddaps/next-numbers', { params: { count } });
        return data.data as NextCheckNumbers;
    },
    /** How many numbers the LDDAP check series holds, and how many are still unused. */
    async series(): Promise<LddapSeries> {
        const { data } = await http.get('/lddaps/series');
        return data.data as LddapSeries;
    },
    /** Approved LDDAPs not yet on any ACIC. */
    async linkable(): Promise<Lddap[]> {
        const { data } = await http.get('/lddaps/linkable');
        return data.data as Lddap[];
    },
    /**
     * Register one LDDAP record. It carries no check number — that arrives when the approved
     * record is put on an ACIC.
     */
    async register(draft: LddapDraft): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post('/lddaps', lddapPayload(draft));
        return data.data as Lddap;
    },
    /**
     * Edit LDDAP Record: the same form, saved onto a Registered or RTS record. The check
     * number is not part of it.
     */
    async edit(id: number, draft: LddapDraft): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.put(`/lddaps/${id}`, lddapPayload(draft));
        return data.data as Lddap;
    },
    /** Every edit the record has had, newest first. */
    async editHistory(id: number): Promise<LddapEdit[]> {
        const { data } = await http.get(`/lddaps/${id}/edit-history`);
        return data.data as LddapEdit[];
    },
    /** Admin/staff: Forward — Registered → For Out. */
    async forward(
        id: number,
        details: { forward_to: string; unit_id: number; date_forwarded: string; note: string },
    ): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/forward`, {
            ...details,
            note: details.note.trim() || null,
        });
        return data.data as Lddap;
    },
    /** Admin/staff: Receive — For Out → Returned for ACIC. */
    async receiveBack(
        id: number,
        details: { unit_id: number; date_received: string; note: string },
    ): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/receive-back`, {
            ...details,
            note: details.note.trim() || null,
        });
        return data.data as Lddap;
    },
    /** Admin only: Approve a record Returned for ACIC, with an optional note. */
    async approve(id: number, note: string): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/approve`, { note: note.trim() || null });
        return data.data as Lddap;
    },
    /** Admin only: Cancel a record Returned for ACIC. Canceled By is the signed-in user. */
    async cancel(id: number, details: { date_canceled: string; note: string }): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/cancel`, details);
        return data.data as Lddap;
    },
    /** Admin only: RTS — Returned for ACIC → RTS, with who received it, the unit, the date and why. */
    async rts(
        id: number,
        details: { received_on: string; received_by: string; unit_id: number; rts_date: string; note: string },
    ): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/rts`, details);
        return data.data as Lddap;
    },
    /** The record's routing trail, oldest first. */
    async routingHistory(id: number): Promise<LddapRoutingStep[]> {
        const { data } = await http.get(`/lddaps/${id}/routing-history`);
        return data.data as LddapRoutingStep[];
    },
    /**
     * Admin/staff: "Assign LDDAP to ACIC" by ACIC number. `expectedCheckNos` is the block the
     * dialog previewed, in the same order as `lddapIds`; the server refuses the save if it is
     * no longer the block about to be issued.
     */
    async assignToAcicNumber(
        acicNo: number,
        lddapIds: number[],
        expectedCheckNos: number[],
    ): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post('/lddaps/assign-acic', {
            acic_no: acicNo,
            lddap_ids: lddapIds,
            expected_check_nos: expectedCheckNos,
        });
        return data.data as Acic;
    },
    /** The select options the register dialog needs: every nature of payment, every unit. */
    async options(): Promise<LddapOptions> {
        const { data } = await http.get('/lddaps/options');
        return data.data as LddapOptions;
    },
    /** Teller only: confirm an LDDAP has been received. */
    async confirmReceipt(id: number): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/receive`);
        return data.data as Lddap;
    },
    /** Staff only: propose corrected details for an LDDAP, with a reason. */
    async requestUpdate(id: number, payload: ProposedLddapUpdate): Promise<LddapUpdateRequest> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/update-requests`, payload);
        return data.data as LddapUpdateRequest;
    },
    /**
     * Admin only: correct an LDDAP's details directly. Takes effect immediately — the reason is
     * required and is written into the record's correction history.
     */
    async update(id: number, payload: ProposedLddapUpdate): Promise<LddapUpdateRequest> {
        await ensureCsrf();
        const { data } = await http.patch(`/lddaps/${id}`, payload);
        return data.data as LddapUpdateRequest;
    },
    /** Admin only: register a block of the LDDAP check series. */
    async addRange(
        startAt: number,
        endAt: number,
    ): Promise<{ from: number; to: number; count: number; message: string }> {
        await ensureCsrf();
        const { data } = await http.post('/lddaps/add-range', { start_at: startAt, end_at: endAt });
        return data.data;
    },
};

export const LddapUpdateRequestApi = {
    /** Admin only: the LDDAP correction queue. */
    async list(status = 'pending', page = 1, perPage = 50): Promise<Paginated<LddapUpdateRequest>> {
        const { data } = await http.get('/lddap-update-requests', {
            params: { status, page, per_page: perPage },
        });
        return data as Paginated<LddapUpdateRequest>;
    },
    /** One LDDAP's request history, with outcomes. Readable by any authenticated user. */
    async forLddap(lddapId: number): Promise<LddapUpdateRequest[]> {
        const { data } = await http.get(`/lddaps/${lddapId}/update-requests`);
        return data.data as LddapUpdateRequest[];
    },
    async approve(id: number, reviewNote?: string): Promise<LddapUpdateRequest> {
        await ensureCsrf();
        const { data } = await http.post(`/lddap-update-requests/${id}/approve`, {
            review_note: reviewNote ?? null,
        });
        return data.data as LddapUpdateRequest;
    },
    async reject(id: number, reviewNote?: string): Promise<LddapUpdateRequest> {
        await ensureCsrf();
        const { data } = await http.post(`/lddap-update-requests/${id}/reject`, {
            review_note: reviewNote ?? null,
        });
        return data.data as LddapUpdateRequest;
    },
};

export const UpdateRequestApi = {
    async list(status = 'pending', page = 1, perPage = 50): Promise<Paginated<UpdateRequest>> {
        const { data } = await http.get('/update-requests', {
            params: { status, page, per_page: perPage },
        });
        return data as Paginated<UpdateRequest>;
    },
    async forCheque(chequeId: number): Promise<UpdateRequest[]> {
        const { data } = await http.get(`/cheques/${chequeId}/update-requests`);
        return data.data as UpdateRequest[];
    },
    async approve(id: number, reviewNote?: string): Promise<UpdateRequest> {
        await ensureCsrf();
        const { data } = await http.post(`/update-requests/${id}/approve`, { review_note: reviewNote ?? null });
        return data.data as UpdateRequest;
    },
    async reject(id: number, reviewNote?: string): Promise<UpdateRequest> {
        await ensureCsrf();
        const { data } = await http.post(`/update-requests/${id}/reject`, { review_note: reviewNote ?? null });
        return data.data as UpdateRequest;
    },
};

export const DashboardApi = {
    async show(): Promise<Dashboard> {
        const { data } = await http.get('/dashboard');
        return data.data as Dashboard;
    },
};

export const NotificationApi = {
    async list(limit = 15): Promise<NotificationFeed> {
        const { data } = await http.get('/notifications', { params: { limit } });
        return data as NotificationFeed;
    },
    async markRead(id: string): Promise<AppNotification> {
        await ensureCsrf();
        const { data } = await http.post(`/notifications/${id}/read`);
        return data.data as AppNotification;
    },
    async markAllRead(): Promise<void> {
        await ensureCsrf();
        await http.post('/notifications/read-all');
    },
};

export const LogApi = {
    async list(params: Record<string, string | number>): Promise<Paginated<ChequeLog>> {
        const { data } = await http.get('/logs', { params });
        return data as Paginated<ChequeLog>;
    },
};

export const UserApi = {
    async list(): Promise<User[]> {
        const { data } = await http.get('/users');
        return data.data as User[];
    },
    async create(payload: Partial<User> & { password: string }): Promise<User> {
        await ensureCsrf();
        const { data } = await http.post('/users', payload);
        return data.data as User;
    },
    async update(id: number, payload: Record<string, unknown>): Promise<User> {
        await ensureCsrf();
        const { data } = await http.put(`/users/${id}`, payload);
        return data.data as User;
    },
    async remove(id: number): Promise<void> {
        await ensureCsrf();
        await http.delete(`/users/${id}`);
    },
};
