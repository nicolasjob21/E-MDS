import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { X, Send, Inbox, ShieldCheck, Undo2, Ban, ChevronDown } from 'lucide-react';
import { LddapApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Lddap, LddapOptions } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, Spinner, LddapStatusBadge } from './ui';
import LddapRecordDetails from './LddapRecordDetails';

/** Which step of the routing the dialog is taking. `rts` and `cancel` are reached from `action`. */
export type LddapStep = 'forward' | 'receive' | 'action' | 'rts' | 'cancel';

interface Props {
    lddap: Lddap;
    step: LddapStep;
    onClose: () => void;
    onDone: (lddap: Lddap, what: string) => void;
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function Field({ label, htmlFor, optional = false, children }: { label: string; htmlFor: string; optional?: boolean; children: ReactNode }) {
    return (
        <div>
            <label htmlFor={htmlFor} className="label !mb-1">
                {label}
                {optional && <span className="ml-1 normal-case tracking-normal text-subtle">(optional)</span>}
            </label>
            {children}
        </div>
    );
}

/**
 * One dialog for the three routing steps, each with its own fields:
 *
 *  - **Forward** (Registered → For Out): Forward To, Unit, Date Forwarded, Note. Forwarded By
 *    is the signed-in user, shown read-only.
 *  - **Receive** (For Out → Returned for ACIC): From Unit, Date Received, Note. Received By is
 *    the signed-in user.
 *  - **Action** (on Returned for ACIC): Approve, RTS or Cancel, with a note — required for
 *    Cancel, which closes the record for good.
 *
 * Only the step the record's status allows is ever opened, so the dialog never offers a move
 * the server would refuse.
 */
export default function LddapStepModal({ lddap, step: initialStep, onClose, onDone }: Props) {
    const { user } = useAuth();
    // The Action dialog hands over to the RTS form when RTS is chosen.
    const [step, setStep] = useState<LddapStep>(initialStep);
    const [options, setOptions] = useState<LddapOptions | null>(null);
    const [forwardTo, setForwardTo] = useState('');
    const [unitId, setUnitId] = useState('');
    const [date, setDate] = useState(today());
    const [note, setNote] = useState('');
    // RTS only: who received the record and when, before it is sent back.
    const [receivedBy, setReceivedBy] = useState(lddap.returned_by?.name ?? user?.name ?? '');
    const [receivedOn, setReceivedOn] = useState(lddap.date_returned ?? today());
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    // Action only: the essentials by default, the whole registered record on request.
    const [showAll, setShowAll] = useState(false);

    // Forward, Receive and RTS all pick a unit; the Action and Cancel dialogs do not.
    const needsUnits = step !== 'action' && step !== 'cancel';

    const load = useCallback(async () => {
        if (!needsUnits) return;
        try {
            setOptions(await LddapApi.options());
        } catch (err) {
            setError(toApiError(err).message);
        }
    }, [needsUnits]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    async function run(label: string, call: () => Promise<Lddap>) {
        setBusy(label);
        setError('');
        try {
            onDone(await call(), label);
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(null);
        }
    }

    function handleForward(e: FormEvent) {
        e.preventDefault();
        void run('Forwarded', () =>
            LddapApi.forward(lddap.id, {
                forward_to: forwardTo.trim(),
                unit_id: Number(unitId),
                date_forwarded: date,
                note,
            }),
        );
    }

    function handleReceive(e: FormEvent) {
        e.preventDefault();
        void run('Received', () =>
            LddapApi.receiveBack(lddap.id, { unit_id: Number(unitId), date_received: date, note }),
        );
    }

    function handleApprove() {
        void run('Approved', () => LddapApi.approve(lddap.id, note));
    }

    function handleCancel(e: FormEvent) {
        e.preventDefault();
        if (note.trim() === '') {
            setError('Give the reason for canceling this record.');
            return;
        }
        void run('Canceled', () => LddapApi.cancel(lddap.id, { date_canceled: date, note }));
    }

    function handleRts(e: FormEvent) {
        e.preventDefault();
        if (note.trim() === '') {
            setError('Say why the record is being returned to sender.');
            return;
        }
        void run('Returned to sender', () =>
            LddapApi.rts(lddap.id, {
                received_on: receivedOn,
                received_by: receivedBy.trim(),
                unit_id: Number(unitId),
                rts_date: date,
                note,
            }),
        );
    }

    const title = { forward: 'Forward', receive: 'Receive', action: 'Action', rts: 'RTS', cancel: 'Cancel' }[step];
    const eyebrow = {
        forward: lddap.status === 'rts' ? 'RTS → For Out' : 'Registered → For Out',
        receive: 'For Out → Returned for ACIC',
        action: 'Returned for ACIC',
        rts: 'Returned for ACIC → RTS',
        cancel: 'Returned for ACIC → Canceled',
    }[step];

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="lddap-step-title"
        >
            {/* The Action dialog carries the whole record, so it is wider and scrolls. */}
            <div
                className={`card flex max-h-[92vh] w-full flex-col p-6 ${
                    step === 'action' ? 'max-w-2xl' : 'max-w-md overflow-y-auto'
                }`}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <span className="eyebrow">{eyebrow}</span>
                        <h2 id="lddap-step-title" className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg">
                            {title}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={!!busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {step === 'action' ? (
                    /* What is being signed off: every registered detail, then the payment. */
                    <div className="mb-5 min-h-0 flex-1 overflow-y-auto pr-1">
                        <div className="mb-3 flex items-center justify-between gap-4 px-1">
                            <span className="text-xs font-semibold uppercase tracking-wider text-subtle">Status</span>
                            <span className="inline-flex items-center gap-2">
                                <LddapStatusBadge status={lddap.status} />
                                {lddap.check_no != null && (
                                    <span className="font-display text-sm font-bold text-fg">Check #{lddap.check_no}</span>
                                )}
                            </span>
                        </div>
                        <LddapRecordDetails lddap={lddap} compact={!showAll} />
                        <button
                            type="button"
                            className="mt-3 inline-flex items-center gap-1 text-xs font-medium text-brandink hover:underline"
                            onClick={() => setShowAll((v) => !v)}
                            aria-expanded={showAll}
                        >
                            <ChevronDown className={`h-3.5 w-3.5 transition-transform ${showAll ? 'rotate-180' : ''}`} />
                            {showAll ? 'Show the essentials only' : 'Show every registered detail'}
                        </button>
                    </div>
                ) : (
                    /* The record being moved. */
                    <div className="mb-5 rounded-xs border border-line bg-well p-3 text-sm">
                        <div className="flex justify-between gap-4">
                            <span className="text-subtle">LDDAP No.</span>
                            <span className="text-right font-display font-bold text-fg">{lddap.lddap_no}</span>
                        </div>
                        <div className="mt-1.5 flex justify-between gap-4">
                            <span className="text-subtle">Payee</span>
                            <span className="text-right text-fg">{lddap.payee_name ?? '—'}</span>
                        </div>
                        <div className="mt-1.5 flex justify-between gap-4">
                            <span className="text-subtle">Amount</span>
                            <span className="text-right font-mono text-fg">{formatMoney(lddap.amount)}</span>
                        </div>
                        <div className="mt-1.5 flex justify-between gap-4">
                            <span className="text-subtle">Status</span>
                            <LddapStatusBadge status={lddap.status} />
                        </div>
                    </div>
                )}

                {needsUnits && options === null ? (
                    <Spinner />
                ) : step === 'forward' ? (
                    <form onSubmit={handleForward} className="space-y-3">
                        <Field label="Forward To" htmlFor="step-to">
                            <input
                                id="step-to"
                                className="field !py-1.5"
                                value={forwardTo}
                                onChange={(e) => setForwardTo(e.target.value)}
                                placeholder="Office or person"
                                maxLength={255}
                                autoFocus
                                required
                            />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Unit Name" htmlFor="step-unit">
                                <select id="step-unit" className="field !py-1.5" value={unitId} onChange={(e) => setUnitId(e.target.value)} required>
                                    <option value="">Choose…</option>
                                    {options?.units.map((u) => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Date Forwarded" htmlFor="step-date">
                                <input id="step-date" type="date" className="field !py-1.5" value={date} onChange={(e) => setDate(e.target.value)} required />
                            </Field>
                        </div>
                        <Field label="Forwarded By" htmlFor="step-by">
                            <input id="step-by" className="field !py-1.5" value={user?.name ?? ''} disabled readOnly />
                        </Field>
                        <Field label="Note" htmlFor="step-note" optional>
                            <textarea id="step-note" className="field min-h-20 !py-1.5" value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
                        </Field>
                        {error && <Alert kind="error">{error}</Alert>}
                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={!!busy}>Cancel</button>
                            <button type="submit" className="btn btn-primary" disabled={!!busy}>
                                <Send className="h-4 w-4" />
                                {busy ? 'Forwarding…' : 'Forward'}
                            </button>
                        </div>
                    </form>
                ) : step === 'receive' ? (
                    <form onSubmit={handleReceive} className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="From Unit Name" htmlFor="step-unit">
                                <select id="step-unit" className="field !py-1.5" value={unitId} onChange={(e) => setUnitId(e.target.value)} required autoFocus>
                                    <option value="">Choose…</option>
                                    {options?.units.map((u) => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Date Received" htmlFor="step-date">
                                <input id="step-date" type="date" className="field !py-1.5" value={date} onChange={(e) => setDate(e.target.value)} required />
                            </Field>
                        </div>
                        <Field label="Received By" htmlFor="step-by">
                            <input id="step-by" className="field !py-1.5" value={user?.name ?? ''} disabled readOnly />
                        </Field>
                        <Field label="Note" htmlFor="step-note" optional>
                            <textarea id="step-note" className="field min-h-20 !py-1.5" value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
                        </Field>
                        {error && <Alert kind="error">{error}</Alert>}
                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={!!busy}>Cancel</button>
                            <button type="submit" className="btn btn-primary" disabled={!!busy}>
                                <Inbox className="h-4 w-4" />
                                {busy ? 'Receiving…' : 'Receive'}
                            </button>
                        </div>
                    </form>
                ) : step === 'cancel' ? (
                    <form onSubmit={handleCancel} className="space-y-3">
                        <div className="rounded-xs border border-danger/40 bg-danger/10 p-3 text-sm text-danger-fg">
                            This closes the record for good. It can no longer be edited, forwarded, returned or
                            assigned to an ACIC, and its LDDAP number stays used.
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Canceled By" htmlFor="cancel-by">
                                <input id="cancel-by" className="field !py-1.5" value={user?.name ?? ''} disabled readOnly />
                            </Field>
                            <Field label="Date Canceled" htmlFor="cancel-date">
                                <input id="cancel-date" type="date" className="field !py-1.5" value={date} onChange={(e) => setDate(e.target.value)} required />
                            </Field>
                        </div>
                        <Field label="Reason" htmlFor="cancel-reason">
                            <textarea
                                id="cancel-reason"
                                className="field min-h-24 !py-1.5"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="Why this record is being canceled"
                                minLength={3}
                                maxLength={2000}
                                required
                                autoFocus
                            />
                        </Field>
                        {error && <Alert kind="error">{error}</Alert>}
                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                className="btn btn-ghost"
                                onClick={() => (initialStep === 'action' ? setStep('action') : onClose())}
                                disabled={!!busy}
                            >
                                Back
                            </button>
                            <button
                                type="submit"
                                className="btn btn-primary !bg-danger hover:!bg-danger/80"
                                disabled={!!busy}
                            >
                                <Ban className="h-4 w-4" />
                                {busy ? 'Canceling…' : 'Cancel this record'}
                            </button>
                        </div>
                    </form>
                ) : step === 'rts' ? (
                    <form onSubmit={handleRts} className="space-y-3">
                        <p className="text-sm text-muted">
                            Send the record back to be corrected. Every return is kept on the record, so say what
                            has to change.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Date Received" htmlFor="rts-received-on">
                                <input id="rts-received-on" type="date" className="field !py-1.5" value={receivedOn} onChange={(e) => setReceivedOn(e.target.value)} required />
                            </Field>
                            <Field label="Received By" htmlFor="rts-received-by">
                                <input id="rts-received-by" className="field !py-1.5" value={receivedBy} onChange={(e) => setReceivedBy(e.target.value)} maxLength={255} required />
                            </Field>
                            <Field label="RTS Unit" htmlFor="rts-unit">
                                <select id="rts-unit" className="field !py-1.5" value={unitId} onChange={(e) => setUnitId(e.target.value)} required autoFocus>
                                    <option value="">Choose…</option>
                                    {options?.units.map((u) => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="RTS Date" htmlFor="rts-date">
                                <input id="rts-date" type="date" className="field !py-1.5" value={date} onChange={(e) => setDate(e.target.value)} required />
                            </Field>
                        </div>
                        <Field label="Comment" htmlFor="rts-note">
                            <textarea
                                id="rts-note"
                                className="field min-h-24 !py-1.5"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="What has to be corrected before it is forwarded again"
                                minLength={3}
                                maxLength={2000}
                                required
                            />
                        </Field>
                        {error && <Alert kind="error">{error}</Alert>}
                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                className="btn btn-ghost"
                                onClick={() => (initialStep === 'action' ? setStep('action') : onClose())}
                                disabled={!!busy}
                            >
                                Back
                            </button>
                            <button type="submit" className="btn btn-primary" disabled={!!busy}>
                                <Undo2 className="h-4 w-4" />
                                {busy ? 'Returning…' : 'Return to sender'}
                            </button>
                        </div>
                    </form>
                ) : (
                    <div className="space-y-3">
                        <p className="text-sm text-muted">
                            <span className="font-semibold text-fg">Approve</span> makes it available to Assign LDDAP
                            to ACIC. <span className="font-semibold text-fg">RTS</span> returns it to sender to be
                            corrected and forwarded again — it opens its own form.{' '}
                            <span className="font-semibold text-fg">Cancel</span> closes it for good — it opens a
                            confirmation with the reason.
                        </p>
                        <Field label="Note" htmlFor="step-note" optional>
                            <textarea
                                id="step-note"
                                className="field min-h-20 !py-1.5"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                maxLength={2000}
                                placeholder="Optional note on the approval"
                                autoFocus
                            />
                        </Field>
                        {error && <Alert kind="error">{error}</Alert>}
                        <div className="grid gap-2 sm:grid-cols-3">
                            <button type="button" className="btn btn-primary" onClick={handleApprove} disabled={!!busy}>
                                <ShieldCheck className="h-4 w-4" />
                                {busy === 'Approved' ? 'Approving…' : 'Approved'}
                            </button>
                            <button
                                type="button"
                                className="btn btn-outline"
                                onClick={() => {
                                    setError('');
                                    setStep('rts');
                                }}
                                disabled={!!busy}
                            >
                                <Undo2 className="h-4 w-4" />
                                RTS
                            </button>
                            <button
                                type="button"
                                className="btn btn-outline !border-danger/50 !text-danger-fg hover:!bg-danger hover:!text-white"
                                onClick={() => {
                                    setError('');
                                    setStep('cancel');
                                }}
                                disabled={!!busy}
                            >
                                <Ban className="h-4 w-4" />
                                Cancel
                            </button>
                        </div>
                        <button type="button" className="btn btn-ghost w-full" onClick={onClose} disabled={!!busy}>
                            Close
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
