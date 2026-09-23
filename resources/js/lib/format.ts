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

const RELATIVE_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
];

/** "3 minutes ago", "2 days ago", or "just now" for very recent timestamps. */
export function formatRelative(iso?: string | null): string {
    if (!iso) return '';
    const diffSeconds = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diffSeconds < 45) return 'just now';
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    for (const [unit, secondsInUnit] of RELATIVE_UNITS) {
        if (diffSeconds >= secondsInUnit) {
            return rtf.format(-Math.floor(diffSeconds / secondsInUnit), unit);
        }
    }
    return 'just now';
}

const ACTION_LABELS: Record<string, string> = {
    login: 'Signed in',
    logout: 'Signed out',
    updated_profile: 'Updated profile',
    changed_password: 'Changed password',
    used_cheque: 'Used cheque',
    added_lddap_check_range: 'Added LDDAP check range',
    used_lddap_check: 'Used LDDAP check',
    received_lddap: 'Received LDDAP',
    reviewed_lddap: 'Reviewed LDDAP',
    requested_lddap_update: 'Requested LDDAP update',
    approved_lddap_update: 'Approved LDDAP update',
    rejected_lddap_update: 'Rejected LDDAP update',
    updated_lddap: 'Updated LDDAP',
    received_cheque: 'Received cheque',
    requested_update: 'Requested update',
    approved_update: 'Approved update',
    rejected_update: 'Rejected update',
    added_cheque_range: 'Added range',
    routed_for_signature: 'Routed for signature',
    ready_for_acic: 'Ready for ACIC',
    rts_cheque: 'Returned to sender',
    voided_cheque: 'Voided cheque',
    accepted_by_teller: 'Accepted by teller',
    released_cheque: 'Released cheque',
    forwarded_cheque_to_teller: 'Forwarded to teller',
    deposited_cheque: 'Deposited cheque',
    returned_cheque_from_teller: 'Returned by teller',
    cancelled_cheque: 'Cancelled cheque',
    spoiled_cheque: 'Spoiled cheque',
    staled_cheque: 'Marked stale',
    replaced_cheque: 'Replaced cheque',
    corrected_release: 'Corrected release details',
    created_acic: 'Opened ACIC',
    used_acic: 'Assigned to ACIC',
    forwarded_acic: 'Forwarded ACIC',
    completed_acic: 'Completed ACIC',
    reviewed_cheque: 'Reviewed cheque',
    created_user: 'Created user',
    updated_user: 'Updated user',
    deleted_user: 'Deleted user',
};

export function actionLabel(action: string): string {
    return ACTION_LABELS[action] ?? action;
}

/** The cheque flow's statuses, as the UI names them. */
const CHEQUE_STATUS_LABELS: Record<string, string> = {
    available: 'Available',
    registered: 'Registered',
    out_for_signature: 'Out for Signature',
    received: 'Received',
    for_acic: 'For ACIC',
    approved: 'Approved',
    released_to_payee: 'Released to Payee',
    forwarded_to_teller: 'Forwarded to Teller',
    accepted_by_teller: 'Accepted by Teller',
    returned_by_bank: 'Returned by Bank',
    completed: 'Completed',
    cancelled: 'Cancelled',
    voided: 'Voided',
    stale: 'Stale',
    replaced: 'Replaced',
};

export function chequeStatusLabel(status: string): string {
    return CHEQUE_STATUS_LABELS[status] ?? status;
}
