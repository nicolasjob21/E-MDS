import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { X, UserRound } from 'lucide-react';
import { AcicTellerApi, toApiError } from '../lib/api';
import type { Acic, Cheque } from '../lib/types';
import { formatDate, formatMoney } from '../lib/format';
import { Alert } from './ui';
import UnitSelect from './UnitSelect';

interface Props {
    /** The full ACIC, with its cheques. */
    acic: Acic;
    onClose: () => void;
    onDone: (acic: Acic, message: string) => void;
}

/** Today in Manila, as the date input wants it. */
function manilaToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
}

/** A cheque that can still go: with the teller (accepted, or Returned by an RTS) and not stale. */
function canGo(c: Cheque): boolean {
    return !c.is_stale && ['accepted_by_teller', 'returned', 'returned_by_bank'].includes(c.status);
}

/**
 * **Forward to Payee** — the accepting teller hands one, several or all of a cheque ACIC's
 * cheques to their payees. The summary and the list come only from the cheques already on the
 * ACIC; stale cheques, and those already forwarded, are shown but cannot be ticked. Who received
 * them, when (today by default, never later) and their unit are entered once for the batch.
 */
export default function PayeeForwardModal({ acic, onClose, onDone }: Props) {
    const cheques = useMemo(() => [...(acic.cheques ?? [])].sort((a, b) => a.cheque_number - b.cheque_number), [acic]);
    const selectable = cheques.filter(canGo);
    const forwardedCount = cheques.filter((c) => c.status === 'forwarded_to_payee').length;
    const total = cheques.reduce((sum, c) => sum + (Number(c.amount) || 0), 0);

    const [selected, setSelected] = useState<number[]>([]);
    const [receivedBy, setReceivedBy] = useState('');
    const [dateReceived, setDateReceived] = useState(manilaToday());
    const [unit, setUnit] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    const allSelected = selectable.length > 0 && selectable.every((c) => selected.includes(c.id));
    const selectedTotal = cheques
        .filter((c) => selected.includes(c.id))
        .reduce((sum, c) => sum + (Number(c.amount) || 0), 0);
    const ready = selected.length > 0 && receivedBy.trim().length >= 2 && dateReceived !== '' && unit !== '';

    function toggle(id: number) {
        setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (!ready) return;
        setBusy(true);
        setError('');
        try {
            const done = await AcicTellerApi.forwardToPayee(
                acic.id,
                { cheque_ids: selected, received_by: receivedBy.trim(), date_received: dateReceived, unit },
                acic.teller_status ?? undefined,
            );
            onDone(done, `${selected.length} cheque${selected.length === 1 ? '' : 's'} on ACIC #${acic.acic_number} forwarded to the payee.`);
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
            aria-labelledby="payee-forward-title"
        >
            <form className="card flex max-h-[92vh] w-full max-w-3xl flex-col p-6" onClick={(e) => e.stopPropagation()} onSubmit={handleSubmit}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Forward to Payee</span>
                        <h2 id="payee-forward-title" className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg">
                            ACIC #{acic.acic_number}
                        </h2>
                    </div>
                    <button type="button" className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto">
                    {/* Summary — only what is already on the ACIC. */}
                    <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-4">
                        <div className="bg-card p-3">
                            <dt className="text-xs uppercase tracking-wider text-subtle">ACIC Date</dt>
                            <dd className="mt-1 text-fg">{formatDate(acic.created_at)}</dd>
                        </div>
                        <div className="bg-card p-3">
                            <dt className="text-xs uppercase tracking-wider text-subtle">Cheques</dt>
                            <dd className="mt-1 text-fg">{cheques.length}</dd>
                        </div>
                        <div className="bg-card p-3">
                            <dt className="text-xs uppercase tracking-wider text-subtle">Forwarded</dt>
                            <dd className="mt-1 text-fg">
                                {forwardedCount}/{forwardedCount + selectable.length}
                            </dd>
                        </div>
                        <div className="bg-card p-3">
                            <dt className="text-xs uppercase tracking-wider text-subtle">Total Amount</dt>
                            <dd className="mt-1 font-mono font-semibold text-fg">{formatMoney(total)}</dd>
                        </div>
                    </dl>

                    <div className="overflow-x-auto rounded-xs border border-line">
                        <table className="w-full min-w-[34rem] text-left text-sm">
                            <thead className="bg-well">
                                <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                    <th className="px-3 py-2">
                                        <input
                                            type="checkbox"
                                            aria-label="Select all"
                                            checked={allSelected}
                                            disabled={selectable.length === 0}
                                            onChange={() => setSelected(allSelected ? [] : selectable.map((c) => c.id))}
                                        />
                                    </th>
                                    <th className="px-3 py-2 font-semibold">Check No.</th>
                                    <th className="px-3 py-2 font-semibold">Payee</th>
                                    <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                    <th className="px-3 py-2 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {cheques.map((c) => {
                                    const ok = canGo(c);
                                    const ticked = selected.includes(c.id);
                                    return (
                                        <tr
                                            key={c.id}
                                            className={`border-b border-line/60 last:border-0 ${ok ? 'cursor-pointer hover:bg-well' : 'opacity-60'} ${
                                                ticked ? 'bg-brand-500/5' : ''
                                            }`}
                                            onClick={() => ok && toggle(c.id)}
                                        >
                                            <td className="px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Select cheque #${c.cheque_number}`}
                                                    checked={ticked}
                                                    disabled={!ok}
                                                    onChange={() => toggle(c.id)}
                                                    onClick={(e) => e.stopPropagation()}
                                                />
                                            </td>
                                            <td className="px-3 py-2 font-display font-bold text-fg">#{c.cheque_number}</td>
                                            <td className="px-3 py-2 text-muted">{c.payee_name ?? '—'}</td>
                                            <td className="px-3 py-2 text-right font-mono text-muted">{formatMoney(c.amount)}</td>
                                            <td className="px-3 py-2 text-xs">
                                                {c.is_stale ? (
                                                    <span className="text-danger-fg">Stale — cannot be forwarded</span>
                                                ) : c.status === 'forwarded_to_payee' ? (
                                                    <span className="text-muted">
                                                        Forwarded — {c.payee_receipt?.received_by ?? '—'}, {formatDate(c.payee_receipt?.date_received)}
                                                    </span>
                                                ) : ok ? (
                                                    <span className="text-subtle">Ready</span>
                                                ) : (
                                                    <span className="text-subtle">{c.status_label ?? c.status}</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Who received the ticked cheques — once for the batch. */}
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label htmlFor="payee-received-by" className="label">
                                Received By
                            </label>
                            <input
                                id="payee-received-by"
                                className="field"
                                value={receivedBy}
                                onChange={(e) => setReceivedBy(e.target.value)}
                                placeholder="Name of the person who received it"
                                maxLength={255}
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="payee-date-received" className="label">
                                Date Received
                            </label>
                            <input
                                id="payee-date-received"
                                type="date"
                                className="field"
                                value={dateReceived}
                                max={manilaToday()}
                                onChange={(e) => setDateReceived(e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="payee-unit" className="label">
                                Unit
                            </label>
                            <UnitSelect id="payee-unit" value={unit} onChange={setUnit} required />
                        </div>
                    </div>
                </div>

                {error && (
                    <div className="mt-4">
                        <Alert kind="error">{error}</Alert>
                    </div>
                )}

                <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <span className="text-sm text-muted" aria-live="polite">
                        {selected.length} selected · <span className="font-mono font-semibold text-fg">{formatMoney(selectedTotal)}</span>
                    </span>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row">
                        <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy || !ready}>
                            <UserRound className="h-4 w-4" />
                            {busy ? 'Saving…' : `Forward ${selected.length || ''} to Payee`.replace('  ', ' ')}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    );
}
