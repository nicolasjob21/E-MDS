import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { X, ListChecks, Search, FilePlus2 } from 'lucide-react';
import { AcicApi, toApiError } from '../lib/api';
import type { Acic, Cheque } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, Spinner } from './ui';

interface Props {
    /** Pre-select this cheque when the modal is opened from a row action. */
    preselect?: Cheque | null;
    onClose: () => void;
    onAssigned: (acic: Acic) => void;
}

/**
 * "Assign to ACIC" — put approved cheques on one ACIC number, the next step after a cheque is
 * signed off.
 *
 * Only approved cheques not already on an ACIC are offered, so an invalid or duplicate
 * assignment can't be selected in the first place; the server re-checks under a lock.
 */
export default function ChequeAssignModal({ preselect, onClose, onAssigned }: Props) {
    const [cheques, setCheques] = useState<Cheque[] | null>(null);
    const [acics, setAcics] = useState<Acic[] | null>(null);
    const [acicId, setAcicId] = useState('');
    const [selected, setSelected] = useState<Set<number>>(new Set(preselect ? [preselect.id] : []));
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [opening, setOpening] = useState(false);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const [linkable, list] = await Promise.all([
                AcicApi.linkableCheques(),
                AcicApi.list('all', 1, 100),
            ]);
            setCheques(linkable);
            // Only an ACIC that still accepts cheques can be a target.
            const open = list.data.filter((a) => a.status === 'open' || a.status === 'used');
            setAcics(open);
            setAcicId((current) => current || (open[0] ? String(open[0].id) : ''));
        } catch (err) {
            setError(toApiError(err).message);
            setCheques([]);
            setAcics([]);
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

    const visible = useMemo(() => {
        const term = filter.trim().toLowerCase();
        if (!term) return cheques ?? [];
        return (cheques ?? []).filter(
            (c) =>
                String(c.cheque_number).includes(term) ||
                (c.payee_name ?? '').toLowerCase().includes(term),
        );
    }, [cheques, filter]);

    const total = useMemo(
        () =>
            (cheques ?? [])
                .filter((c) => selected.has(c.id))
                .reduce((sum, c) => sum + (Number(c.amount) || 0), 0),
        [cheques, selected],
    );

    function toggle(id: number) {
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

    /** Open the next ACIC in the sequence and make it the target, without leaving the modal. */
    async function handleOpenAcic() {
        setOpening(true);
        setError('');
        try {
            const acic = await AcicApi.create();
            setAcics((prev) => [acic, ...(prev ?? [])]);
            setAcicId(String(acic.id));
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setOpening(false);
        }
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (!acicId) {
            setError('Choose the ACIC these cheques go on, or open a new one.');
            return;
        }
        if (selected.size === 0) {
            setError('Select at least one cheque.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            onAssigned(await AcicApi.assignCheques(Number(acicId), [...selected]));
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
            aria-labelledby="cheque-assign-title"
        >
            <div
                className="card flex max-h-[92vh] w-full max-w-3xl flex-col p-6"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Assign to ACIC</span>
                        <h2
                            id="cheque-assign-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            Link approved cheques
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    Only <span className="font-semibold text-fg">approved</span> cheques that aren’t already
                    on an ACIC are listed. Many can share one ACIC number.
                </p>

                <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                    <div className="mb-3 flex flex-wrap items-end gap-3">
                        <div className="min-w-48 flex-1">
                            <label htmlFor="cheque-acic-target" className="label">
                                ACIC number
                            </label>
                            <select
                                id="cheque-acic-target"
                                className="field"
                                value={acicId}
                                onChange={(e) => setAcicId(e.target.value)}
                            >
                                {acics === null ? (
                                    <option value="">Loading…</option>
                                ) : acics.length === 0 ? (
                                    <option value="">No open ACIC — open one →</option>
                                ) : (
                                    acics.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            ACIC #{a.acic_number} ({a.status})
                                        </option>
                                    ))
                                )}
                            </select>
                        </div>
                        <button
                            type="button"
                            className="btn btn-outline"
                            onClick={handleOpenAcic}
                            disabled={opening || busy}
                        >
                            <FilePlus2 className="h-4 w-4" />
                            {opening ? 'Opening…' : 'Open next ACIC'}
                        </button>
                    </div>

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

                    <div className="min-h-0 flex-1 overflow-y-auto rounded-xs border border-line">
                        {cheques === null ? (
                            <div className="p-6">
                                <Spinner />
                            </div>
                        ) : visible.length === 0 ? (
                            <p className="p-6 text-center text-sm text-subtle">
                                {cheques.length === 0
                                    ? 'No approved cheques are waiting for an ACIC.'
                                    : 'No cheque matches that filter.'}
                            </p>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <thead className="sticky top-0 bg-well">
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                checked={allShownSelected}
                                                onChange={toggleAllVisible}
                                                aria-label="Select all shown"
                                            />
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
                                                    type="checkbox"
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
                            {selected.size} selected · total{' '}
                            <span className="font-mono font-semibold text-fg">{formatMoney(total)}</span>
                        </span>
                        <div className="flex gap-2">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={busy || selected.size === 0 || !acicId}
                            >
                                <ListChecks className="h-4 w-4" />
                                {busy ? 'Assigning…' : 'Assign to ACIC'}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}
