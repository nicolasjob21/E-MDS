import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { X, ListChecks, Search } from 'lucide-react';
import { AcicApi, toApiError } from '../lib/api';
import type { Acic, Cheque } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, Spinner } from './ui';

interface Props {
    /**
     * The ACIC being filled. Omit it to assign onto the **next number in the sequence**: it is
     * opened on submit, so cancelling out leaves no empty ACIC behind.
     */
    acic?: Acic | null;
    /** The previewed next number, shown while there is no ACIC yet. */
    nextNumber?: number | null;
    /** Restrict the pick to a single cheque. */
    single?: boolean;
    /** Id of the cheque to start with selected, when the dialog is opened from its row. */
    preselect?: number | null;
    onClose: () => void;
    onAssigned: (acic: Acic) => void;
}

/**
 * "Use ACIC" — pick the cheques that go on this ACIC.
 *
 * Only approved cheques not already sitting on another ACIC are offered, so an invalid or
 * duplicate assignment can't be selected in the first place; the server re-checks regardless.
 */
export default function AcicUseModal({
    acic,
    nextNumber,
    single = false,
    preselect = null,
    onClose,
    onAssigned,
}: Props) {
    const [cheques, setCheques] = useState<Cheque[] | null>(null);
    const [selected, setSelected] = useState<Set<number>>(new Set(preselect ? [preselect] : []));
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            setCheques(await AcicApi.linkableCheques());
        } catch (err) {
            setError(toApiError(err).message);
            setCheques([]);
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

    const visible = (cheques ?? []).filter((c) => {
        const term = filter.trim().toLowerCase();
        if (!term) return true;
        return (
            String(c.cheque_number).includes(term) ||
            (c.payee_name ?? '').toLowerCase().includes(term)
        );
    });

    function toggle(id: number) {
        // In single mode the pick replaces itself, so a second click moves the choice rather
        // than adding to it. Clicking the chosen row again clears it.
        if (single) {
            setSelected((prev) => (prev.has(id) ? new Set() : new Set([id])));
            return;
        }
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function toggleAllVisible() {
        const allShown = visible.length > 0 && visible.every((c) => selected.has(c.id));
        setSelected((prev) => {
            const next = new Set(prev);
            visible.forEach((c) => (allShown ? next.delete(c.id) : next.add(c.id)));
            return next;
        });
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (selected.size === 0) {
            setError(single ? 'Select a cheque.' : 'Select at least one cheque.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            // Opening the ACIC is deferred to here so a cancelled dialog leaves no empty number
            // in the sequence. The server re-derives the number under a lock either way.
            const target = acic ?? (await AcicApi.create());
            onAssigned(await AcicApi.assignCheques(target.id, [...selected]));
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    const allShownSelected = visible.length > 0 && visible.every((c) => selected.has(c.id));

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="acic-use-title"
        >
            <div
                className="card flex max-h-[90vh] w-full max-w-2xl flex-col p-6"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Use ACIC</span>
                        <h2
                            id="acic-use-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            ACIC #{acic?.acic_number ?? nextNumber ?? '—'}
                        </h2>
                        {!acic && (
                            <p className="mt-1 text-xs text-subtle">
                                Next in sequence — opened when you assign.
                            </p>
                        )}
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    {single
                        ? 'Choose one approved cheque to put on this ACIC.'
                        : 'Select the approved cheques to put on this ACIC.'}{' '}
                    Only approved cheques that aren’t already on another ACIC are listed.
                </p>

                <div className="relative mb-3">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                    <input
                        type="search"
                        className="field !pl-10"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        placeholder="Filter by cheque number or payee…"
                        aria-label="Filter cheques"
                    />
                </div>

                <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                    <div className="min-h-0 flex-1 overflow-y-auto rounded-xs border border-line">
                        {cheques === null ? (
                            <div className="p-6">
                                <Spinner />
                            </div>
                        ) : visible.length === 0 ? (
                            <p className="p-6 text-center text-sm text-subtle">
                                {cheques.length === 0
                                    ? 'No approved cheques are available to assign.'
                                    : 'No cheque matches that filter.'}
                            </p>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <thead className="sticky top-0 bg-well">
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-3 py-2">
                                            {!single && (
                                                <input
                                                    type="checkbox"
                                                    checked={allShownSelected}
                                                    onChange={toggleAllVisible}
                                                    aria-label="Select all shown"
                                                />
                                            )}
                                        </th>
                                        <th className="px-3 py-2 font-semibold">Cheque</th>
                                        <th className="px-3 py-2 font-semibold">Payee</th>
                                        <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visible.map((c) => (
                                        <tr
                                            key={c.id}
                                            className="cursor-pointer border-b border-line/60 last:border-0 hover:bg-well"
                                            onClick={() => toggle(c.id)}
                                        >
                                            <td className="px-3 py-2">
                                                <input
                                                    type={single ? 'radio' : 'checkbox'}
                                                    name={single ? 'acic-cheque' : undefined}
                                                    checked={selected.has(c.id)}
                                                    onChange={() => toggle(c.id)}
                                                    onClick={(e) => e.stopPropagation()}
                                                    aria-label={`Select cheque ${c.cheque_number}`}
                                                />
                                            </td>
                                            <td className="px-3 py-2 font-display font-bold text-fg">
                                                #{c.cheque_number}
                                            </td>
                                            <td className="px-3 py-2 text-muted">{c.payee_name ?? '—'}</td>
                                            <td className="px-3 py-2 text-right font-mono text-muted">
                                                {formatMoney(c.amount)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    {error && (
                        <div className="mt-4">
                            <Alert kind="error">{error}</Alert>
                        </div>
                    )}

                    <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <span className="text-sm text-muted" aria-live="polite">
                            {single
                                ? selected.size === 1
                                    ? '1 cheque selected'
                                    : 'No cheque selected'
                                : `${selected.size} cheque${selected.size === 1 ? '' : 's'} selected`}
                        </span>
                        <div className="flex gap-2">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={busy || selected.size === 0}
                            >
                                <ListChecks className="h-4 w-4" />
                                {busy
                                    ? 'Assigning…'
                                    : acic
                                      ? 'Assign to ACIC'
                                      : `Assign to ACIC #${nextNumber ?? ''}`}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}
