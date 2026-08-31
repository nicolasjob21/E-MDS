import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { X, ListChecks, Search } from 'lucide-react';
import { AcicApi, LddapApi, toApiError } from '../lib/api';
import type { Acic, Lddap } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, Spinner } from './ui';

interface Props {
    /** Pre-select this LDDAP when the modal is opened from a row action. */
    preselect?: Lddap | null;
    /**
     * Put the records on **this** ACIC — an existing one that is still open or used. Omit it to
     * assign onto the next number in the sequence, which is opened on submit.
     */
    acic?: Acic | null;
    onClose: () => void;
    onAssigned: (acic: Acic) => void;
}

/**
 * "Assign LDDAP to ACIC" — put approved LDDAP records on an ACIC: the **next in the sequence**,
 * or an existing one passed in as `acic`.
 *
 * A **Used** ACIC keeps accepting records. Used only means it already carries something; many
 * LDDAPs share one ACIC number, and they all stay on that one table.
 *
 * Only approved LDDAPs not already on an ACIC are offered, so an invalid or duplicate
 * assignment can't be selected in the first place; the server re-checks under a lock. Many
 * LDDAPs may share one ACIC. The ACIC itself is opened on submit — cancelling out leaves no
 * empty number behind — matching how `AcicUseModal` assigns cheques. The LDDAP rows stay in the
 * LDDAP table either way; assigning only fills in their ACIC No.
 */
export default function LddapAssignModal({ preselect, acic, onClose, onAssigned }: Props) {
    const [lddaps, setLddaps] = useState<Lddap[] | null>(null);
    // The number the ACIC will take. It is opened on submit, so cancelling out leaves no empty
    // number in the sequence.
    const [nextNumber, setNextNumber] = useState<number | null>(null);
    const [selected, setSelected] = useState<Set<number>>(new Set(preselect ? [preselect.id] : []));
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const [linkable, next] = await Promise.all([
                LddapApi.linkable(),
                acic ? Promise.resolve(acic.acic_number) : AcicApi.next(),
            ]);
            setLddaps(linkable);
            setNextNumber(next);
        } catch (err) {
            setError(toApiError(err).message);
            setLddaps([]);
        }
    }, [acic]);

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
        if (!term) return lddaps ?? [];
        return (lddaps ?? []).filter(
            (l) =>
                l.lddap_no.toLowerCase().includes(term) ||
                (l.obj_no ?? '').toLowerCase().includes(term) ||
                String(l.check_no ?? '').includes(term),
        );
    }, [lddaps, filter]);

    const total = useMemo(
        () =>
            (lddaps ?? [])
                .filter((l) => selected.has(l.id))
                .reduce((sum, l) => sum + (Number(l.amount) || 0), 0),
        [lddaps, selected],
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
        const allShown = visible.length > 0 && visible.every((l) => selected.has(l.id));
        setSelected((prev) => {
            const next = new Set(prev);
            visible.forEach((l) => (allShown ? next.delete(l.id) : next.add(l.id)));
            return next;
        });
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (selected.size === 0) {
            setError('Select at least one LDDAP record.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            // An existing ACIC is added to; otherwise the next number is opened here rather
            // than on open, so a cancelled dialog leaves no empty ACIC behind. Either way the
            // server re-derives and locks, so the sequence cannot skip or collide.
            const target = acic ?? (await AcicApi.create());
            onAssigned(await AcicApi.assignLddaps(target.id, [...selected]));
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    const allShownSelected = visible.length > 0 && visible.every((l) => selected.has(l.id));

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="lddap-assign-title"
        >
            <div
                className="card flex max-h-[92vh] w-full max-w-3xl flex-col p-6"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Assign to ACIC</span>
                        <h2
                            id="lddap-assign-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            ACIC #{nextNumber ?? '—'}
                        </h2>
                        <p className="mt-1 text-xs text-subtle">
                            {acic
                                ? `Adding to this ACIC — it already carries ${acic.lddap_count ?? acic.lddaps?.length ?? 0} LDDAP record(s).`
                                : 'Next in sequence — opened when you assign.'}
                        </p>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    Choose the LDDAP records to put on this ACIC. Only{' '}
                    <span className="font-semibold text-fg">approved</span> LDDAP records that aren’t
                    already on an ACIC are listed. Many can share one ACIC number.
                </p>

                <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                    <div className="relative mb-3">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                        <input
                            type="search"
                            className="field !pl-10"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder="Filter by LDDAP, OBJ or check number…"
                            aria-label="Filter LDDAP records"
                        />
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto rounded-xs border border-line">
                        {lddaps === null ? (
                            <div className="p-6">
                                <Spinner />
                            </div>
                        ) : visible.length === 0 ? (
                            <p className="p-6 text-center text-sm text-subtle">
                                {lddaps.length === 0
                                    ? 'No approved LDDAP records are waiting for an ACIC.'
                                    : 'No LDDAP matches that filter.'}
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
                                        <th className="px-3 py-2 font-semibold">Check No.</th>
                                        <th className="px-3 py-2 font-semibold">LDDAP No.</th>
                                        <th className="px-3 py-2 font-semibold">OBJ No.</th>
                                        <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visible.map((l) => (
                                        <tr
                                            key={l.id}
                                            className="cursor-pointer border-b border-line/60 last:border-0 hover:bg-well"
                                            onClick={() => toggle(l.id)}
                                        >
                                            <td className="px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    checked={selected.has(l.id)}
                                                    onChange={() => toggle(l.id)}
                                                    onClick={(e) => e.stopPropagation()}
                                                    aria-label={`Select LDDAP ${l.lddap_no}`}
                                                />
                                            </td>
                                            <td className="px-3 py-2 font-display font-bold text-fg">
                                                #{l.check_no}
                                            </td>
                                            <td className="px-3 py-2 text-muted">{l.lddap_no}</td>
                                            <td className="px-3 py-2 text-muted">{l.obj_no ?? '—'}</td>
                                            <td className="px-3 py-2 text-right font-mono text-muted">
                                                {formatMoney(l.amount)}
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
                                disabled={busy || selected.size === 0}
                            >
                                <ListChecks className="h-4 w-4" />
                                {busy ? 'Assigning…' : `Assign to ACIC #${nextNumber ?? ''}`}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}
