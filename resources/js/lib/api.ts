import axios, { AxiosError } from 'axios';
import type {
    AccountHolder,
    AccountHolderDraft,
    AppNotification,
    Cheque,
    ChequeDetails,
    ChequeStatusStep,
    ChequeListFilters,
    ChequeValiditySummary,
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
    AcicHistoryStep,
    AcicSeries,
    Paginated,
    PayeeOption,
    Summary,
    TellerQueue,
    TellerForwardTo,
    RtsOutcome,
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
    /**
     * The validity banner's counts, the tellers a cheque can be forwarded to, and the size of
     * the viewer's own deposit queue.
     */
    async validitySummary(): Promise<ChequeValiditySummary> {
        const { data } = await http.get('/cheques/validity-summary');
        return data.data as ChequeValiditySummary;
    },
    /** Branch A — hand a cheque on an ACIC to the payee. */
    async release(
        id: number,
        details: { received_by_name: string; date_received: string; note?: string; expected_status?: string },
    ): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/release`, details);
        return data.data as Cheque;
    },
    /** Cancel — needs a reason. */
    async except(id: number, step: 'cancel', reason: string, expectedStatus?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/${step}`, { reason, expected_status: expectedStatus });
        return data.data as Cheque;
    },
    /**
     * Admin: mark an Approved cheque Spoiled. The payment moves to a replacement on the next
     * available number — the one the dialog showed, or the step is refused. The spoiled cheque
     * comes back with `replaced_by`.
     */
    /** Admin/staff: a spoiled cheque's replacement takes its place on the ACIC it came off. */
    async assignToPreviousAcic(id: number, expectedStatus?: string): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/use-previous-acic`, { expected_status: expectedStatus });
        return data.data as Acic;
    },
    async spoil(id: number, reason: string, replacementNumber: number | null, expectedStatus?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/spoil`, {
            reason,
            replacement_number: replacementNumber ?? undefined,
            expected_status: expectedStatus,
        });
        return data.data as Cheque;
    },
    /** The lowest available cheque number, or null when every registered book is used up. */
    async nextNumber(): Promise<number | null> {
        const { data } = await http.get('/cheques/next');
        return (data.data?.cheque_number ?? null) as number | null;
    },
    /** Print Draft — submits the cheque to the admin in charge; it becomes For Checking. */
    async printDraft(id: number, expectedStatus?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/print-draft`, { expected_status: expectedStatus });
        return data.data as Cheque;
    },
    /** Super Admin: the draft is correct — For Final Print. */
    async approveDraft(id: number, comment?: string, expectedStatus?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/approve-draft`, { comment: comment || undefined, expected_status: expectedStatus });
        return data.data as Cheque;
    },
    /** Super Admin: the draft is not correct — For Compliance, with what to change. */
    async returnDraft(id: number, comment: string, expectedStatus?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/return-draft`, { comment, expected_status: expectedStatus });
        return data.data as Cheque;
    },
    /** The final print came out right — For Signature. */
    async confirmFinalPrint(id: number, expectedStatus?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/final-print`, { expected_status: expectedStatus });
        return data.data as Cheque;
    },
    /** Edit the details — only with no status yet, or while For Compliance. */
    async updateDetails(id: number, details: ChequeDetails, expectedStatus?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.put(`/cheques/${id}`, { ...details, expected_status: expectedStatus });
        return data.data as Cheque;
    },
    /** Admin: issue a replacement for a stale cheque, on the next available number. */
    async replace(id: number, chequeDate?: string): Promise<Cheque> {
        await ensureCsrf();
        const { data } = await http.post(`/cheques/${id}/replace`, chequeDate ? { cheque_date: chequeDate } : {});
        return data.data as Cheque;
    },
    /** Every step the cheque has taken, oldest first. */
    async statusHistory(id: number): Promise<ChequeStatusStep[]> {
        const { data } = await http.get(`/cheques/${id}/status-history`);
        return data.data as ChequeStatusStep[];
    },
    /**
     * One page of the register, filtered on the server. `search` matches the cheque number,
     * payee or account number; `tab` is a status (or the Expiring Soon view); `unit` one PCG
     * unit; `dateFrom`/`dateTo` the cheque date, both ends included. Blank filters are left out.
     */
    async list(filters: ChequeListFilters): Promise<Paginated<Cheque>> {
        const { page = 1, perPage = 50, search = '', tab = 'all', sort = 'number', unit = '', dateFrom = '', dateTo = '' } = filters;
        const { data } = await http.get('/cheques', {
            params: {
                page,
                per_page: perPage,
                ...(search ? { search } : {}),
                ...(tab !== 'all' ? { tab } : {}),
                ...(sort !== 'number' ? { sort } : {}),
                ...(unit ? { unit } : {}),
                ...(dateFrom ? { date_from: dateFrom } : {}),
                ...(dateTo ? { date_to: dateTo } : {}),
            },
        });
        return data as Paginated<Cheque>;
    },
    /**
     * Admin/staff: put For Signature cheques on the ACIC a typed number means — an existing one
     * that still takes cheques, or the next in the series. Many cheques may share one number.
     */
    async assignToAcicNumber(acicNo: number, chequeIds: number[]): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post('/cheques/assign-acic', { acic_no: acicNo, cheque_ids: chequeIds });
        return data.data as Acic;
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
};

export const AcicApi = {
    /**
     * `status` filters on the ACIC's lifecycle; `category` on what it carries (`cheques` /
     * `lddaps`); `search` matches the ACIC number or the cheque / LDDAP / check / DV number of
     * anything on it. They combine, on the server.
     */
    async list(
        status = 'all',
        page = 1,
        perPage = 50,
        category: 'all' | 'cheques' | 'lddaps' = 'all',
        search = '',
    ): Promise<Paginated<Acic>> {
        const { data } = await http.get('/acics', {
            params: { status, page, per_page: perPage, category, ...(search ? { search } : {}) },
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
    /** Creditors and PCG Personnel in one list, by name or account number. */
    async search(term: string): Promise<PayeeOption[]> {
        const { data } = await http.get('/lddaps/payee-options', { params: term ? { search: term } : {} });
        return data.data as PayeeOption[];
    },
};

/** The register/edit form's fields as `POST /lddaps` and `PUT /lddaps/{id}` take them. */
function lddapPayload(draft: LddapDraft): Record<string, unknown> {
    return {
        lddap_no: draft.lddap_no.trim(),
        nca_no: draft.nca_no.trim(),
        obr_no: draft.obr_no.trim(),
        dv_no: draft.dv_no.trim(),
        nature_of_payment: draft.nature_of_payment,
        obj_no: draft.obj_no.trim() || null,
        unit_name: draft.unit_name || null,
        check_date: draft.check_date,
        // Only a fresh pick is sent; an edit that keeps the saved payee leaves these out.
        ...(draft.payee?.id != null ? { payee_type: draft.payee.type, payee_ref: draft.payee.id } : {}),
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

export const AcicTellerApi = {
    /** Admin: send a whole ACIC — cheque or LDDAP — to the tellers. */
    async forwardToTeller(id: number, details: { note?: string } = {}): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/forward-to-teller`, details);
        return data.data as Acic;
    },
    /** Teller: claim a Pending ACIC. The first to get here takes it. */
    async accept(id: number, expectedStatus?: string): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/accept`, { expected_status: expectedStatus });
        return data.data as Acic;
    },
    /** The accepting teller: take it to Land Bank or to the payee. */
    async forward(id: number, to: TellerForwardTo, expectedStatus?: string): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/teller-forward`, { to, expected_status: expectedStatus });
        return data.data as Acic;
    },
    /** The accepting teller's Action → Completed. */
    async complete(id: number, expectedStatus?: string): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/teller-complete`, { expected_status: expectedStatus });
        return data.data as Acic;
    },
    /**
     * The accepting teller: forward one, several or all of a cheque ACIC's cheques to their
     * payees, with who received them, when and their unit (once for the batch).
     */
    async forwardToPayee(
        id: number,
        payload: { cheque_ids: number[]; received_by: string; date_received: string; unit: string },
        expectedStatus?: string,
    ): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/forward-to-payee`, { ...payload, expected_status: expectedStatus });
        return data.data as Acic;
    },
    /** The accepting teller's Action → RTS: a reason, and a status for every check ("cheque:ID"). */
    async rts(id: number, reason: string, outcomes: Record<string, RtsOutcome>, expectedStatus?: string): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/teller-rts`, { reason, outcomes, expected_status: expectedStatus });
        return data.data as Acic;
    },
    /** Teller: hand it back to the admin, with a reason. */
    async returnToAdmin(id: number, reason: string, expectedStatus?: string): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/return-to-admin`, { reason, expected_status: expectedStatus });
        return data.data as Acic;
    },
    /** Every step of an ACIC's teller life, oldest first. */
    async history(id: number): Promise<AcicHistoryStep[]> {
        const { data } = await http.get(`/acics/${id}/history`);
        return data.data as AcicHistoryStep[];
    },
    /** The teller dashboard's lists, with the filters applied (they only narrow each list). */
    async queue(
        filters: { type?: string; from?: string; to?: string; search?: string; status?: string } = {},
    ): Promise<TellerQueue> {
        const { data } = await http.get('/acics/teller-queue', { params: filters });
        return data.data as TellerQueue;
    },
};

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
                ...(filters.payeeType && filters.payeeType !== 'all' ? { payee_type: filters.payeeType } : {}),
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
    /** For Signature LDDAPs not yet on any ACIC. */
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
    /** Admin/staff: Resubmit a corrected RTS record — back to For Signature. Comment required, notes optional. */
    async resubmit(id: number, comment: string, notes: string): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/resubmit`, { comment, notes: notes || null });
        return data.data as Lddap;
    },
    /** Admin only: Cancel a For Signature record. Canceled By is the signed-in user. */
    async cancel(id: number, details: { date_canceled: string; note: string }): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/cancel`, details);
        return data.data as Lddap;
    },
    /** Admin only: RTS — Returned for ACIC → RTS, with who received it, the unit, the date and why. */
    async rts(
        id: number,
        details: { received_on: string; received_by: string; unit_name: string; rts_date: string; note: string },
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
    /** The select options the register dialog needs: every nature of payment. */
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

/** The creditor and PCG personnel lists share one set of endpoints under their own path. */
export interface AccountHolderApi {
    list(params: { page: number; search?: string; unit?: string }): Promise<Paginated<AccountHolder>>;
    /** Add one or more entries — all are saved, or (on a 422, as `records.N.field`) none are. */
    create(drafts: AccountHolderDraft[]): Promise<AccountHolder[]>;
    /** Batch Upload — all rows are saved, or (on a 422) none are. */
    upload(file: File): Promise<{ count: number }>;
}

function accountHolderApi(path: string): AccountHolderApi {
    return {
        async list(params) {
            const { data } = await http.get(path, { params });
            return data as Paginated<AccountHolder>;
        },
        async create(drafts) {
            await ensureCsrf();
            const { data } = await http.post(path, {
                records: drafts.map((d) => ({
                    name: d.name.trim(),
                    account_no: d.account_no.trim(),
                    unit: d.unit.trim() || null,
                })),
            });
            return data.data as AccountHolder[];
        },
        async upload(file) {
            await ensureCsrf();
            const form = new FormData();
            form.append('file', file);
            const { data } = await http.post(`${path}/batch-upload`, form);
            return data as { count: number };
        },
    };
}

export const CreditorApi = accountHolderApi('/creditors');
export const PcgPersonnelApi = accountHolderApi('/pcg-personnel');
