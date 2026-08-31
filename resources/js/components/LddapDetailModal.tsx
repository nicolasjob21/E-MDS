import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { X, PencilLine, Clock, History, Check, Ban, Hash, AlertTriangle, Zap, CheckCircle2 } from 'lucide-react';
import { LddapUpdateRequestApi, LddapApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Lddap, LddapUpdateRequest, RequestStatus } from '../lib/types';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';
import { Alert, LddapStatusBadge } from './ui';

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

    // Staff propose a correction for approval; an admin applies one immediately. Same fields,
    // same mandatory reason — only who it has to pass through differs. A returned record is the
    // exception: it went back to the staff member who used the number, so only they get the form.
    const ownsLddap = lddap.used_by?.id === user?.id;
    const canEdit = isAdmin || (isStaff && (!lddap.awaits_compliance || ownsLddap));
    const direct = isAdmin;

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
    // Teller receipt confirmation lives here now, as it does for cheques.
    const [receiving, setReceiving] = useState(false);
    const [receiptError, setReceiptError] = useState('');

    // Loading the history also re-derives the true hold state, so the form is never offered on
    // a record that already has a request awaiting approval.
    const loadHistory = useCallback(async () => {
        try {
            const list = await LddapUpdateRequestApi.forLddap(lddap.id);
            setHistory(list);
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
            if (e.key === 'Escape' && !reqBusy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, reqBusy]);

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
                className="card flex max-h-[92vh] w-full max-w-lg flex-col overflow-y-auto p-6"
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
                            Check {lddap.check_no}
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

                {lddap.awaits_compliance && (
                    <div className="mb-4 rounded-xs border border-accent-400/50 bg-accent-400/10 p-3">
                        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-accent-400">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            Returned
                        </div>
                        <p className="mt-1.5 text-sm text-fg">
                            {lddap.review_note ?? 'No remark was recorded.'}
                        </p>
                        <p className="mt-1.5 text-xs text-subtle">
                            {lddap.reviewed_by?.name ? `${lddap.reviewed_by.name} · ` : ''}
                            {formatDateTime(lddap.reviewed_at)}
                            {isStaff && ownsLddap
                                ? ' — edit the details below to address this. An admin has to approve the change, and the record stays Returned until they do.'
                                : ''}
                        </p>
                        {!ownsLddap && (
                            <p className="mt-1.5 text-xs text-subtle">
                                Returned to {lddap.used_by?.name ?? 'the staff member who used it'} —
                                it is theirs to update.
                            </p>
                        )}
                    </div>
                )}

                <section>
                    <Row
                        label="Status"
                        value={
                            <LddapStatusBadge
                                status={lddap.status}
                                complied={pendingRequest}
                                viewer={user?.role}
                            />
                        }
                    />
                    <Row label="LDDAP No." value={lddap.lddap_no} />
                    <Row label="OBJ No." value={lddap.obj_no ?? '—'} />
                    <Row label="Payee" value={lddap.payee_name ?? '—'} />
                    <Row
                        label="Amount"
                        value={<span className="font-mono">{formatMoney(lddap.amount)}</span>}
                    />
                    <Row label="Check date" value={formatDate(lddap.check_date)} />
                    <Row label="Used by" value={lddap.used_by?.name ?? '—'} />
                    <Row label="Used at" value={formatDateTime(lddap.used_at)} />
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

                {isTeller && lddap.status === 'used' && !pendingRequest && (
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

                {canEdit && !pendingRequest && (
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
                                        : lddap.awaits_compliance
                                          ? 'Update the details to address the remark above. An admin reviews and approves the change — approving it is the sign-off.'
                                          : 'Spotted a mistake in the details above? Propose a correction — an admin will review and apply it.'}
                                </p>
                                <button
                                    className={`btn w-full ${lddap.awaits_compliance && isStaff ? 'btn-primary' : 'btn-outline'}`}
                                    onClick={() => setRequesting(true)}
                                >
                                    <PencilLine className="h-4 w-4" />
                                    {direct
                                        ? 'Edit details'
                                        : lddap.awaits_compliance
                                          ? 'Edit Details'
                                          : 'Request an update'}
                                </button>
                            </>
                        )}
                    </section>
                )}
            </div>
        </div>
    );
}
