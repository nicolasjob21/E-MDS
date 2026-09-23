import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { X, Send, Inbox, HandCoins, Undo2, Ban, Slash } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Cheque } from '../lib/types';
import { formatDate, formatMoney } from '../lib/format';
import { Alert, StatusBadge, ValidityBadge } from './ui';

/** Which step of the flow the dialog is taking. */
export type ChequeStep = 'route' | 'receive' | 'release' | 'rts' | 'cancel' | 'void';

interface Props {
    cheque: Cheque;
    step: ChequeStep;
    onClose: () => void;
    onDone: (cheque: Cheque, what: string) => void;
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

const TITLES: Record<ChequeStep, string> = {
    route: 'Route for Signature',
    receive: 'Mark as Received',
    release: 'Release to Payee',
    rts: 'Return to Sender',
    cancel: 'Cancel Cheque',
    void: 'Void Cheque',
};

/**
 * Every step a cheque takes, in one dialog: routed out for signature, marked received, released
 * to the payee, or taken out of the flow by RTS, Cancel or Void.
 *
 * Each one opens on the cheque itself — number, payee, amount, date and how long it has left —
 * so the decision is made against the cheque rather than from memory. The status the page was
 * showing rides along as `expected_status`, so a cheque someone else has moved is refused
 * rather than quietly stepped on.
 */
export default function ChequeStepModal({ cheque, step, onClose, onDone }: Props) {
    const { user } = useAuth();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [confirming, setConfirming] = useState(false);

    const [forwardTo, setForwardTo] = useState('');
    const [unit, setUnit] = useState('');
    const [date, setDate] = useState(today());
    const [receivedBy, setReceivedBy] = useState(
        step === 'release' ? (cheque.payee_name ?? '') : (user?.name ?? ''),
    );
    const [note, setNote] = useState('');
    const [reason, setReason] = useState('');

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    const expected = cheque.status;

    async function run(what: string, call: () => Promise<Cheque>) {
        setBusy(true);
        setError('');
        setFieldErrors({});
        try {
            onDone(await call(), what);
        } catch (err) {
            const apiErr = toApiError(err);
            setFieldErrors(apiErr.errors);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setConfirming(false);
            setBusy(false);
        }
    }

    const fieldError = (field: string) => fieldErrors[field]?.[0];

    function submit(e: FormEvent) {
        e.preventDefault();

        if (step === 'route') {
            void run('routed for signature', () =>
                ChequeApi.routeForSignature(cheque.id, {
                    forward_to_name: forwardTo.trim(),
                    forward_unit_name: unit.trim() || undefined,
                    date_forwarded: date,
                    note: note.trim() || undefined,
                    expected_status: expected,
                }),
            );
        } else if (step === 'receive') {
            void run('received — now For ACIC', () =>
                ChequeApi.markAsReceived(cheque.id, {
                    received_by_name: receivedBy.trim() || undefined,
                    date_received: date,
                    from_unit_name: unit.trim() || undefined,
                    note: note.trim() || undefined,
                    expected_status: expected,
                }),
            );
        } else if (step === 'release') {
            // The last word before something that cannot be undone.
            if (!confirming) {
                setConfirming(true);
                return;
            }
            void run('released to the payee', () =>
                ChequeApi.release(cheque.id, {
                    received_by_name: receivedBy.trim(),
                    date_received: date,
                    note: note.trim() || undefined,
                    expected_status: expected,
                }),
            );
        } else {
            const verb = { rts: 'returned to sender', cancel: 'cancelled', void: 'voided' }[step];
            void run(verb, () => ChequeApi.except(cheque.id, step, reason.trim(), expected));
        }
    }

    const needsReason = step === 'rts' || step === 'cancel' || step === 'void';
    const destructive = step === 'cancel' || step === 'void';
    const Icon = { route: Send, receive: Inbox, release: HandCoins, rts: Undo2, cancel: Ban, void: Slash }[step];

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="cheque-step-title"
        >
            <div className="card flex max-h-[92vh] w-full max-w-md flex-col overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <span className="eyebrow">Cheque #{cheque.cheque_number}</span>
                        <h2 id="cheque-step-title" className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg">
                            {TITLES[step]}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {/* The cheque the decision is about. */}
                <div className="mb-5 space-y-1.5 rounded-xs border border-line bg-well p-3 text-sm">
                    {[
                        ['Payee', cheque.payee_name ?? '—'],
                        ['Amount', formatMoney(cheque.amount)],
                        ['Cheque date', formatDate(cheque.cheque_date)],
                        ['ACIC #', cheque.acic_number ? `#${cheque.acic_number}` : '—'],
                    ].map(([label, value]) => (
                        <div key={label} className="flex justify-between gap-4">
                            <span className="text-subtle">{label}</span>
                            <span className="text-right text-fg">{value}</span>
                        </div>
                    ))}
                    <div className="flex items-center justify-between gap-4 pt-1">
                        <span className="text-subtle">Status</span>
                        {cheque.effective_status && <StatusBadge status={cheque.effective_status} />}
                    </div>
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-subtle">Validity</span>
                        <ValidityBadge cheque={cheque} />
                    </div>
                </div>

                {error && (
                    <div className="mb-3">
                        <Alert kind="error">{error}</Alert>
                    </div>
                )}

                {confirming ? (
                    <div className="space-y-4">
                        <div className="rounded-xs border border-accent-400/50 bg-accent-400/10 p-3 text-sm text-fg">
                            Release cheque #{cheque.cheque_number} to{' '}
                            <span className="font-semibold">{receivedBy.trim()}</span>? This cannot be undone.
                        </div>
                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={() => setConfirming(false)} disabled={busy}>
                                Back
                            </button>
                            <button type="button" className="btn btn-primary" onClick={submit} disabled={busy}>
                                <HandCoins className="h-4 w-4" />
                                {busy ? 'Releasing…' : 'Confirm Release'}
                            </button>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-3">
                        {step === 'route' && (
                            <>
                                <Field label="Forward to" htmlFor="st-to">
                                    <input
                                        id="st-to"
                                        className="field !py-1.5"
                                        value={forwardTo}
                                        onChange={(e) => setForwardTo(e.target.value)}
                                        placeholder="Signatory or office"
                                        maxLength={255}
                                        required
                                        autoFocus
                                    />
                                    {fieldError('forward_to_name') && <p className="mt-1 text-xs text-danger-fg">{fieldError('forward_to_name')}</p>}
                                </Field>
                                <Field label="Unit name" htmlFor="st-unit" optional>
                                    <input id="st-unit" className="field !py-1.5" value={unit} onChange={(e) => setUnit(e.target.value)} maxLength={255} />
                                </Field>
                                <Field label="Forwarded by" htmlFor="st-by">
                                    <input id="st-by" className="field !py-1.5" value={user?.name ?? ''} disabled readOnly />
                                </Field>
                            </>
                        )}

                        {step === 'receive' && (
                            <>
                                <Field label="Received by" htmlFor="st-recv">
                                    <input
                                        id="st-recv"
                                        className="field !py-1.5"
                                        value={receivedBy}
                                        onChange={(e) => setReceivedBy(e.target.value)}
                                        maxLength={255}
                                        autoFocus
                                    />
                                </Field>
                                <Field label="From unit name" htmlFor="st-unit" optional>
                                    <input id="st-unit" className="field !py-1.5" value={unit} onChange={(e) => setUnit(e.target.value)} maxLength={255} />
                                </Field>
                                <p className="text-xs text-subtle">On save the cheque becomes <strong>For ACIC</strong>.</p>
                            </>
                        )}

                        {step === 'release' && (
                            <Field label="Received by" htmlFor="st-recv">
                                <input
                                    id="st-recv"
                                    className="field !py-1.5"
                                    value={receivedBy}
                                    onChange={(e) => setReceivedBy(e.target.value)}
                                    maxLength={255}
                                    required
                                    autoFocus
                                    aria-describedby="st-recv-hint"
                                />
                                <p id="st-recv-hint" className="mt-1 text-xs text-subtle">
                                    The payee, or the representative who collected it.
                                </p>
                                {fieldError('received_by_name') && <p className="mt-1 text-xs text-danger-fg">{fieldError('received_by_name')}</p>}
                            </Field>
                        )}

                        {!needsReason && (
                            <Field label={step === 'route' ? 'Date forwarded' : 'Date received'} htmlFor="st-date">
                                <input
                                    id="st-date"
                                    type="date"
                                    className="field !py-1.5"
                                    value={date}
                                    onChange={(e) => setDate(e.target.value)}
                                    max={today()}
                                    required
                                />
                                {(fieldError('date_forwarded') || fieldError('date_received')) && (
                                    <p className="mt-1 text-xs text-danger-fg">{fieldError('date_forwarded') ?? fieldError('date_received')}</p>
                                )}
                            </Field>
                        )}

                        {needsReason ? (
                            <>
                                <div className={`rounded-xs border p-3 text-sm ${destructive ? 'border-danger/40 bg-danger/10 text-danger-fg' : 'border-amber-400/50 bg-amber-400/10 text-fg'}`}>
                                    {step === 'rts'
                                        ? 'The cheque goes back to Registered, ready to be corrected and routed again.'
                                        : step === 'cancel'
                                          ? 'This closes the cheque for good, before it ever reaches an ACIC.'
                                          : 'This voids the cheque. Its number stays used and is never reassigned.'}
                                </div>
                                <Field label="Reason" htmlFor="st-reason">
                                    <textarea
                                        id="st-reason"
                                        className="field min-h-24 !py-1.5"
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        minLength={3}
                                        maxLength={2000}
                                        required
                                        autoFocus
                                    />
                                    {fieldError('reason') && <p className="mt-1 text-xs text-danger-fg">{fieldError('reason')}</p>}
                                </Field>
                            </>
                        ) : (
                            <Field label="Note" htmlFor="st-note" optional>
                                <textarea id="st-note" className="field min-h-20 !py-1.5" value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
                            </Field>
                        )}

                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className={`btn btn-primary ${destructive ? '!bg-danger hover:!bg-danger/80' : ''}`}
                                disabled={busy || (needsReason && reason.trim().length < 3)}
                            >
                                <Icon className="h-4 w-4" />
                                {busy ? 'Saving…' : TITLES[step]}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
