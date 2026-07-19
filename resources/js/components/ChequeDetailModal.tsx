import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { X, CheckCircle2, Landmark, PencilLine, Clock, History, Check, Ban } from 'lucide-react';
import { ChequeApi, UpdateRequestApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Cheque, RequestStatus, UpdateRequest } from '../lib/types';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';
import { Alert, StatusBadge } from './ui';

const REQUEST_STATUS: Record<RequestStatus, { label: string; styles: string; icon: typeof Clock }> = {
    pending: { label: 'Pending', styles: 'border-accent-400/50 bg-accent-400/10 text-accent-400', icon: Clock },
    approved: { label: 'Approved', styles: 'border-success/40 bg-success/10 text-success-fg', icon: Check },
    rejected: { label: 'Rejected', styles: 'border-danger/40 bg-danger/10 text-danger-fg', icon: Ban },
};

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 border-b border-line/60 py-2.5 last:border-0">
            <span className="text-xs font-semibold uppercase tracking-wider text-subtle">{label}</span>
            <span className="text-right text-sm text-fg">{value}</span>
        </div>
    );
}

interface Props {
    cheque: Cheque;
    onClose: () => void;
    onChanged: (cheque: Cheque) => void;
}

export default function ChequeDetailModal({ cheque, onClose, onChanged }: Props) {
    const { user, isTeller } = useAuth();
    const isStaff = user?.role === 'staff';
    const [current, setCurrent] = useState<Cheque>(cheque);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    // Staff "request an update" state — staff propose the corrected values plus a reason.
    const [requesting, setRequesting] = useState(false);
    const [payee, setPayee] = useState(cheque.payee_name ?? '');
    const [amount, setAmount] = useState(cheque.amount ? String(Number(cheque.amount)) : '');
    const [chequeDate, setChequeDate] = useState(cheque.cheque_date ?? '');
    const [reason, setReason] = useState('');
    const [reqBusy, setReqBusy] = useState(false);
    const [reqError, setReqError] = useState('');
    const [pendingRequest, setPendingRequest] = useState(!!cheque.has_pending_update);
    const [history, setHistory] = useState<UpdateRequest[]>([]);

    // Load the cheque's request history on open; this also re-derives the true hold state
    // (so a teller never sees the confirm button on a cheque that is actually on hold).
    const loadHistory = useCallback(async () => {
        try {
            const list = await UpdateRequestApi.forCheque(cheque.id);
            setHistory(list);
            setPendingRequest(list.some((r) => r.status === 'pending'));
        } catch {
            /* non-fatal — history just won't show */
        }
    }, [cheque.id]);

    useEffect(() => {
        void loadHistory();
    }, [loadHistory]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const isIssued = current.status === 'used' || current.status === 'received';
    const isReceived = current.status === 'received';
    const isPending = current.status === 'used';
    // A used cheque with an open update request is on hold: the teller can't confirm it yet.
    const onHold = isPending && pendingRequest;

    async function handleConfirm() {
        setBusy(true);
        setError('');
        try {
            const updated = await ChequeApi.confirmReceipt(current.id);
            setCurrent(updated);
            onChanged(updated);
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setBusy(false);
        }
    }

    async function handleRequestUpdate(e: FormEvent) {
        e.preventDefault();
        setReqBusy(true);
        setReqError('');
        try {
            await ChequeApi.requestUpdate(current.id, {
                payee_name: payee.trim(),
                amount: Number(amount),
                cheque_date: chequeDate,
                reason: reason.trim(),
            });
            setPendingRequest(true);
            setRequesting(false);
            setReason('');
            void loadHistory();
        } catch (err) {
            const apiErr = toApiError(err);
            setReqError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setReqBusy(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
        >
            <div className="card max-h-[90vh] w-full max-w-md overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Cheque</span>
                        <div className="mt-2 font-display text-4xl font-extrabold tracking-tight text-brandink">
                            #{current.cheque_number}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <StatusBadge status={current.status} />
                        {onHold && (
                            <span className="inline-flex items-center gap-1 rounded-xs border border-accent-400/50 bg-accent-400/10 px-2 py-0.5 text-xs font-medium text-accent-400">
                                <Clock className="h-3 w-3" />
                                On hold
                            </span>
                        )}
                        <button className="btn btn-ghost !px-2" onClick={onClose} aria-label="Close">
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                </div>

                {!isIssued ? (
                    <div className="rounded-xs border border-line bg-well px-4 py-6 text-center text-sm text-muted">
                        This cheque is still <span className="font-semibold text-brandink">available</span> and has not
                        been used yet.
                    </div>
                ) : (
                    <div className="space-y-5">
                        {/* Cheque details */}
                        <section>
                            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wider text-subtle">
                                Cheque details
                            </h3>
                            <Row label="Payee / name" value={current.payee_name ?? '—'} />
                            <Row label="Amount" value={formatMoney(current.amount)} />
                            <Row label="Cheque date" value={formatDate(current.cheque_date)} />
                            <Row label="Used by" value={current.used_by?.name ?? current.used_by_name ?? '—'} />
                            <Row label="Used at" value={formatDateTime(current.used_at)} />
                        </section>

                        {/* Receipt confirmation — the teller lifecycle step */}
                        <section className="rounded-xs border border-line bg-well p-4">
                            <h3 className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brandink">
                                <Landmark className="h-4 w-4" />
                                Receipt confirmation
                            </h3>

                            {isReceived ? (
                                <>
                                    <Row
                                        label="Received by teller"
                                        value={current.received_by?.name ?? current.received_by_name ?? '—'}
                                    />
                                    <Row label="Confirmed at" value={formatDateTime(current.received_at)} />
                                    <p className="mt-3 flex items-center gap-1.5 text-xs text-success-fg">
                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                        This cheque has been confirmed as received.
                                    </p>
                                </>
                            ) : onHold ? (
                                <p className="flex items-start gap-1.5 text-sm text-muted">
                                    <Clock className="mt-0.5 h-4 w-4 shrink-0 text-accent-400" />
                                    On hold — a detail update request is awaiting admin approval. Receipt can’t be
                                    confirmed until the request is approved or rejected.
                                </p>
                            ) : isTeller ? (
                                <>
                                    <p className="mb-3 text-sm text-muted">
                                        Not yet confirmed. Confirm once this cheque has been received.
                                    </p>
                                    {error && (
                                        <div className="mb-3">
                                            <Alert kind="error">{error}</Alert>
                                        </div>
                                    )}
                                    <button className="btn btn-primary w-full" onClick={handleConfirm} disabled={busy}>
                                        <CheckCircle2 className="h-4 w-4" />
                                        {busy ? 'Confirming…' : 'Confirm received'}
                                    </button>
                                </>
                            ) : (
                                <p className="text-sm text-muted">
                                    {isPending
                                        ? 'Awaiting confirmation by a teller.'
                                        : 'Not yet confirmed as received.'}
                                </p>
                            )}
                        </section>

                        {/* Update-request history with the admin's decision on each. */}
                        {history.length > 0 && (
                            <section>
                                <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-subtle">
                                    <History className="h-4 w-4" />
                                    Update history
                                </h3>
                                <div className="space-y-2">
                                    {history.map((req) => {
                                        const meta = REQUEST_STATUS[req.status];
                                        const Icon = meta.icon;
                                        return (
                                            <div key={req.id} className="rounded-xs border border-line bg-well p-3">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span
                                                        className={`inline-flex items-center gap-1 rounded-xs border px-2 py-0.5 text-xs font-medium ${meta.styles}`}
                                                    >
                                                        <Icon className="h-3 w-3" />
                                                        {meta.label}
                                                    </span>
                                                    <span className="text-xs text-subtle">
                                                        {formatDateTime(req.created_at)}
                                                    </span>
                                                </div>
                                                <p className="mt-2 text-xs text-subtle">
                                                    Requested by{' '}
                                                    <span className="text-fg">{req.requested_by?.name ?? '—'}</span>
                                                </p>
                                                <p className="mt-1 text-sm text-fg">“{req.reason}”</p>
                                                <p className="mt-2 text-xs text-muted">
                                                    Proposed — {req.proposed_payee_name ?? '—'} ·{' '}
                                                    {formatMoney(req.proposed_amount)} ·{' '}
                                                    {formatDate(req.proposed_cheque_date)}
                                                </p>
                                                {req.status !== 'pending' && (
                                                    <p
                                                        className={`mt-2 text-xs ${
                                                            req.status === 'approved'
                                                                ? 'text-success-fg'
                                                                : 'text-danger-fg'
                                                        }`}
                                                    >
                                                        {req.status === 'approved' ? 'Approved' : 'Rejected'} by{' '}
                                                        {req.reviewed_by?.name ?? 'admin'}
                                                        {req.reviewed_at ? ` on ${formatDateTime(req.reviewed_at)}` : ''}
                                                        {req.review_note ? ` — “${req.review_note}”` : ''}
                                                    </p>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                        )}

                        {/* Staff can request a correction to the details; an admin must approve it. */}
                        {isStaff && (
                            <section className="rounded-xs border border-line bg-well p-4">
                                <h3 className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brandink">
                                    <PencilLine className="h-4 w-4" />
                                    Detail correction
                                </h3>

                                {pendingRequest ? (
                                    <p className="flex items-center gap-1.5 text-sm text-muted">
                                        <Clock className="h-4 w-4 text-accent-400" />
                                        An update request is pending admin approval.
                                    </p>
                                ) : requesting ? (
                                    <form onSubmit={handleRequestUpdate} className="space-y-3">
                                        <p className="text-xs text-muted">
                                            Edit the values below to what they should be, then give a reason. An admin
                                            reviews and applies your change.
                                        </p>
                                        <div>
                                            <label htmlFor="req-payee" className="label">
                                                Payee / name
                                            </label>
                                            <input
                                                id="req-payee"
                                                className="field"
                                                value={payee}
                                                onChange={(e) => setPayee(e.target.value)}
                                                autoFocus
                                                required
                                            />
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <label htmlFor="req-amount" className="label">
                                                    Amount
                                                </label>
                                                <input
                                                    id="req-amount"
                                                    type="number"
                                                    step="0.01"
                                                    min="0.01"
                                                    className="field"
                                                    value={amount}
                                                    onChange={(e) => setAmount(e.target.value)}
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <label htmlFor="req-date" className="label">
                                                    Cheque date
                                                </label>
                                                <input
                                                    id="req-date"
                                                    type="date"
                                                    className="field"
                                                    value={chequeDate}
                                                    onChange={(e) => setChequeDate(e.target.value)}
                                                    required
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <label htmlFor="reason" className="label">
                                                Reason for the change
                                            </label>
                                            <textarea
                                                id="reason"
                                                className="field min-h-20"
                                                value={reason}
                                                onChange={(e) => setReason(e.target.value)}
                                                placeholder="Explain what is wrong and why it must change…"
                                                required
                                                minLength={5}
                                            />
                                        </div>
                                        {reqError && <Alert kind="error">{reqError}</Alert>}
                                        <div className="flex gap-2">
                                            <button type="submit" className="btn btn-primary flex-1" disabled={reqBusy}>
                                                {reqBusy ? 'Submitting…' : 'Submit request'}
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-ghost"
                                                onClick={() => setRequesting(false)}
                                                disabled={reqBusy}
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                ) : (
                                    <>
                                        <p className="mb-3 text-sm text-muted">
                                            Spotted a mistake in the details above? Propose a correction — an admin
                                            will review and apply it.
                                        </p>
                                        <button className="btn btn-outline w-full" onClick={() => setRequesting(true)}>
                                            <PencilLine className="h-4 w-4" />
                                            Request an update
                                        </button>
                                    </>
                                )}
                            </section>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
