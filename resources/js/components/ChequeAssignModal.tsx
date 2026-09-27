import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { X, ListChecks, Search, Hash, History } from 'lucide-react';
import { AcicApi, ChequeApi, toApiError } from '../lib/api';
import type { Acic, Cheque } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, Spinner } from './ui';

interface Props {
    /** Tick this cheque to start with, when the dialog is opened from its row. */
    preselect?: Cheque | null;
    onClose: () => void;
    onAssigned: (acic: Acic) => void;
}

/**
 * "Assign Cheque to ACIC" — put For Signature cheques on one ACIC number.
 *
 * Many cheques may share one ACIC number. The number is typed: an existing ACIC that still
 * takes cheques, or the next one in the series (prefilled), which is opened on save — never an
 * invented one. Only For Signature cheques not yet on an ACIC are offered; the server re-checks
 * everything under a lock.
 *
 * Opened on the replacement of a spoiled cheque that was on an ACIC, it first offers a choice:
 * **Use previous ACIC** — the replacement takes the spoiled cheque's place there, allowed only
 * while that ACIC is still with the admin (otherwise disabled, saying why) — or **Assign to a
 * new ACIC**, the usual form below.
 */
export default function ChequeAssignModal({ preselect = null, onClose, onAssigned }: Props) {
    const [cheques, setCheques] = useState<Cheque[] | null>(null);
    const [nextAcic, setNextAcic] = useState<number | null>(null);
    const [acicNo, setAcicNo] = useState('');
    const [selected, setSelected] = useState<number[]>(preselect ? [preselect.id] : []);
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    // A replacement of a spoiled cheque that was on an ACIC: use that ACIC, or a new one.
    const previous = preselect?.previous_acic ?? null;
    const [choice, setChoice] = useState<'previous' | 'new'>(previous?.allowed ? 'previous' : 'new');
    const usingPrevious = previous !== null && previous.allowed && choice === 'previous';

    const load = useCallback(async () => {
        try {
            const [linkable, nextNo] = await Promise.all([AcicApi.linkableCheques(), AcicApi.next()]);
            setCheques(linkable);
            setNextAcic(nextNo);
            // Prefill with the next in the series, unless something has been typed already.
            if (nextNo !== null) setAcicNo((cur) => cur || String(nextNo));
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

    // The filter matches the cheque number or the payee.
    const visible = useMemo(() => {
        const term = filter.trim().toLowerCase();
        if (!term) return cheques ?? [];
        return (cheques ?? []).filter(
            (c) => String(c.cheque_number).includes(term) || (c.payee_name ?? '').toLowerCase().includes(term),
        );
    }, [cheques, filter]);

    const total = useMemo(
        () =>
            (cheques ?? []).filter((c) => selected.includes(c.id)).reduce((sum, c) => sum + (Number(c.amount) || 0), 0),
        [cheques, selected],
    );

    function toggle(id: number) {
        setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    }

    function toggleAllVisible() {
        const allShown = visible.length > 0 && visible.every((c) => selected.includes(c.id));
        setSelected((prev) =>
            allShown
                ? prev.filter((id) => !visible.some((c) => c.id === id))
                : [...prev, ...visible.filter((c) => !prev.includes(c.id)).map((c) => c.id)],
        );
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        if (usingPrevious && preselect) {
            setBusy(true);
            try {
                onAssigned(await ChequeApi.assignToPreviousAcic(preselect.id, preselect.status));
            } catch (err) {
                const apiErr = toApiError(err);
                setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
                setBusy(false);
            }
            return;
        }
        if (selected.length === 0) {
            setError('Select at least one cheque.');
            return;
        }
        if (!acicNo.trim()) {
            setError('Enter the ACIC number.');
            return;
        }

        setBusy(true);
        try {
            onAssigned(await ChequeApi.assignToAcicNumber(Number(acicNo), selected));
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
            // Someone may have taken a cheque or the number under us.
            void load();
        }
    }

    const allShownSelected = visible.length > 0 && visible.every((c) => selected.includes(c.id));

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="cheque-assign-title"
        >
            <div className="card flex max-h-[92vh] w-full max-w-3xl flex-col p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Assign to ACIC</span>
                        <h2
                            id="cheque-assign-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            Assign Cheque to ACIC
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    Only <span className="font-semibold text-fg">For Signature</span> cheques with no ACIC yet are
                    listed. <span className="font-semibold text-fg">Many cheques may share one ACIC number</span> — tick
                    as many as belong on it.
                </p>

                <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                    {previous && (
                        <fieldset className="mb-4">
                            <legend className="label">
                                Cheque #{preselect?.cheque_number} replaces spoiled cheque #
                                {previous.spoiled_cheque_number}
                            </legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <label
                                    className={`flex gap-3 rounded-xs border p-3 text-sm ${
                                        !previous.allowed
                                            ? 'cursor-not-allowed border-line opacity-60'
                                            : choice === 'previous'
                                              ? 'cursor-pointer border-brand-400 bg-brand-500/10'
                                              : 'cursor-pointer border-line hover:border-brand-400/40'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="cheque-assign-choice"
                                        className="mt-0.5"
                                        checked={choice === 'previous'}
                                        onChange={() => setChoice('previous')}
                                        disabled={!previous.allowed}
                                    />
                                    <span>
                                        <span className="flex items-center gap-1.5 font-semibold text-fg">
                                            <History className="h-4 w-4 text-brandink" />
                                            Use previous ACIC (#
                                            {previous.acic_number})
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted">
                                            {previous.allowed
                                                ? `Takes spoiled cheque #${previous.spoiled_cheque_number}'s place on that ACIC.`
                                                : previous.reason}
                                        </span>
                                    </span>
                                </label>
                                <label
                                    className={`flex cursor-pointer gap-3 rounded-xs border p-3 text-sm ${
                                        choice === 'new'
                                            ? 'border-brand-400 bg-brand-500/10'
                                            : 'border-line hover:border-brand-400/40'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="cheque-assign-choice"
                                        className="mt-0.5"
                                        checked={choice === 'new'}
                                        onChange={() => setChoice('new')}
                                    />
                                    <span>
                                        <span className="flex items-center gap-1.5 font-semibold text-fg">
                                            <ListChecks className="h-4 w-4 text-brandink" />
                                            Assign to a new ACIC
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted">
                                            Type an ACIC number and tick cheques, as usual.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                    )}

                    {!usingPrevious && (
                        <>
                            <div className="mb-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label htmlFor="cheque-assign-acic-no" className="label">
                                        ACIC #
                                    </label>
                                    <div className="relative">
                                        <Hash className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                                        <input
                                            id="cheque-assign-acic-no"
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            className="field !pl-10 font-display font-bold"
                                            value={acicNo}
                                            onChange={(e) => setAcicNo(e.target.value)}
                                            required
                                        />
                                    </div>
                                    <p className="mt-1 text-xs text-subtle">
                                        {nextAcic !== null
                                            ? `An existing ACIC that still takes cheques, or the next in the series (#${nextAcic}).`
                                            : 'An existing ACIC that still takes cheques.'}
                                    </p>
                                </div>
                                <div>
                                    <label htmlFor="cheque-assign-filter" className="label">
                                        Find a cheque
                                    </label>
                                    <div className="relative">
                                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                                        <input
                                            id="cheque-assign-filter"
                                            type="search"
                                            className="field !pl-10"
                                            value={filter}
                                            onChange={(e) => setFilter(e.target.value)}
                                            placeholder="Cheque no. or payee…"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="min-h-0 flex-1 overflow-auto rounded-xs border border-line">
                                {cheques === null ? (
                                    <div className="p-6">
                                        <Spinner />
                                    </div>
                                ) : visible.length === 0 ? (
                                    <p className="p-6 text-center text-sm text-subtle">
                                        {cheques.length === 0
                                            ? 'No For Signature cheques are waiting for an ACIC.'
                                            : 'No cheque matches that filter.'}
                                    </p>
                                ) : (
                                    <table className="w-full min-w-[32rem] text-left text-sm">
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
                                                <th className="px-3 py-2 font-semibold">Cheque No.</th>
                                                <th className="px-3 py-2 font-semibold">Payee</th>
                                                <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {visible.map((c) => {
                                                const ticked = selected.includes(c.id);
                                                return (
                                                    <tr
                                                        key={c.id}
                                                        className={`cursor-pointer border-b border-line/60 last:border-0 hover:bg-well ${
                                                            ticked ? 'bg-brand-500/5' : ''
                                                        }`}
                                                        onClick={() => toggle(c.id)}
                                                    >
                                                        <td className="px-3 py-2">
                                                            <input
                                                                type="checkbox"
                                                                checked={ticked}
                                                                onChange={() => toggle(c.id)}
                                                                onClick={(e) => e.stopPropagation()}
                                                                aria-label={`Select cheque #${c.cheque_number}`}
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
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                )}
                            </div>
                        </>
                    )}

                    {error && (
                        <div className="mt-4">
                            <Alert kind="error">{error}</Alert>
                        </div>
                    )}

                    <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <span className="text-sm text-muted" aria-live="polite">
                            {usingPrevious ? (
                                <>
                                    Cheque #{preselect?.cheque_number} ·{' '}
                                    <span className="font-mono font-semibold text-fg">
                                        {formatMoney(preselect?.amount)}
                                    </span>
                                </>
                            ) : (
                                <>
                                    {selected.length} selected · total{' '}
                                    <span className="font-mono font-semibold text-fg">{formatMoney(total)}</span>
                                </>
                            )}
                        </span>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={busy || (!usingPrevious && selected.length === 0)}
                            >
                                <ListChecks className="h-4 w-4" />
                                {busy
                                    ? 'Assigning…'
                                    : usingPrevious
                                      ? `Use previous ACIC #${previous?.acic_number}`
                                      : `Assign to ACIC #${acicNo || '—'}`}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}
