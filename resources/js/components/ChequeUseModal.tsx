import { useEffect, useState, type FormEvent } from 'react';
import { X, CheckCircle2, Lock } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque } from '../lib/types';
import { Alert } from './ui';

interface Props {
    /** The cheque being acted on — always the lowest available number. */
    cheque: Cheque;
    onClose: () => void;
    onUsed: (cheque: Cheque) => void;
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * The row-level "Action" on an available cheque: record the details that put the number into
 * use, without leaving the table for the next-in-line panel.
 *
 * It writes through the same `POST /cheques/use` path the panel uses, so the number is still
 * re-checked against the real next-available row under a lock — this dialog is a second way in,
 * never a way around the sequence.
 */
export default function ChequeUseModal({ cheque, onClose, onUsed }: Props) {
    const [payeeName, setPayeeName] = useState('');
    const [amount, setAmount] = useState('');
    const [chequeDate, setChequeDate] = useState(today());
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
            const used = await ChequeApi.consumeNext(cheque.cheque_number, {
                payee_name: payeeName.trim(),
                amount: Number(amount),
                cheque_date: chequeDate,
            });
            onUsed(used);
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="use-cheque-title"
        >
            <div className="card w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Use cheque</span>
                        <h2
                            id="use-cheque-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            Cheque #{cheque.cheque_number}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-5 flex items-start gap-2 rounded-xs border border-line bg-well p-3 text-sm text-muted">
                    <Lock className="mt-0.5 h-4 w-4 shrink-0 text-brandink" />
                    This is the lowest available number, so it is the only one that can be used next.
                    Recording these details puts it into use against your account.
                </p>

                <form onSubmit={handleSubmit}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label htmlFor="use-payee" className="label">
                                Cheque name / payee
                            </label>
                            <input
                                id="use-payee"
                                className="field"
                                value={payeeName}
                                onChange={(e) => setPayeeName(e.target.value)}
                                placeholder="e.g. Acme Supplies"
                                maxLength={255}
                                autoFocus
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="use-amount" className="label">
                                Amount
                            </label>
                            <input
                                id="use-amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                className="field"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                placeholder="0.00"
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="use-date" className="label">
                                Cheque date
                            </label>
                            <input
                                id="use-date"
                                type="date"
                                className="field"
                                value={chequeDate}
                                onChange={(e) => setChequeDate(e.target.value)}
                                required
                            />
                        </div>
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
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                            <CheckCircle2 className="h-4 w-4" />
                            {busy ? 'Working…' : `Confirm & use #${cheque.cheque_number}`}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
