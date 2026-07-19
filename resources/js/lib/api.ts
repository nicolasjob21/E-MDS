import axios, { AxiosError } from 'axios';
import type {
    Cheque,
    ChequeDetails,
    ChequeLog,
    Paginated,
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
    async list(status: string, page = 1, perPage = 50): Promise<Paginated<Cheque>> {
        const { data } = await http.get('/cheques', {
            params: { status, page, per_page: perPage },
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
    async addRange(count: number, startAt?: number): Promise<{ from: number; to: number; count: number; message: string }> {
        await ensureCsrf();
        const { data } = await http.post('/cheques/add-range', {
            count,
            start_at: startAt ?? null,
        });
        return data.data;
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
