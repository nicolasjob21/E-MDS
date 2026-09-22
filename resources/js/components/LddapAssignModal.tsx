import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { X, ListChecks, Search, Hash, RefreshCw } from 'lucide-react';
import { AcicApi, LddapApi, toApiError } from '../lib/api';
import type { Acic, Lddap, NextCheckNumbers } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, Spinner } from './ui';

interface Props {
    /** Pre-select this LDDAP when the modal is opened from a row action. */
    preselect?: Lddap | null;
    /**
     * Put the records on **this** ACIC — an existing one that is still open or used. Omit it
     * to let the user type the ACIC number (prefilled with the next in the series).
     */
    acic?: Acic | null;
    onClose: () => void;
    onAssigned: (acic: Acic) => void;
}

/**
 * "Assign LDDAP to ACIC" — put approved LDDAP records that have no ACIC yet onto one.
 *
 * Going on an ACIC is when a record takes its **check number**. N ticked records take a block
 * of N **consecutive** numbers — the first run long enough, searching up from the lowest unused;
 * a run broken by a used number is skipped whole and its free numbers stay for a smaller batch
 * — in the order ticked. The dialog previews the block (refetched as the selection changes) and
 * sends it with the save. If someone else took any of it first, the server refuses — "Check
 * number X is already used. Please refresh and try again." — and the block is recomputed.
 *
 * Many records may share one ACIC number. The number is typed (or fixed, when opened from an
 * ACIC); it has to be an existing open ACIC or the next in the ACIC series — never invented.
 */
export default function LddapAssignModal({ preselect, acic, onClose, onAssigned }: Props) {
    const [lddaps, setLddaps] = useState<Lddap[] | null>(null);
    const [pool, setPool] = useState<NextCheckNumbers | null>(null);
    const [nextAcic, setNextAcic] = useState<number | null>(null);
    const [acicNo, setAcicNo] = useState<string>(acic ? String(acic.acic_number) : '');
    // Ticked, in the order they were ticked — that is the order the numbers go out in.
    const [selected, setSelected] = useState<number[]>(preselect ? [preselect.id] : []);
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const [linkable, nextNo] = await Promise.all([
                LddapApi.linkable(),
                acic ? Promise.resolve(null) : AcicApi.next(),
            ]);
            setLddaps(linkable);
            setNextAcic(nextNo);
            // Prefill the ACIC number with the next in the series, but only if the user has
            // not typed something already.
            if (!acic && nextNo !== null) setAcicNo((cur) => cur || String(nextNo));
        } catch (err) {
            setError(toApiError(err).message);
            setLddaps([]);
        }
    }, [acic]);

    useEffect(() => {
        void load();
    }, [load]);

    /**
     * The block of numbers this many records would take: N consecutive, first run from the
     * lowest unused. It depends on N, so it is refetched whenever the selection count changes
     * (and on demand after a refused save). The sequence guard drops a slow response that
     * arrives after a newer one.
     */
    const previewSeq = useRef(0);
    const refreshPreview = useCallback(async (count: number) => {
        const seq = ++previewSeq.current;
        try {
            const next = await LddapApi.nextNumbers(Math.max(1, count));
            if (seq === previewSeq.current) setPool(next);
        } catch (err) {
            if (seq === previewSeq.current) setError(toApiError(err).message);
        }
    }, []);

    useEffect(() => {
        void refreshPreview(selected.length);
    }, [selected.length, refreshPreview]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    // The filter matches the LDDAP number only.
    const visible = useMemo(() => {
        const term = filter.trim().toLowerCase();
        if (!term) return lddaps ?? [];
        return (lddaps ?? []).filter((l) => l.lddap_no.toLowerCase().includes(term));
    }, [lddaps, filter]);

    /** The check number each ticked record will get: the Nth unused, by tick order. */
    const previewFor = useMemo(() => {
        const map = new Map<number, number | undefined>();
        selected.forEach((id, i) => map.set(id, pool?.numbers[i]));
        return map;
    }, [selected, pool]);

    const total = useMemo(
        () =>
            (lddaps ?? [])
                .filter((l) => selected.includes(l.id))
                .reduce((sum, l) => sum + (Number(l.amount) || 0), 0),
        [lddaps, selected],
    );

    // No run of `selected.length` consecutive numbers exists in the series.
    const short = pool !== null && selected.length > 0 && pool.numbers.length < selected.length;

    function toggle(id: number) {
        setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    }

    function toggleAllVisible() {
        const allShown = visible.length > 0 && visible.every((l) => selected.includes(l.id));
        setSelected((prev) =>
            allShown
                ? prev.filter((id) => !visible.some((l) => l.id === id))
                : [...prev, ...visible.filter((l) => !prev.includes(l.id)).map((l) => l.id)],
        );
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        if (selected.length === 0) {
            setError('Select at least one LDDAP record.');
            return;
        }
        if (!acic && !acicNo.trim()) {
            setError('Enter the ACIC number.');
            return;
        }
        if (short) {
            setError(
                `There is no run of ${selected.length} consecutive unused check numbers in the series. Select fewer records, or ask an admin to register a new range.`,
            );
            return;
        }

        const expected = selected.map((id) => previewFor.get(id) as number);

        setBusy(true);
        try {
            onAssigned(
                acic
                    ? await AcicApi.assignLddaps(acic.id, selected, expected)
                    : await LddapApi.assignToAcicNumber(Number(acicNo), selected, expected),
            );
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
            // The numbers may have been taken under us — recompute the block and the list.
            void refreshPreview(selected.length);
            void load();
        }
    }

    const allShownSelected = visible.length > 0 && visible.every((l) => selected.includes(l.id));

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
                            Assign LDDAP to ACIC
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    Only <span className="font-semibold text-fg">approved</span> LDDAP records with no ACIC
                    yet are listed. Many can share one ACIC number. The records you tick take a{' '}
                    <span className="font-semibold text-fg">consecutive block</span> of check numbers — the
                    first run long enough, searching up from the lowest unused — in the order ticked, shown
                    beside each before you confirm.
                </p>

                <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                    <div className="mb-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label htmlFor="assign-acic-no" className="label">
                                ACIC #
                            </label>
                            <div className="relative">
                                <Hash className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                                <input
                                    id="assign-acic-no"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    className="field !pl-10 font-display font-bold"
                                    value={acicNo}
                                    onChange={(e) => setAcicNo(e.target.value)}
                                    disabled={!!acic}
                                    required
                                />
                            </div>
                            <p className="mt-1 text-xs text-subtle">
                                {acic
                                    ? 'Adding to this ACIC.'
                                    : nextAcic !== null
                                      ? `An existing open ACIC, or the next in the series (#${nextAcic}).`
                                      : 'An existing open ACIC number.'}
                            </p>
                        </div>
                        <div>
                            <label htmlFor="assign-filter" className="label">
                                Find a record
                            </label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                                <input
                                    id="assign-filter"
                                    type="search"
                                    className="field !pl-10"
                                    value={filter}
                                    onChange={(e) => setFilter(e.target.value)}
                                    placeholder="Filter by LDDAP no.…"
                                />
                            </div>
                            {pool !== null && (
                                <p className={`mt-1 text-xs ${short ? 'text-danger-fg' : 'text-subtle'}`}>
                                    {pool.available} check number{pool.available === 1 ? '' : 's'} still unused
                                    {selected.length > 0 && pool.numbers.length > 0
                                        ? ` — this batch takes #${pool.numbers[0]}–#${pool.numbers[pool.numbers.length - 1]}`
                                        : short
                                          ? ` — no run of ${selected.length} consecutive`
                                          : ''}
                                    .
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto rounded-xs border border-line">
                        {lddaps === null || pool === null ? (
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
                            <table className="w-full min-w-[40rem] text-left text-sm">
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
                                        <th className="px-3 py-2 font-semibold">LDDAP No.</th>
                                        <th className="px-3 py-2 font-semibold">Payee</th>
                                        <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                        <th className="px-3 py-2 text-right font-semibold">Check No.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visible.map((l) => {
                                        const ticked = selected.includes(l.id);
                                        const number = previewFor.get(l.id);
                                        return (
                                            <tr
                                                key={l.id}
                                                className={`cursor-pointer border-b border-line/60 last:border-0 hover:bg-well ${
                                                    ticked ? 'bg-brand-500/5' : ''
                                                }`}
                                                onClick={() => toggle(l.id)}
                                            >
                                                <td className="px-3 py-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={ticked}
                                                        onChange={() => toggle(l.id)}
                                                        onClick={(e) => e.stopPropagation()}
                                                        aria-label={`Select ${l.lddap_no}`}
                                                    />
                                                </td>
                                                <td className="px-3 py-2 font-display font-bold text-fg">
                                                    {l.lddap_no}
                                                    {l.obj_no && (
                                                        <span className="ml-2 font-mono text-xs font-normal text-subtle">
                                                            {l.obj_no}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-muted">{l.payee_name ?? '—'}</td>
                                                <td className="px-3 py-2 text-right font-mono text-muted">
                                                    {formatMoney(l.amount)}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {ticked ? (
                                                        number !== undefined ? (
                                                            <span className="inline-flex items-center gap-1 font-display font-bold text-brandink">
                                                                <Hash className="h-3 w-3 text-subtle" />
                                                                {number}
                                                            </span>
                                                        ) : (
                                                            <span className="text-xs text-danger-fg">No block</span>
                                                        )
                                                    ) : (
                                                        <span className="text-xs text-subtle">—</span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
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
                            {selected.length} selected · total{' '}
                            <span className="font-mono font-semibold text-fg">{formatMoney(total)}</span>
                        </span>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                className="btn btn-ghost"
                                onClick={() => {
                                    void refreshPreview(selected.length);
                                    void load();
                                }}
                                disabled={busy}
                                title="Recompute the check-number block"
                            >
                                <RefreshCw className="h-4 w-4" />
                                Refresh
                            </button>
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={busy || selected.length === 0 || short}
                            >
                                <ListChecks className="h-4 w-4" />
                                {busy ? 'Assigning…' : `Assign to ACIC #${acicNo || '—'}`}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}
