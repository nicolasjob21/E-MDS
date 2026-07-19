import { useState, type FormEvent } from 'react';
import { CheckCircle2, AlertTriangle } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque } from '../lib/types';
import { Alert } from './ui';

interface Props {
    next: Cheque | null;
    onUsed: (cheque: Cheque) => void;
    compact?: boolean;
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * Presents the single next-in-line cheque. Using it captures the cheque's details
 * (payee, amount, date) before confirming. The server re-validates the number under a
 * lock, so this is safe against races and against skipping.
 */
export default function NextChequePanel({ next, onUsed, compact = false }: Props) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const [payeeName, setPayeeName] = useState('');
    const [amount, setAmount] = useState('');
    const [chequeDate, setChequeDate] = useState(today());

    function reset() {
        setConfirming(false);
        setPayeeName('');
        setAmount('');
        setChequeDate(today());
        setError('');
    }

    async function handleUse(e: FormEvent) {
        e.preventDefault();
        if (!next) return;
        setBusy(true);
        setError('');
        try {
            const used = await ChequeApi.consumeNext(next.cheque_number, {
                payee_name: payeeName.trim(),
                amount: Number(amount),
                cheque_date: chequeDate,
            });
            reset();
            onUsed(used);
        } catch (err) {
            const apiErr = toApiError(err);
            const first = Object.values(apiErr.errors)[0]?.[0];
            setError(first ?? apiErr.message);
        } finally {
            setBusy(false);
        }
    }

    if (!next) {
        return (
            <div className="card flex items-center gap-3 border-accent-400/30 bg-accent-400/5 p-5 text-sm text-accent-300">
                <AlertTriangle className="h-5 w-5 shrink-0" />
                <span>No cheque numbers are available. An administrator needs to add a new range.</span>
            </div>
        );
    }

    return (
        <div className={`card p-6 ${compact ? '' : 'md:p-8'}`}>
            <div className="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <span className="eyebrow">Next in line</span>
                    <div className="mt-3 font-display text-5xl font-extrabold tracking-tight text-brandink md:text-6xl">
                        #{next.cheque_number}
                    </div>
                    <p className="mt-2 text-sm text-muted">
                        This is the only cheque you can use next — numbers cannot be skipped.
                    </p>
                </div>

                {!confirming && (
                    <button className="btn btn-primary" onClick={() => setConfirming(true)}>
                        <CheckCircle2 className="h-4 w-4" />
                        Use #{next.cheque_number}
                    </button>
                )}
            </div>

            {confirming && (
                <form onSubmit={handleUse} className="mt-6 border-t border-line pt-6">
                    <p className="mb-4 text-sm text-muted">
                        Enter the details for cheque <span className="font-semibold text-fg">#{next.cheque_number}</span>:
                    </p>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label htmlFor="payee" className="label">
                                Cheque name / payee
                            </label>
                            <input
                                id="payee"
                                className="field"
                                value={payeeName}
                                onChange={(e) => setPayeeName(e.target.value)}
                                placeholder="e.g. Acme Supplies"
                                autoFocus
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="amount" className="label">
                                Amount
                            </label>
                            <input
                                id="amount"
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
                            <label htmlFor="cheque-date" className="label">
                                Cheque date
                            </label>
                            <input
                                id="cheque-date"
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

                    <div className="mt-5 flex gap-2">
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                            <CheckCircle2 className="h-4 w-4" />
                            {busy ? 'Working…' : `Confirm & use #${next.cheque_number}`}
                        </button>
                        <button type="button" className="btn btn-ghost" onClick={reset} disabled={busy}>
                            Cancel
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}
