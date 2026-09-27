import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { X, Undo2, Ban, RotateCcw } from 'lucide-react';
import { LddapApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Lddap } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, LddapStatusBadge } from './ui';
import UnitSelect from './UnitSelect';

/** Which step the dialog is taking: RTS or Cancel on a For Signature record, Resubmit on an RTS one. */
export type LddapStep = 'rts' | 'cancel' | 'resubmit';

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
 * One dialog for the steps an LDDAP takes before an ACIC, each with its own fields:
 *
 *  - **RTS** (For Signature → RTS, admin): Date Received, Received By, RTS Unit, RTS Date and a
 *    required Comment.
 *  - **Cancel** (For Signature → Canceled, admin): Date Canceled and a required Reason.
 *  - **Resubmit** (RTS → For Signature, once corrected): a required Comment on what was
 *    corrected, and optional Notes.
 *
 * Only the step the record's status allows is ever opened, so the dialog never offers a move
 * the server would refuse.
 */
export default function LddapStepModal({ lddap, step, onClose, onDone }: Props) {
    const { user } = useAuth();
    const [unit, setUnit] = useState('');
    const [date, setDate] = useState(today());
    const [note, setNote] = useState('');
    // Resubmit only: optional notes beside the comment.
    const [notes, setNotes] = useState('');
    // RTS only: who received the record and when, before it is sent back.
    const [receivedBy, setReceivedBy] = useState(user?.name ?? '');
    const [receivedOn, setReceivedOn] = useState(today());
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');


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

    function handleResubmit(e: FormEvent) {
        e.preventDefault();
        if (note.trim().length < 3) {
            setError('Say what was corrected.');
            return;
        }
        void run('Resubmitted — now For Signature', () => LddapApi.resubmit(lddap.id, note.trim(), notes.trim()));
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
                unit_name: unit,
                rts_date: date,
                note,
            }),
        );
    }

    const title = { rts: 'RTS', cancel: 'Cancel', resubmit: 'Resubmit' }[step];
    const eyebrow = {
        rts: 'For Signature → RTS',
        cancel: 'For Signature → Canceled',
        resubmit: 'RTS → For Signature',
    }[step];

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="lddap-step-title"
        >
            <div className="card flex max-h-[92vh] w-full max-w-md flex-col overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
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

                {/* The record being moved. */}
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

                {step === 'resubmit' ? (
                    <form onSubmit={handleResubmit} className="space-y-3">
                        <p className="text-sm text-muted">
                            Send the corrected record back to <span className="font-semibold text-fg">For Signature</span>, ready to
                            be assigned to an ACIC. Both fields go on the record's history.
                        </p>
                        <Field label="Comment" htmlFor="resubmit-comment">
                            <textarea
                                id="resubmit-comment"
                                className="field min-h-24 !py-1.5"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="What was corrected"
                                minLength={3}
                                maxLength={2000}
                                required
                                autoFocus
                            />
                        </Field>
                        <Field label="Notes" htmlFor="resubmit-notes" optional>
                            <textarea
                                id="resubmit-notes"
                                className="field min-h-20 !py-1.5"
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                maxLength={2000}
                            />
                        </Field>
                        {error && <Alert kind="error">{error}</Alert>}
                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={!!busy}>Cancel</button>
                            <button type="submit" className="btn btn-primary" disabled={!!busy}>
                                <RotateCcw className="h-4 w-4" />
                                {busy ? 'Resubmitting…' : 'Resubmit'}
                            </button>
                        </div>
                    </form>
                ) : step === 'cancel' ? (
                    <form onSubmit={handleCancel} className="space-y-3">
                        <div className="rounded-xs border border-danger/40 bg-danger/10 p-3 text-sm text-danger-fg">
                            This closes the record for good. It can no longer be edited, returned, resubmitted or
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
                                onClick={onClose}
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
                                <UnitSelect id="rts-unit" className="field !py-1.5" value={unit} onChange={setUnit} required autoFocus />
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
                                placeholder="What has to be corrected before it is resubmitted"
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
                                onClick={onClose}
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
                ) : null}
            </div>
        </div>
    );
}
