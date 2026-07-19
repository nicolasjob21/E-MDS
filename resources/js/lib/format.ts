/** Shared formatting helpers. */

export function formatDateTime(iso?: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatDate(iso?: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
    });
}

export function formatMoney(amount?: string | number | null): string {
    if (amount === null || amount === undefined || amount === '') return '—';
    const n = typeof amount === 'string' ? Number(amount) : amount;
    if (Number.isNaN(n)) return '—';
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const ACTION_LABELS: Record<string, string> = {
    login: 'Signed in',
    logout: 'Signed out',
    used_cheque: 'Used cheque',
    received_cheque: 'Received cheque',
    requested_update: 'Requested update',
    approved_update: 'Approved update',
    rejected_update: 'Rejected update',
    added_cheque_range: 'Added range',
    created_user: 'Created user',
    updated_user: 'Updated user',
    deleted_user: 'Deleted user',
};

export function actionLabel(action: string): string {
    return ACTION_LABELS[action] ?? action;
}
