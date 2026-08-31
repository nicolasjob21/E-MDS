import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { X, Plus, Trash2, Hash } from 'lucide-react';
import { LddapApi, toApiError } from '../lib/api';
import type { LddapDraft, NextCheckNumbers } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, Spinner } from './ui';

interface Props {
    onClose: () => void;
    onUsed: (count: number) => void;
}

const emptyRow = (): LddapDraft => ({ lddap_no: '', obj_no: '', payee_name: '', amount: '' });

/**
 * "Use Check Number" — register LDDAP-ADA documents against the LDDAP check series.
 *
 * The series is independent of the cheque register and of ACIC numbering. One LDDAP takes one
 * check number, matched in row order against the lowest unused numbers, so the preview beside
 * each row is exactly what the server will assign. The batch is all-or-nothing: the server
 * re-derives the numbers under a lock and refuses the whole request if the series has moved on,
 * rather than letting a number be skipped.
 */
export default function LddapUseModal({ onClose, onUsed }: Props) {
    const [rows, setRows] = useState<LddapDraft[]>([emptyRow()]);
    const [pool, setPool] = useState<NextCheckNumbers | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    const load = useCallback(async () => {
        try {
            // Fetched once for the whole batch; rows are matched against this list in order.
            setPool(await LddapApi.nextNumbers(20));
        } catch (err) {
            setError(toApiError(err).message);
        }
    }, []);

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

    const maxBatch = pool?.max_batch ?? 20;
    const numbers = pool?.numbers ?? [];
    const outOfNumbers = pool !== null && numbers.length === 0;
    const tooManyRows = rows.length > numbers.length;

    const total = useMemo(
        () => rows.reduce((sum, row) => sum + (Number(row.amount) || 0), 0),
        [rows],
    );

    function update(index: number, patch: Partial<LddapDraft>) {
        setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function addRow() {
        setRows((prev) => (prev.length >= maxBatch ? prev : [...prev, emptyRow()]));
    }

    function removeRow(index: number) {
        setRows((prev) => (prev.length === 1 ? prev : prev.filter((_, i) => i !== index)));
    }

    /** The error the server reported for a specific row field, if any. */
    function rowError(index: number, field: keyof LddapDraft): string | undefined {
        return fieldErrors[`rows.${index}.${field}`]?.[0];
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        setFieldErrors({});

        if (numbers.length === 0) {
            setError(
                'There are no unused check numbers left in the LDDAP series. Ask an admin to register a new range.',
            );
            return;
        }
        if (tooManyRows) {
            setError(
                `Only ${numbers.length} check number${numbers.length === 1 ? ' is' : 's are'} still unused, but ${rows.length} LDDAP records are listed.`,
            );
            return;
        }

        setBusy(true);
        try {
            const created = await LddapApi.consumeCheckNumbers(numbers[0], rows);
            onUsed(created.length);
        } catch (err) {
            const apiErr = toApiError(err);
            setFieldErrors(apiErr.errors);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
            // The series may have moved on under us — refresh the preview so a retry is accurate.
            void load();
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="lddap-use-title"
        >
            <div
                className="card flex max-h-[92vh] w-full max-w-4xl flex-col p-6"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Use Check Number</span>
                        <h2
                            id="lddap-use-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            Register LDDAP records
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    Each LDDAP takes <span className="font-semibold text-fg">one</span> number from the LDDAP
                    check series, lowest unused first. Numbers cannot be chosen, typed or skipped — the check
                    number shown beside each row is the one it will take.
                    {pool !== null && (
                        <>
                            {' '}
                            <span className="font-semibold text-fg">{pool.available}</span> number
                            {pool.available === 1 ? ' is' : 's are'} still unused.
                        </>
                    )}
                </p>

                {pool === null ? (
                    <div className="py-6">
                        <Spinner />
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                        {/* Rows. Scrolls on its own so the footer totals stay in view. */}
                        <div className="min-h-0 flex-1 overflow-y-auto rounded-xs border border-line">
                            <table className="w-full min-w-[46rem] text-left text-sm">
                                <thead className="sticky top-0 bg-well">
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="w-28 px-3 py-2 font-semibold">Check No.</th>
                                        <th className="px-3 py-2 font-semibold">LDDAP No.</th>
                                        <th className="px-3 py-2 font-semibold">OBJ No.</th>
                                        <th className="px-3 py-2 font-semibold">Payee</th>
                                        <th className="w-36 px-3 py-2 font-semibold">Amount</th>
                                        <th className="w-12 px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row, index) => {
                                        const number = numbers[index];
                                        return (
                                            <tr key={index} className="border-b border-line/60 last:border-0 align-top">
                                                <td className="px-3 py-2">
                                                    {number === undefined ? (
                                                        <span className="text-xs text-danger-fg">
                                                            None left
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 font-display font-bold text-fg">
                                                            <Hash className="h-3 w-3 text-subtle" />
                                                            {number}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <input
                                                        className="field !py-1.5"
                                                        value={row.lddap_no}
                                                        onChange={(e) =>
                                                            update(index, { lddap_no: e.target.value })
                                                        }
                                                        placeholder="LDDAP-0001"
                                                        aria-label={`LDDAP number for row ${index + 1}`}
                                                        required
                                                    />
                                                    {rowError(index, 'lddap_no') && (
                                                        <p className="mt-1 text-xs text-danger-fg">
                                                            {rowError(index, 'lddap_no')}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <input
                                                        className="field !py-1.5"
                                                        value={row.obj_no}
                                                        onChange={(e) => update(index, { obj_no: e.target.value })}
                                                        placeholder="Optional"
                                                        aria-label={`OBJ number for row ${index + 1}`}
                                                    />
                                                </td>
                                                <td className="px-3 py-2">
                                                    <input
                                                        className="field !py-1.5"
                                                        value={row.payee_name}
                                                        onChange={(e) =>
                                                            update(index, { payee_name: e.target.value })
                                                        }
                                                        placeholder="Optional"
                                                        aria-label={`Payee for row ${index + 1}`}
                                                    />
                                                </td>
                                                <td className="px-3 py-2">
                                                    <input
                                                        className="field !py-1.5 text-right font-mono"
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        value={row.amount}
                                                        onChange={(e) => update(index, { amount: e.target.value })}
                                                        placeholder="0.00"
                                                        aria-label={`Amount for row ${index + 1}`}
                                                        required
                                                    />
                                                    {rowError(index, 'amount') && (
                                                        <p className="mt-1 text-xs text-danger-fg">
                                                            {rowError(index, 'amount')}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <button
                                                        type="button"
                                                        className="btn btn-ghost !px-2 !py-1.5"
                                                        onClick={() => removeRow(index)}
                                                        disabled={rows.length === 1}
                                                        aria-label={`Remove row ${index + 1}`}
                                                        title="Remove this LDDAP"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <button
                                type="button"
                                className="btn btn-outline !px-3 !py-1.5"
                                onClick={addRow}
                                disabled={rows.length >= maxBatch || rows.length >= numbers.length}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add LDDAP
                            </button>
                            <span className="text-sm text-muted">
                                {rows.length} LDDAP{rows.length === 1 ? '' : 's'} · total{' '}
                                <span className="font-mono font-semibold text-fg">{formatMoney(total)}</span>
                            </span>
                        </div>

                        {error && (
                            <div className="mt-4">
                                <Alert kind="error">{error}</Alert>
                            </div>
                        )}

                        <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={busy || outOfNumbers || tooManyRows}
                            >
                                <Hash className="h-4 w-4" />
                                {busy
                                    ? 'Using…'
                                    : rows.length === 1
                                      ? `Use check #${numbers[0] ?? '—'}`
                                      : `Use ${rows.length} check numbers`}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
