import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { X, Repeat, ArrowRight } from 'lucide-react';
import { AcicApi, LddapApi, toApiError } from '../lib/api';
import type { Acic, Cheque, Lddap } from '../lib/types';
import { formatMoney } from '../lib/format';
import { Alert, Spinner } from './ui';

interface Props {
    acic: Acic;
    onClose: () => void;
    onReassigned: (acic: Acic) => void;
}

type Kind = 'cheque' | 'lddap';

/** One selectable record, flattened so cheques and LDDAPs render through the same list. */
interface Option {
    id: number;
    kind: Kind;
    /** What identifies it — "#1042" or "LDDAP-0001". */
    title: string;
    subtitle: string;
    amount: string;
}

function chequeOption(c: Cheque): Option {
    return {
        id: c.id,
        kind: 'cheque',
        title: `#${c.cheque_number}`,
        subtitle: c.payee_name ?? '—',
        amount: c.amount ?? '0',
    };
}

function lddapOption(l: Lddap): Option {
    return {
        id: l.id,
        kind: 'lddap',
        title: l.lddap_no,
        subtitle: l.payee_name ?? `Check ${l.check_no ?? '—'}`,
        amount: l.amount,
    };
}

function OptionList({
    options,
    selected,
    onSelect,
    name,
    empty,
}: {
    options: Option[];
    selected: number | null;
    onSelect: (id: number) => void;
    name: string;
    empty: string;
}) {
    if (options.length === 0) {
        return (
            <p className="rounded-xs border border-line bg-well p-4 text-center text-sm text-subtle">
                {empty}
            </p>
        );
    }

    return (
        <div className="max-h-48 space-y-1.5 overflow-y-auto rounded-xs border border-line p-1.5">
            {options.map((o) => (
                <label
                    key={`${o.kind}-${o.id}`}
                    className={`flex cursor-pointer items-center gap-3 rounded-xs border p-2.5 transition-colors ${
                        selected === o.id ? 'border-brand-400/50 bg-brand-500/10' : 'border-line hover:bg-well'
                    }`}
                >
                    <input
                        type="radio"
                        name={name}
                        checked={selected === o.id}
                        onChange={() => onSelect(o.id)}
                        aria-label={o.title}
                    />
                    <span className="min-w-0 flex-1">
                        <span className="font-display text-sm font-bold text-fg">{o.title}</span>
                        <span className="mt-0.5 block truncate text-xs text-muted">{o.subtitle}</span>
                    </span>
                    <span className="font-mono text-xs text-muted">{formatMoney(o.amount)}</span>
                </label>
            ))}
        </div>
    );
}

/**
 * "Re-assign" — swap one record on an ACIC for another of the same kind.
 *
 * The record coming off is **released back to the pool**, so it can go on a later ACIC rather
 * than being stranded here; the one going on takes its place. Both halves happen in one locked
 * request, so the ACIC is never briefly short of what it carries.
 */
export default function AcicReassignModal({ acic, onClose, onReassigned }: Props) {
    const onAcic = useMemo<Option[]>(
        () => [
            ...(acic.cheques ?? []).map(chequeOption),
            ...(acic.lddaps ?? []).map(lddapOption),
        ],
        [acic],
    );

    const [releaseId, setReleaseId] = useState<number | null>(onAcic[0]?.id ?? null);
    const [assignId, setAssignId] = useState<number | null>(null);
    const [pool, setPool] = useState<Option[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const release = onAcic.find((o) => o.id === releaseId) ?? null;
    const kind: Kind = release?.kind ?? 'cheque';

    // The replacement has to be the same kind as what is coming off — a cheque for a cheque,
    // an LDDAP for an LDDAP — so the pool is reloaded whenever that changes.
    const loadPool = useCallback(async () => {
        setPool(null);
        setAssignId(null);
        try {
            setPool(
                kind === 'cheque'
                    ? (await AcicApi.linkableCheques()).map(chequeOption)
                    : (await LddapApi.linkable()).map(lddapOption),
            );
        } catch (err) {
            setError(toApiError(err).message);
            setPool([]);
        }
    }, [kind]);

    useEffect(() => {
        void loadPool();
    }, [loadPool]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (releaseId === null) {
            setError('Choose the record to take off this ACIC.');
            return;
        }
        if (assignId === null) {
            setError('Choose the record to put on in its place.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            onReassigned(await AcicApi.reassign(acic.id, { type: kind, releaseId, assignId }));
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
            aria-labelledby="acic-reassign-title"
        >
            <div
                className="card flex max-h-[92vh] w-full max-w-lg flex-col overflow-y-auto p-6"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Re-assign</span>
                        <h2
                            id="acic-reassign-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            ACIC #{acic.acic_number}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    Swap one record on this ACIC for another. The one you take off is{' '}
                    <span className="font-semibold text-fg">released back to the pool</span> and can be
                    put on a future ACIC.
                </p>

                <form onSubmit={handleSubmit}>
                    <fieldset className="mb-4">
                        <legend className="label mb-2">Take off this ACIC</legend>
                        <OptionList
                            options={onAcic}
                            selected={releaseId}
                            onSelect={setReleaseId}
                            name="acic-release"
                            empty="This ACIC carries nothing to re-assign."
                        />
                    </fieldset>

                    <div className="mb-4 flex items-center gap-2 text-xs uppercase tracking-widest text-subtle">
                        <ArrowRight className="h-3.5 w-3.5" />
                        Put on in its place
                    </div>

                    <fieldset className="mb-4">
                        <legend className="sr-only">Put on in its place</legend>
                        {pool === null ? (
                            <Spinner />
                        ) : (
                            <OptionList
                                options={pool}
                                selected={assignId}
                                onSelect={setAssignId}
                                name="acic-assign"
                                empty={
                                    kind === 'cheque'
                                        ? 'No approved cheque is free to take its place.'
                                        : 'No approved LDDAP is free to take its place.'
                                }
                            />
                        )}
                    </fieldset>

                    {error && <Alert kind="error">{error}</Alert>}

                    <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn btn-primary"
                            disabled={busy || releaseId === null || assignId === null}
                        >
                            <Repeat className="h-4 w-4" />
                            {busy ? 'Re-assigning…' : 'Re-assign'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
