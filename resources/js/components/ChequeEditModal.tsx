import { useEffect, useState, type FormEvent } from 'react';
import { X, Save } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque } from '../lib/types';
import { Alert } from './ui';
import UnitSelect from './UnitSelect';

interface Props {
    cheque: Cheque;
    onClose: () => void;
    onSaved: (cheque: Cheque) => void;
}

/**
 * Edit a cheque's details — allowed only before its first draft, or while it is For Compliance
 * after a Return. Every field is pre-filled with what is saved; the number never changes. Each
 * save is a timeline entry of its own, with what changed.
 */
export default function ChequeEditModal({ cheque, onClose, onSaved }: Props) {
    const [payeeName, setPayeeName] = useState(cheque.payee_name ?? '');
    const [accountNo, setAccountNo] = useState(cheque.account_no ?? '');
    const [unitName, setUnitName] = useState(cheque.unit_name ?? '');
    const [amount, setAmount] = useState(cheque.amount ?? '');
    const [chequeDate, setChequeDate] = useState(cheque.cheque_date ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

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
        setFieldErrors({});
        try {
            const saved = await ChequeApi.updateDetails(
                cheque.id,
                {
                    payee_name: payeeName.trim(),
                    account_no: accountNo.trim() || undefined,
                    unit_name: unitName || undefined,
                    amount: Number(amount),
                    cheque_date: chequeDate,
                },
                cheque.status,
            );
            onSaved(saved);
        } catch (err) {
            const apiErr = toApiError(err);
            setFieldErrors(apiErr.errors);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    const fieldError = (field: string) => fieldErrors[field]?.[0];

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="edit-cheque-title"
        >
            <div className="card max-h-[92vh] w-full max-w-md overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Edit details</span>
                        <h2 id="edit-cheque-title" className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg">
                            Cheque #{cheque.cheque_number}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {cheque.status === 'for_compliance' && (
                    <p className="mb-4 rounded-xs border border-amber-400/50 bg-amber-400/10 p-3 text-sm text-fg">
                        This draft was returned. Make the changes asked for, then print a new draft to send it back for checking.
                    </p>
                )}

                <form onSubmit={handleSubmit}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label htmlFor="edit-payee" className="label">
                                Cheque name / payee
                            </label>
                            <input
                                id="edit-payee"
                                className="field"
                                value={payeeName}
                                onChange={(e) => setPayeeName(e.target.value)}
                                maxLength={255}
                                autoFocus
                                required
                            />
                            {fieldError('payee_name') && <p className="mt-1 text-xs text-danger-fg">{fieldError('payee_name')}</p>}
                        </div>
                        <div>
                            <label htmlFor="edit-account" className="label">
                                Account No. <span className="normal-case tracking-normal text-subtle">(optional)</span>
                            </label>
                            <input
                                id="edit-account"
                                className="field font-mono"
                                value={accountNo}
                                onChange={(e) => setAccountNo(e.target.value)}
                                maxLength={255}
                                autoComplete="off"
                            />
                        </div>
                        <div className="min-w-0">
                            <label htmlFor="edit-unit" className="label">
                                Unit <span className="normal-case tracking-normal text-subtle">(optional)</span>
                            </label>
                            <UnitSelect id="edit-unit" value={unitName} onChange={setUnitName} />
                            {fieldError('unit_name') && <p className="mt-1 text-xs text-danger-fg">{fieldError('unit_name')}</p>}
                        </div>
                        <div>
                            <label htmlFor="edit-amount" className="label">
                                Amount
                            </label>
                            <input
                                id="edit-amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                className="field"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                required
                            />
                            {fieldError('amount') && <p className="mt-1 text-xs text-danger-fg">{fieldError('amount')}</p>}
                        </div>
                        <div>
                            <label htmlFor="edit-date" className="label">
                                Cheque date
                            </label>
                            <input
                                id="edit-date"
                                type="date"
                                className="field"
                                value={chequeDate}
                                onChange={(e) => setChequeDate(e.target.value)}
                                required
                            />
                            {fieldError('cheque_date') && <p className="mt-1 text-xs text-danger-fg">{fieldError('cheque_date')}</p>}
                        </div>
                    </div>

                    {error && !Object.keys(fieldErrors).some((k) => k !== 'cheque') && (
                        <div className="mt-4">
                            <Alert kind="error">{error}</Alert>
                        </div>
                    )}

                    <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                            <Save className="h-4 w-4" />
                            {busy ? 'Saving…' : 'Save changes'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
