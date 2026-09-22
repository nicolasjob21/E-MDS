import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { X, PencilLine, Clock, History, Check, Ban, Hash, AlertTriangle, Zap, CheckCircle2, Route, Undo2 } from 'lucide-react';
import { LddapUpdateRequestApi, LddapApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Lddap, LddapEdit, LddapRoutingStep, LddapUpdateRequest, RequestStatus } from '../lib/types';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';
import { Alert, LddapStatusBadge } from './ui';
import LddapRecordDetails, { Row } from './LddapRecordDetails';
import LddapRegisterModal from './LddapRegisterModal';

const REQUEST_STATUS: Record<RequestStatus, { label: string; styles: string; icon: typeof Clock }> = {
    pending: { label: 'Pending', styles: 'border-accent-400/50 bg-accent-400/10 text-accent-400', icon: Clock },
    approved: { label: 'Approved', styles: 'border-success/40 bg-success/10 text-success-fg', icon: Check },
    rejected: { label: 'Rejected', styles: 'border-danger/40 bg-danger/10 text-danger-fg', icon: Ban },
};

interface Props {
    lddap: Lddap;
    /**
     * `action` is the Returned entry point from the LDDAP table: the review outcome is hidden
     * — deciding it is not the staff member's call — and the dialog is there to edit details.
     */
    mode?: 'view' | 'action';
    onClose: () => void;
    onChanged: () => void;
}

/**
 * An LDDAP's full details, its correction history, and — for staff — the form to propose a
 * correction. The check number is shown but never editable: it comes from the LDDAP series.
 */
export default function LddapDetailModal({ lddap, mode = 'view', onClose, onChanged }: Props) {
    const { user } = useAuth();
    const isStaff = user?.role === 'staff';
    const isAdmin = user?.role === 'admin';
    const isTeller = user?.role === 'teller';

    // While Registered or RTS the record is edited through the register form itself ("Edit
    // LDDAP Record"). Once it is out for routing, a staff member can only propose a correction
    // for approval and an admin apply one with a reason; a canceled record is closed to both.
    const canOpenEdit = (isAdmin || isStaff) && !!lddap.can_edit;
    const canCorrect = (isAdmin || isStaff) && !lddap.can_edit && lddap.status !== 'canceled';
    const direct = isAdmin;

    const [editing, setEditing] = useState(false);
    const [edits, setEdits] = useState<LddapEdit[]>([]);

    const [requesting, setRequesting] = useState(false);
    const [lddapNo, setLddapNo] = useState(lddap.lddap_no);
    const [objNo, setObjNo] = useState(lddap.obj_no ?? '');
    const [payee, setPayee] = useState(lddap.payee_name ?? '');
    const [amount, setAmount] = useState(lddap.amount ? String(Number(lddap.amount)) : '');
    const [reason, setReason] = useState('');
    const [reqBusy, setReqBusy] = useState(false);
    const [reqError, setReqError] = useState('');
    const [pendingRequest, setPendingRequest] = useState(!!lddap.has_pending_update);
    const [history, setHistory] = useState<LddapUpdateRequest[]>([]);
    // Every routing step the record has taken, oldest first.
    const [routing, setRouting] = useState<LddapRoutingStep[]>([]);
    // The returns to sender, newest first — the RTS History section, and the count badge.
    const rtsEntries = routing.filter((step) => step.action === 'rts').slice().reverse();
    const latestRts = rtsEntries[0] ?? null;
    // Teller receipt confirmation lives here now, as it does for cheques.
    const [receiving, setReceiving] = useState(false);
    const [receiptError, setReceiptError] = useState('');

    // Loading the history also re-derives the true hold state, so the form is never offered on
    // a record that already has a request awaiting approval.
    const loadHistory = useCallback(async () => {
        try {
            const [list, trail, changes] = await Promise.all([
                LddapUpdateRequestApi.forLddap(lddap.id),
                LddapApi.routingHistory(lddap.id),
                LddapApi.editHistory(lddap.id),
            ]);
            setHistory(list);
            setRouting(trail);
            setEdits(changes);
            setPendingRequest(list.some((r) => r.status === 'pending'));
        } catch {
            /* non-fatal — history just won't show */
        }
    }, [lddap.id]);

    useEffect(() => {
        void loadHistory();
    }, [loadHistory]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            // With the edit dialog open on top, Esc is its to handle.
            if (e.key === 'Escape' && !reqBusy && !editing) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, reqBusy, editing]);

    async function handleConfirmReceipt() {
        setReceiving(true);
        setReceiptError('');
        try {
            await LddapApi.confirmReceipt(lddap.id);
            onChanged();
            onClose();
        } catch (err) {
            const apiErr = toApiError(err);
            setReceiptError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setReceiving(false);
        }
    }

    async function handleRequestUpdate(e: FormEvent) {
        e.preventDefault();
        setReqBusy(true);
        setReqError('');
        try {
            const payload = {
                lddap_no: lddapNo.trim(),
                obj_no: objNo.trim(),
                payee_name: payee.trim(),
                amount: Number(amount),
                reason: reason.trim(),
            };
            if (direct) {
                await LddapApi.update(lddap.id, payload);
            } else {
                await LddapApi.requestUpdate(lddap.id, payload);
                setPendingRequest(true);
            }
            setRequesting(false);
            setReason('');
            void loadHistory();
            onChanged();
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
            onClick={() => !reqBusy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="lddap-detail-title"
        >
            <div
                className="card flex max-h-[92vh] w-full max-w-xl flex-col overflow-y-auto p-6"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">LDDAP-ADA</span>
                        <h2
                            id="lddap-detail-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            {lddap.lddap_no}
                        </h2>
                        <p className="mt-1 flex items-center gap-1.5 text-sm text-muted">
                            <Hash className="h-3.5 w-3.5 text-subtle" />
                            {lddap.check_no != null
                                ? `Check ${lddap.check_no}`
                                : lddap.status === 'approved'
                                  ? 'Check number assigned when put on an ACIC'
                                  : 'No check number yet — assigned with the ACIC after approval'}
                        </p>
                    </div>
                    <button
                        className="btn btn-ghost !px-2"
                        onClick={onClose}
                        disabled={reqBusy}
                        aria-label="Close"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {pendingRequest && (
                    <div className="mb-4">
                        <Alert kind="error">
                            On hold — a correction is awaiting admin approval. This record cannot be
                            received or reviewed until that is resolved.
                        </Alert>
                    </div>
                )}

                {/* Where the record is in its routing, and the last note left on it. */}
                {lddap.status === 'rts' && latestRts && (
                    <div className="mb-4 rounded-xs border border-amber-400/50 bg-amber-400/10 p-3">
                        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-amber-400">
                            <Undo2 className="h-3.5 w-3.5" />
                            Returned to sender
                        </div>
                        <p className="mt-1.5 text-sm text-fg">{latestRts.note}</p>
                        <p className="mt-1.5 text-xs text-subtle">
                            {latestRts.user?.name ?? '—'} · {latestRts.unit_name ?? '—'} ·{' '}
                            {formatDate(latestRts.acted_on)} — correct the details, then forward it again.
                        </p>
                    </div>
                )}

                {(lddap.status === 'for_out' || lddap.status === 'returned_for_acic') && (
                    <div
                        className={`mb-4 rounded-xs border p-3 ${
                            lddap.status === 'returned_for_acic'
                                ? 'border-accent-400/50 bg-accent-400/10'
                                : 'border-brand-400/40 bg-brand-500/10'
                        }`}
                    >
                        <div
                            className={`flex items-center gap-2 text-xs font-semibold uppercase tracking-wider ${
                                lddap.status === 'returned_for_acic' ? 'text-accent-400' : 'text-brandink'
                            }`}
                        >
                            <AlertTriangle className="h-3.5 w-3.5" />
                            {lddap.status_label}
                        </div>
                        <p className="mt-1.5 text-sm text-fg">
                            {lddap.status === 'for_out'
                                ? `Forwarded to ${lddap.forward_to ?? '—'}${lddap.forward_unit_name ? ` (${lddap.forward_unit_name})` : ''} by ${lddap.forwarded_by?.name ?? '—'} on ${formatDate(lddap.date_forwarded)}.`
                                : `Received back${lddap.return_unit_name ? ` from ${lddap.return_unit_name}` : ''} by ${lddap.returned_by?.name ?? '—'} on ${formatDate(lddap.date_returned)} — awaiting the admin's action.`}
                        </p>
                    </div>
                )}

                {/* Cancellation details — only on a canceled record. */}
                {lddap.status === 'canceled' && (
                    <section className="mb-4 rounded-xs border border-danger/40 bg-danger/10 p-3">
                        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-danger-fg">
                            <Ban className="h-3.5 w-3.5" />
                            Cancellation Details
                        </div>
                        <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                            <dt className="text-xs uppercase tracking-wider text-subtle">Canceled By</dt>
                            <dd className="text-fg">{lddap.canceled_by?.name ?? lddap.reviewed_by?.name ?? '—'}</dd>
                            <dt className="text-xs uppercase tracking-wider text-subtle">Date Canceled</dt>
                            <dd className="text-fg">{formatDate(lddap.date_canceled ?? lddap.reviewed_at)}</dd>
                            <dt className="text-xs uppercase tracking-wider text-subtle">Reason</dt>
                            <dd className="whitespace-pre-wrap text-fg">
                                {lddap.cancel_reason ?? lddap.review_note ?? 'No reason was recorded.'}
                            </dd>
                        </dl>
                        <p className="mt-2 text-xs text-subtle">
                            This record is closed. It cannot be edited, forwarded, returned or assigned to an ACIC,
                            and its LDDAP number stays used.
                        </p>
                    </section>
                )}

                <section>
                    <Row
                        label="Status"
                        value={
                            <span className="inline-flex flex-wrap items-center justify-end gap-1.5">
                                <LddapStatusBadge status={lddap.status} />
                                {rtsEntries.length > 0 && (
                                    <span
                                        className="inline-flex items-center rounded-xs border border-amber-400/50 bg-amber-400/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-400"
                                        title={`Returned to sender ${rtsEntries.length} time${rtsEntries.length === 1 ? '' : 's'}`}
                                    >
                                        RTS: {rtsEntries.length}
                                    </span>
                                )}
                            </span>
                        }
                    />
                </section>

                {/* Everything it was registered with — details, payee, W/TAX, VAT, deductions, net. */}
                <div className="mt-4">
                    <LddapRecordDetails lddap={lddap} />
                </div>

                {/* Where it has been since. */}
                <section className="mt-6">
                    <h3 className="mb-2 font-display text-sm font-semibold uppercase tracking-widest text-subtle">
                        Routing
                    </h3>
                    {lddap.received_at && (
                        <Row
                            label="Received by"
                            value={`${lddap.received_by?.name ?? '—'} · ${formatDateTime(lddap.received_at)}`}
                        />
                    )}
                    {mode !== 'action' && lddap.reviewed_at && (
                        <Row
                            label="Reviewed by"
                            value={`${lddap.reviewed_by?.name ?? '—'} · ${formatDateTime(lddap.reviewed_at)}`}
                        />
                    )}
                    {mode !== 'action' && lddap.review_note && (
                        <Row label="Review note" value={lddap.review_note} />
                    )}
                    <Row label="ACIC No." value={lddap.acic_number ? `#${lddap.acic_number}` : '—'} />
                    {lddap.forwarded_at && (
                        <Row
                            label="Forwarded to"
                            value={`${lddap.forwarded_to ?? '—'} · ${formatDateTime(lddap.forwarded_at)}`}
                        />
                    )}
                </section>

                {isTeller && lddap.check_no != null && !lddap.received_at && !pendingRequest && (
                    <section className="mt-6 border-t border-line pt-5">
                        <p className="mb-3 text-sm text-muted">
                            Confirm this LDDAP has been received. This records you and the time against it.
                        </p>
                        {receiptError && (
                            <div className="mb-3">
                                <Alert kind="error">{receiptError}</Alert>
                            </div>
                        )}
                        <button
                            className="btn btn-primary w-full"
                            onClick={() => void handleConfirmReceipt()}
                            disabled={receiving}
                        >
                            <CheckCircle2 className="h-4 w-4" />
                            {receiving ? 'Saving…' : 'Confirm receipt'}
                        </button>
                    </section>
                )}

                {/* Every return to sender, newest first. Absent when the record was never returned. */}
                {rtsEntries.length > 0 && (
                    <section className="mt-6">
                        <h3 className="mb-3 flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-widest text-amber-400">
                            <Undo2 className="h-4 w-4" />
                            RTS History ({rtsEntries.length})
                        </h3>
                        <ol className="space-y-2">
                            {rtsEntries.map((entry, i) => (
                                <li key={entry.id} className="rounded-xs border border-amber-400/30 bg-well p-3">
                                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-display text-xs font-semibold uppercase tracking-wider text-amber-400">
                                            RTS #{rtsEntries.length - i}
                                        </span>
                                        <span className="text-xs text-subtle">by {entry.user?.name ?? '—'}</span>
                                    </div>
                                    <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                        <dt className="uppercase tracking-wider text-subtle">Date Received</dt>
                                        <dd className="text-fg">{formatDate(entry.received_on)}</dd>
                                        <dt className="uppercase tracking-wider text-subtle">Received By</dt>
                                        <dd className="text-fg">{entry.received_by_name ?? '—'}</dd>
                                        <dt className="uppercase tracking-wider text-subtle">RTS Unit</dt>
                                        <dd className="text-fg">{entry.unit_name ?? '—'}</dd>
                                        <dt className="uppercase tracking-wider text-subtle">RTS Date</dt>
                                        <dd className="text-fg">{formatDate(entry.acted_on)}</dd>
                                        <dt className="uppercase tracking-wider text-subtle">Comment</dt>
                                        <dd className="whitespace-pre-wrap text-fg">{entry.note ?? '—'}</dd>
                                    </dl>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                {routing.length > 0 && (
                    <section className="mt-6">
                        <h3 className="mb-3 flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-widest text-subtle">
                            <Route className="h-4 w-4" />
                            Routing trail
                        </h3>
                        <ol className="space-y-2">
                            {routing.map((step) => (
                                <li key={step.id} className="rounded-xs border border-line bg-well p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="flex items-center gap-2">
                                            <span className="font-display text-sm font-semibold text-fg">
                                                {step.action_label}
                                            </span>
                                            <LddapStatusBadge status={step.to_status} />
                                        </span>
                                        <span className="text-xs text-subtle">
                                            {step.user?.name ?? '—'} · {formatDate(step.acted_on ?? step.created_at)}
                                        </span>
                                    </div>
                                    {(step.counterparty || step.unit_name) && (
                                        <p className="mt-1.5 text-xs text-muted">
                                            {step.action === 'forwarded' ? 'To' : 'From'}{' '}
                                            {[step.counterparty, step.unit_name].filter(Boolean).join(' · ')}
                                        </p>
                                    )}
                                    {step.note && <p className="mt-1.5 text-sm text-fg">{step.note}</p>}
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                {history.length > 0 && (
                    <section className="mt-6">
                        <h3 className="mb-3 flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-widest text-subtle">
                            <History className="h-4 w-4" />
                            Correction history
                        </h3>
                        <ul className="space-y-2">
                            {history.map((r) => {
                                const badge = REQUEST_STATUS[r.status];
                                const Icon = badge.icon;
                                return (
                                    <li key={r.id} className="rounded-xs border border-line bg-well p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span
                                                className={`inline-flex items-center gap-1.5 rounded-xs border px-2 py-0.5 text-xs font-medium ${badge.styles}`}
                                            >
                                                <Icon className="h-3 w-3" />
                                                {badge.label}
                                            </span>
                                            <span className="flex items-center gap-1.5 text-xs text-subtle">
                                                {r.applied_directly && (
                                                    <span
                                                        className="inline-flex items-center gap-1 rounded-xs border border-brand-400/40 bg-brand-500/10 px-1.5 py-0.5 text-brandink"
                                                        title="Applied directly by an admin"
                                                    >
                                                        <Zap className="h-3 w-3" />
                                                        Direct
                                                    </span>
                                                )}
                                                {r.requested_by?.name ?? '—'} · {formatDateTime(r.created_at)}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm text-muted">{r.reason}</p>
                                        <p className="mt-1 text-xs text-subtle">
                                            Proposed: {r.proposed_lddap_no} · OBJ {r.proposed_obj_no ?? '—'} ·{' '}
                                            {r.proposed_payee_name ?? '—'} ·{' '}
                                            {formatMoney(r.proposed_amount)}
                                        </p>
                                        {r.review_note && (
                                            <p className="mt-1 text-xs text-subtle">
                                                {r.reviewed_by?.name ?? 'Admin'}: {r.review_note}
                                            </p>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}

                {/* Every edit through "Edit LDDAP Record": who, when, and what changed. */}
                {edits.length > 0 && (
                    <section className="mt-6">
                        <h3 className="mb-3 flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-widest text-subtle">
                            <PencilLine className="h-4 w-4" />
                            Edit history
                        </h3>
                        <ol className="space-y-2">
                            {edits.map((edit) => (
                                <li key={edit.id} className="rounded-xs border border-line bg-well p-3 text-sm">
                                    <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                        <span className="font-medium text-fg">{edit.user?.name ?? '—'}</span>
                                        <span className="text-xs text-subtle">{formatDateTime(edit.created_at)}</span>
                                    </div>
                                    <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                        {Object.entries(edit.changes).map(([field, change]) => (
                                            <div key={field} className="contents">
                                                <dt className="uppercase tracking-wider text-subtle">{field.replaceAll('_', ' ')}</dt>
                                                <dd className="min-w-0 break-words text-muted">
                                                    <span className="line-through">{change.from ?? '—'}</span>
                                                    {' → '}
                                                    <span className="text-fg">{change.to ?? '—'}</span>
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                {canOpenEdit && !pendingRequest && (
                    <section className="mt-6 border-t border-line pt-5">
                        <p className="mb-3 text-sm text-muted">
                            Open the record in the register form to change any of its details. Every change is
                            kept with who made it and when. The check number is never part of it.
                        </p>
                        <button className="btn btn-outline w-full" onClick={() => setEditing(true)}>
                            <PencilLine className="h-4 w-4" />
                            Edit
                        </button>
                    </section>
                )}

                {canCorrect && !pendingRequest && (
                    <section className="mt-6 border-t border-line pt-5">
                        {requesting ? (
                            <form onSubmit={handleRequestUpdate} className="space-y-3">
                                <p className="text-sm text-muted">
                                    {direct
                                        ? 'This takes effect immediately. The check number is assigned by the series and cannot be changed.'
                                        : 'The check number is assigned by the series and cannot be changed.'}
                                </p>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label htmlFor="req-lddap-no" className="label">
                                            LDDAP No.
                                        </label>
                                        <input
                                            id="req-lddap-no"
                                            className="field"
                                            value={lddapNo}
                                            onChange={(e) => setLddapNo(e.target.value)}
                                            maxLength={100}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label htmlFor="req-obj-no" className="label">
                                            OBJ No.
                                        </label>
                                        <input
                                            id="req-obj-no"
                                            className="field"
                                            value={objNo}
                                            onChange={(e) => setObjNo(e.target.value)}
                                            maxLength={100}
                                            placeholder="Optional"
                                        />
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label htmlFor="req-payee" className="label">
                                            Payee
                                        </label>
                                        <input
                                            id="req-payee"
                                            className="field"
                                            value={payee}
                                            onChange={(e) => setPayee(e.target.value)}
                                            maxLength={255}
                                            placeholder="Optional"
                                        />
                                    </div>
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
                                </div>
                                <div>
                                    <label htmlFor="req-reason" className="label">
                                        Reason for the change
                                    </label>
                                    <textarea
                                        id="req-reason"
                                        className="field min-h-20"
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        placeholder={
                                            direct
                                                ? 'Required — say why you are changing these details…'
                                                : 'Explain what is wrong and why it must change…'
                                        }
                                        required
                                        minLength={5}
                                        maxLength={1000}
                                    />
                                </div>
                                {reqError && <Alert kind="error">{reqError}</Alert>}
                                <div className="flex gap-2">
                                    <button type="submit" className="btn btn-primary flex-1" disabled={reqBusy}>
                                        {reqBusy
                                            ? direct
                                                ? 'Saving…'
                                                : 'Submitting…'
                                            : direct
                                              ? 'Save changes'
                                              : 'Submit request'}
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
                                    {direct
                                        ? 'Correct the details yourself — the change takes effect immediately, and you must record why.'
                                        : 'Spotted a mistake in the details above? Propose a correction — an admin will review and apply it.'}
                                </p>
                                <button className="btn btn-outline w-full" onClick={() => setRequesting(true)}>
                                    <PencilLine className="h-4 w-4" />
                                    {direct ? 'Correct details' : 'Request an update'}
                                </button>
                            </>
                        )}
                    </section>
                )}
            </div>

            {/* The edit dialog sits on top; its overlay clicks must not fall through to this one. */}
            {editing && (
                <div onClick={(e) => e.stopPropagation()}>
                    <LddapRegisterModal
                        lddap={lddap}
                        onClose={() => setEditing(false)}
                        onSaved={() => {
                            setEditing(false);
                            onChanged();
                            onClose();
                        }}
                    />
                </div>
            )}
        </div>
    );
}
