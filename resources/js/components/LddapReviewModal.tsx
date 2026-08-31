import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { X, CheckCircle2, AlertTriangle, XCircle, PencilLine } from 'lucide-react';
import { LddapApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Lddap, LddapReviewOutcome } from '../lib/types';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';
import { Alert, LddapStatusBadge } from './ui';

/** One row of the details panel: label left, value right — as in `ChequeReviewModal`. */
function Field({ label, value, mono = false }: { label: string; value: ReactNode; mono?: boolean }) {
    return (
        <div className="mt-1.5 flex justify-between gap-4 first:mt-0">
            <span className="text-subtle">{label}</span>
            <span className={`text-right text-fg ${mono ? 'font-mono' : ''}`}>{value}</span>
        </div>
    );
}

interface Props {
    lddap: Lddap;
    onClose: () => void;
    onReviewed: (lddap: Lddap) => void;
    /**
     * An admin corrected the details from inside this dialog. The dialog stays open — the review
     * is still to be recorded — so the list refreshes behind it rather than closing.
     */
    onChanged?: () => void;
}

const OUTCOMES: {
    key: LddapReviewOutcome;
    label: string;
    hint: string;
    icon: typeof CheckCircle2;
    classes: string;
}[] = [
    {
        key: 'approved',
        label: 'Approved',
        hint: 'Signed off. Only approved records can go on an ACIC. This is final.',
        icon: CheckCircle2,
        classes: 'border-success/40 text-success-fg',
    },
    {
        key: 'compliance',
        label: 'Returned',
        hint: 'Send back to the staff member to fix. It can be reviewed again afterwards.',
        icon: AlertTriangle,
        classes: 'border-accent-400/50 text-accent-400',
    },
    {
        key: 'cancelled',
        label: 'Cancelled',
        hint: 'Rejected. This is final.',
        icon: XCircle,
        classes: 'border-danger/40 text-danger-fg',
    },
];

/**
 * Admin review of an LDDAP record. "Returned" requires a note, since it is the only
 * outcome that asks the staff member to do something.
 */
export default function LddapReviewModal({ lddap, onClose, onReviewed, onChanged }: Props) {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin';

    // The record as this dialog currently knows it. An admin correction below rewrites the
    // details in place, so the outcome is recorded against what is actually on screen.
    const [record, setRecord] = useState<Lddap>(lddap);

    const [outcome, setOutcome] = useState<LddapReviewOutcome | ''>('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    // Admin direct correction, the same PATCH the detail modal offers: it takes effect
    // immediately and the reason is mandatory, since nobody else approves it.
    const [editing, setEditing] = useState(false);
    const [lddapNo, setLddapNo] = useState(lddap.lddap_no);
    const [objNo, setObjNo] = useState(lddap.obj_no ?? '');
    const [payee, setPayee] = useState(lddap.payee_name ?? '');
    const [amount, setAmount] = useState(lddap.amount ? String(Number(lddap.amount)) : '');
    const [reason, setReason] = useState('');
    const [editBusy, setEditBusy] = useState(false);
    const [editError, setEditError] = useState('');

    const noteRequired = outcome === 'compliance';
    const anyBusy = busy || editBusy;

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !anyBusy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, anyBusy]);

    /**
     * Apply the corrected details straight away (`PATCH /lddaps/{lddap}`). The reason is
     * required and is written into the record's correction history flagged `applied_directly`,
     * so a direct edit is as accountable as a staff request. The check number is never editable:
     * it comes from the LDDAP series.
     */
    async function handleEdit(e: FormEvent) {
        e.preventDefault();
        setEditBusy(true);
        setEditError('');
        try {
            await LddapApi.update(record.id, {
                lddap_no: lddapNo.trim(),
                obj_no: objNo.trim(),
                payee_name: payee.trim(),
                amount: Number(amount),
                reason: reason.trim(),
            });
            // Only the corrected fields are merged in: the response carries the request record,
            // not a fully-loaded LDDAP, so the relations already on screen are left alone.
            setRecord((prev) => ({
                ...prev,
                lddap_no: lddapNo.trim(),
                obj_no: objNo.trim() || null,
                payee_name: payee.trim() || null,
                amount: String(Number(amount)),
            }));
            setReason('');
            setEditing(false);
            onChanged?.();
        } catch (err) {
            const apiErr = toApiError(err);
            setEditError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setEditBusy(false);
        }
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (!outcome) {
            setError('Choose a review outcome.');
            return;
        }
        if (noteRequired && note.trim() === '') {
            setError('Say what needs to be fixed before returning this to the staff member.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            onReviewed(await LddapApi.review(record.id, outcome, note.trim() || undefined));
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !anyBusy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="lddap-review-title"
        >
            <div
                className="card flex max-h-[92vh] w-full max-w-lg flex-col overflow-y-auto p-6"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <span className="eyebrow">Review</span>
                        <h2
                            id="lddap-review-title"
                            className="mt-2 truncate font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            {record.lddap_no}
                        </h2>
                    </div>
                    <button
                        className="btn btn-ghost !px-2"
                        onClick={onClose}
                        disabled={anyBusy}
                        aria-label="Close"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {/* What is being signed off — the same label/value panel the cheque review
                    dialog uses. It stays on screen while the details are edited, so the record
                    can be read and corrected side by side. */}
                <div className="mb-3 rounded-xs border border-line bg-well p-3 text-sm">
                    <Field label="Check No." value={record.check_no ?? '—'} mono />
                    <Field label="Amount" value={formatMoney(record.amount)} mono />
                    <Field label="OBJ No." value={record.obj_no ?? '—'} />
                    <Field label="Payee" value={record.payee_name ?? '—'} />
                    <Field label="Check date" value={formatDate(record.check_date)} />
                    <Field label="Used by" value={record.used_by?.name ?? '—'} />
                    <Field label="Used at" value={formatDateTime(record.used_at)} />
                    <Field
                        label="Status"
                        value={
                            <LddapStatusBadge
                                status={record.status}
                                complied={record.has_pending_update}
                                viewer="admin"
                            />
                        }
                    />
                </div>

                {isAdmin && !editing && (
                    <button
                        type="button"
                        className="btn btn-outline mb-5 w-full"
                        onClick={() => setEditing(true)}
                        disabled={anyBusy}
                    >
                        <PencilLine className="h-4 w-4" />
                        Edit details
                    </button>
                )}

                {editing && (
                    <form onSubmit={handleEdit} className="mb-5 rounded-xs border border-line bg-well p-4">
                        <h3 className="mb-2 flex items-center gap-2 font-display text-xs font-semibold uppercase tracking-widest text-brandink">
                            <PencilLine className="h-3.5 w-3.5" />
                            Correct the details
                        </h3>
                        <p className="mb-3 text-sm text-muted">
                            This takes effect immediately — no one else approves it — so the reason is
                            required and is kept in the record's correction history. The check number
                            comes from the series and cannot be changed.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label htmlFor="rev-lddap-no" className="label">
                                    LDDAP No.
                                </label>
                                <input
                                    id="rev-lddap-no"
                                    className="field"
                                    value={lddapNo}
                                    onChange={(e) => setLddapNo(e.target.value)}
                                    maxLength={100}
                                    required
                                />
                            </div>
                            <div>
                                <label htmlFor="rev-obj-no" className="label">
                                    OBJ No.
                                </label>
                                <input
                                    id="rev-obj-no"
                                    className="field"
                                    value={objNo}
                                    onChange={(e) => setObjNo(e.target.value)}
                                    maxLength={100}
                                    placeholder="Optional"
                                />
                            </div>
                            <div>
                                <label htmlFor="rev-payee" className="label">
                                    Payee
                                </label>
                                <input
                                    id="rev-payee"
                                    className="field"
                                    value={payee}
                                    onChange={(e) => setPayee(e.target.value)}
                                    maxLength={255}
                                    placeholder="Optional"
                                />
                            </div>
                            <div>
                                <label htmlFor="rev-amount" className="label">
                                    Amount
                                </label>
                                <input
                                    id="rev-amount"
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
                        <div className="mt-3">
                            <label htmlFor="rev-reason" className="label">
                                Reason for the change
                            </label>
                            <textarea
                                id="rev-reason"
                                className="field min-h-20"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder="Required — say why you are changing these details…"
                                required
                                minLength={5}
                                maxLength={1000}
                            />
                        </div>

                        {editError && (
                            <div className="mt-3">
                                <Alert kind="error">{editError}</Alert>
                            </div>
                        )}

                        <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                className="btn btn-ghost"
                                onClick={() => {
                                    setEditing(false);
                                    setEditError('');
                                    setReason('');
                                    setLddapNo(record.lddap_no);
                                    setObjNo(record.obj_no ?? '');
                                    setPayee(record.payee_name ?? '');
                                    setAmount(record.amount ? String(Number(record.amount)) : '');
                                }}
                                disabled={editBusy}
                            >
                                Cancel
                            </button>
                            <button type="submit" className="btn btn-primary" disabled={editBusy}>
                                {editBusy ? 'Saving…' : 'Save changes'}
                            </button>
                        </div>
                    </form>
                )}

                {/* Re-reviewing a returned record: show what was asked for. */}
                {record.awaits_compliance && record.review_note && (
                    <div className="mb-5 rounded-xs border border-accent-400/50 bg-accent-400/10 p-3">
                        <div className="text-xs font-semibold uppercase tracking-wider text-accent-400">
                            Previously returned
                        </div>
                        <p className="mt-1 text-sm text-fg">{record.review_note}</p>
                        {record.reviewed_by && (
                            <p className="mt-1 text-xs text-subtle">
                                {record.reviewed_by.name} · {formatDateTime(record.reviewed_at)}
                            </p>
                        )}
                    </div>
                )}

                <form onSubmit={handleSubmit}>
                    <fieldset>
                        <legend className="label mb-2">Outcome</legend>
                        <div className="space-y-2">
                            {OUTCOMES.map(({ key, label, hint, icon: Icon, classes }) => (
                                <label
                                    key={key}
                                    className={`flex cursor-pointer items-start gap-3 rounded-xs border p-3 transition-colors ${
                                        outcome === key ? classes : 'border-line hover:bg-well'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="outcome"
                                        value={key}
                                        checked={outcome === key}
                                        onChange={() => setOutcome(key)}
                                        className="mt-1"
                                    />
                                    <span>
                                        <span className="flex items-center gap-2 font-display text-sm font-semibold">
                                            <Icon className="h-4 w-4" />
                                            {label}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted">{hint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <div className="mt-4">
                        <label htmlFor="review-note" className="label">
                            Note {noteRequired ? '' : <span className="text-subtle">(optional)</span>}
                        </label>
                        <textarea
                            id="review-note"
                            className="field"
                            rows={3}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            maxLength={2000}
                            placeholder={
                                noteRequired ? 'What has to be fixed?' : 'Anything worth recording…'
                            }
                            required={noteRequired}
                        />
                    </div>

                    {error && (
                        <div className="mt-4">
                            <Alert kind="error">{error}</Alert>
                        </div>
                    )}

                    <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy || !outcome}>
                            {busy ? 'Saving…' : 'Record review'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
