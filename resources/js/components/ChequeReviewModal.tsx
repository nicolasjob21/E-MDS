import { useEffect, useState, type FormEvent } from 'react';
import { X, ShieldCheck, AlertTriangle } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque, ReviewOutcome } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert } from './ui';

/**
 * Admin review of an issued cheque.
 *
 * Three modes share this dialog:
 *  - `review` — the single entry point from the cheque table: the admin picks any of the three
 *    outcomes. A straight approval needs no note; the other two do.
 *  - `approve` — a plain confirmation; the outcome is fixed to "approved".
 *  - `disapprove` — narrowed to the two negative outcomes, each requiring a note.
 */
export type ReviewMode = 'approve' | 'disapprove' | 'review';

const ALL_OUTCOMES: { value: ReviewOutcome; label: string; hint: string }[] = [
    {
        value: 'approved',
        label: 'Approved',
        hint: 'Signed off. Approved cheques are the ones that can go on an ACIC. This is final.',
    },
    {
        value: 'complies',
        label: 'Returned',
        hint: 'Returned to the staff member to fix what your note describes. Not final — the cheque comes back for review once they comply.',
    },
    {
        value: 'disapproved',
        label: 'Disapproved',
        hint: 'Rejected outright. This is final and cannot be revisited.',
    },
];

/** The negative outcomes only — what the legacy "disapprove" entry point offers. */
const OUTCOMES = ALL_OUTCOMES.filter((o) => o.value !== 'approved');

const OUTCOME_HINTS = Object.fromEntries(ALL_OUTCOMES.map((o) => [o.value, o.hint])) as Record<
    ReviewOutcome,
    string
>;

interface Props {
    cheque: Cheque;
    mode: ReviewMode;
    onClose: () => void;
    onReviewed: (cheque: Cheque) => void;
}

export default function ChequeReviewModal({ cheque, mode, onClose, onReviewed }: Props) {
    const [outcome, setOutcome] = useState<ReviewOutcome>(mode === 'review' ? 'approved' : 'disapproved');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            const chosen = mode === 'approve' ? 'approved' : outcome;
            const updated = await ChequeApi.review(
                cheque.id,
                chosen,
                // A straight approval carries no note; the other outcomes must explain themselves.
                chosen === 'approved' ? undefined : note.trim(),
            );
            onReviewed(updated);
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    const isApprove = mode === 'approve';
    const choices = mode === 'review' ? ALL_OUTCOMES : OUTCOMES;
    // Only an outright approval needs no explanation — matching ReviewChequeRequest.
    const noteRequired = outcome !== 'approved';

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="review-title"
        >
            <div className="card w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">{isApprove ? 'Confirm approval' : 'Review cheque'}</span>
                        <h2
                            id="review-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            Cheque #{cheque.cheque_number}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="mb-5 rounded-xs border border-line bg-well p-3 text-sm">
                    <div className="flex justify-between gap-4">
                        <span className="text-subtle">Payee</span>
                        <span className="text-right text-fg">{cheque.payee_name ?? '—'}</span>
                    </div>
                    <div className="mt-1.5 flex justify-between gap-4">
                        <span className="text-subtle">Amount</span>
                        <span className="text-right font-mono text-fg">{formatMoney(cheque.amount)}</span>
                    </div>
                    <div className="mt-1.5 flex justify-between gap-4">
                        <span className="text-subtle">ACIC no.</span>
                        <span className="text-right font-mono text-xs text-fg">
                            {cheque.acic_number ? `#${cheque.acic_number}` : '—'}
                        </span>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    {isApprove ? (
                        <p className="flex items-start gap-2 text-sm text-muted">
                            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-success" />
                            Approve cheque #{cheque.cheque_number}? This records the outcome against your account
                            and cannot be undone.
                        </p>
                    ) : (
                        <>
                            <div className="mb-4">
                                <label htmlFor="review-status" className="label">
                                    Status
                                </label>
                                <select
                                    id="review-status"
                                    className="field"
                                    value={outcome}
                                    onChange={(e) => setOutcome(e.target.value as ReviewOutcome)}
                                    autoFocus
                                >
                                    {choices.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1.5 text-xs text-muted">{OUTCOME_HINTS[outcome]}</p>
                            </div>

                            <div className="mb-4">
                                <label htmlFor="review-note" className="label">
                                    Reason / notes{' '}
                                    {!noteRequired && <span className="text-subtle">(optional)</span>}
                                </label>
                                <textarea
                                    id="review-note"
                                    className="field min-h-24"
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                    placeholder={
                                        outcome === 'complies'
                                            ? 'Say exactly what the staff member must fix…'
                                            : outcome === 'approved'
                                              ? 'Anything worth recording…'
                                              : 'Explain why this cheque is being rejected…'
                                    }
                                    required={noteRequired}
                                    minLength={noteRequired ? 5 : undefined}
                                    maxLength={1000}
                                />
                            </div>
                        </>
                    )}

                    {error && (
                        <div className="mt-4">
                            <Alert kind="error">{error}</Alert>
                        </div>
                    )}

                    <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                            {isApprove ? (
                                <ShieldCheck className="h-4 w-4" />
                            ) : (
                                <AlertTriangle className="h-4 w-4" />
                            )}
                            {busy ? 'Saving…' : 'Confirm'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
