import axios, { AxiosError } from 'axios';
import type {
    AppNotification,
    Cheque,
    ChequeDetails,
    ChequeLog,
    Lddap,
    LddapDraft,
    LddapReviewOutcome,
    LddapSeries,
    LddapUpdateRequest,
    ProposedLddapUpdate,
    NextCheckNumbers,
    NotificationFeed,
    Acic,
    AcicSeries,
    Paginated,
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
    /** Put completed LDDAP records on this ACIC. */
    async assignLddaps(id: number, lddapIds: number[]): Promise<Acic> {
        await ensureCsrf();
        const { data } = await http.post(`/acics/${id}/lddaps`, { lddap_ids: lddapIds });
        return data.data as Acic;
    },
};

export const LddapApi = {
    /** `search` matches the LDDAP number, OBJ number, payee, check number or ACIC number. */
    async list(status = 'all', page = 1, perPage = 50, search = ''): Promise<Paginated<Lddap>> {
        const { data } = await http.get('/lddaps', {
            params: { status, page, per_page: perPage, ...(search ? { search } : {}) },
        });
        return data as Paginated<Lddap>;
    },
    /** The next `count` check numbers in the LDDAP series, lowest unused first. */
    async nextNumbers(count: number): Promise<NextCheckNumbers> {
        const { data } = await http.get('/lddaps/next-numbers', { params: { count } });
        return data.data as NextCheckNumbers;
    },
    /** How many numbers the LDDAP check series holds, and how many are still unused. */
    async series(): Promise<LddapSeries> {
        const { data } = await http.get('/lddaps/series');
        return data.data as LddapSeries;
    },
    /** Completed LDDAPs not yet on any ACIC. */
    async linkable(): Promise<Lddap[]> {
        const { data } = await http.get('/lddaps/linkable');
        return data.data as Lddap[];
    },
    /**
     * Register LDDAP records, each taking the next check number in the series. `startAt` is the
     * number the batch is expected to start at — the server rejects the request if the series has
     * moved on since the preview, so a number can never be skipped. The check date is stamped
     * server-side from the day of registration.
     */
    async consumeCheckNumbers(startAt: number, rows: LddapDraft[]): Promise<Lddap[]> {
        await ensureCsrf();
        const { data } = await http.post('/lddaps/use-cheque', {
            start_at: startAt,
            rows: rows.map((row) => ({
                lddap_no: row.lddap_no.trim(),
                obj_no: row.obj_no.trim() || null,
                payee_name: row.payee_name.trim() || null,
                amount: row.amount,
            })),
        });
        return data.data as Lddap[];
    },
    /** Teller only: confirm an LDDAP has been received. */
    async confirmReceipt(id: number): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/receive`);
        return data.data as Lddap;
    },
    /** Admin only: record the review outcome (completed | compliance | cancelled). */
    async review(id: number, status: LddapReviewOutcome, reviewNote?: string): Promise<Lddap> {
        await ensureCsrf();
        const { data } = await http.post(`/lddaps/${id}/review`, {
            status,
            review_note: reviewNote ?? null,
        });
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
