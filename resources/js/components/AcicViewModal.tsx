import { useCallback, useEffect, useMemo, useState } from 'react';
import { X, History } from 'lucide-react';
import { AcicApi, AcicTellerApi, toApiError } from '../lib/api';
import type { Acic, AcicHistoryStep } from '../lib/types';
import { formatDate, formatDateTime, formatManila, formatMoney } from '../lib/format';
import { Alert, Spinner, AcicDisplayStatusBadge } from './ui';

interface Props {
    acicId: number;
    onClose: () => void;
}

/** One line of the history, whatever it came from. */
interface Step {
    key: string;
    label: string;
    who: string | null;
    at: string | null;
    note: string | null;
}

/** How each teller step reads in the history. */
const ACTION_LABELS: Record<string, string> = {
    forwarded_to_teller: 'Forwarded to the tellers — Pending',
    accepted: 'Accepted',
    forwarded_to_land_bank: 'Forwarded to LBP',
    forwarded_to_payee: 'Forwarded to Payee',
    completed: 'Completed',
    re_completed: 'Completed again',
    rts: 'RTS',
    returned_by_bank: 'Returned by Bank',
    returned_to_admin: 'Returned to Admin',
};

/**
 * The read-only view of an ACIC, for the teller's tables: its number, type, status and total,
 * every cheque or LDDAP on it (number, DV number, payee, amount), and its history — when it was
 * opened and used, then every step with the tellers. Nothing here changes the ACIC.
 */
export default function AcicViewModal({ acicId, onClose }: Props) {
    const [acic, setAcic] = useState<Acic | null>(null);
    const [history, setHistory] = useState<AcicHistoryStep[]>([]);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const [record, steps] = await Promise.all([AcicApi.show(acicId), AcicTellerApi.history(acicId)]);
            setAcic(record);
            setHistory(steps);
        } catch (err) {
            setError(toApiError(err).message);
        }
    }, [acicId]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const rows = useMemo(
        () => [
            ...(acic?.cheques ?? []).map((c) => ({
                key: `c-${c.id}`,
                number: `Cheque #${c.cheque_number}`,
                sub: null as string | null,
                dv: null as string | null,
                receipt: c.payee_receipt?.received_by
                    ? `Received by ${c.payee_receipt.received_by}${c.payee_receipt.unit ? ` (${c.payee_receipt.unit})` : ''} · ${formatDate(c.payee_receipt.date_received)}`
                    : null,
                payee: c.payee_name ?? '—',
                amount: c.amount,
            })),
            ...(acic?.lddaps ?? []).map((l) => ({
                key: `l-${l.id}`,
                number: l.lddap_no,
                sub: l.check_no ? `Check #${l.check_no}` : null,
                dv: l.dv_no ?? null,
                receipt: null as string | null,
                payee: l.payee_name ?? '—',
                amount: l.amount,
            })),
        ],
        [acic],
    );

    const total = rows.reduce((sum, r) => sum + (Number(r.amount) || 0), 0);

    // Opened and used come from the ACIC itself; everything after, from its teller history.
    const steps = useMemo<Step[]>(() => {
        if (!acic) return [];
        const own: Step[] = [
            { key: 'created', label: 'Opened', who: acic.created_by?.name ?? null, at: acic.created_at ?? null, note: null },
            ...(acic.used_at ? [{ key: 'used', label: 'Used', who: acic.used_by?.name ?? null, at: acic.used_at, note: null }] : []),
        ];
        return [
            ...own,
            ...history.map((h) => ({
                key: `h-${h.id}`,
                label: ACTION_LABELS[h.action] ?? h.to_status_label ?? h.action,
                who: h.user?.name ?? null,
                at: h.created_at,
                note: h.note,
            })),
        ];
    }, [acic, history]);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-labelledby="acic-view-title"
        >
            <div className="card flex max-h-[92vh] w-full max-w-3xl flex-col p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">ACIC</span>
                        <h2 id="acic-view-title" className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg">
                            {acic ? `#${acic.acic_number}` : 'ACIC'}
                        </h2>
                    </div>
                    <button type="button" className="btn btn-ghost !px-2" onClick={onClose} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto">
                    {error ? (
                        <Alert kind="error">{error}</Alert>
                    ) : acic === null ? (
                        <Spinner />
                    ) : (
                        <>
                            <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-4">
                                {[
                                    ['ACIC No.', `#${acic.acic_number}`],
                                    ['Type', acic.type === 'lddap' ? 'LDDAP' : acic.type === 'cheque' ? 'Cheque' : '—'],
                                ].map(([label, value]) => (
                                    <div key={label} className="bg-card p-3">
                                        <dt className="text-xs uppercase tracking-wider text-subtle">{label}</dt>
                                        <dd className="mt-1 font-display font-bold text-fg">{value}</dd>
                                    </div>
                                ))}
                                <div className="bg-card p-3">
                                    <dt className="text-xs uppercase tracking-wider text-subtle">Status</dt>
                                    <dd className="mt-1">
                                        <AcicDisplayStatusBadge status={acic.display_status} label={acic.display_status_label} />
                                    </dd>
                                </div>
                                <div className="bg-card p-3">
                                    <dt className="text-xs uppercase tracking-wider text-subtle">Total Amount</dt>
                                    <dd className="mt-1 font-mono font-semibold text-fg">{formatMoney(total)}</dd>
                                </div>
                                {/* When it was forwarded to LBP, Philippine time; "—" before then. */}
                                <div className="col-span-2 bg-card p-3 sm:col-span-4">
                                    <dt className="text-xs uppercase tracking-wider text-subtle">Forwarded to Bank</dt>
                                    <dd className="mt-1 text-fg">{formatManila(acic.forwarded_to_land_bank_at)}</dd>
                                </div>
                            </dl>

                            <section>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-subtle">
                                    {acic.type === 'lddap' ? 'LDDAPs' : 'Cheques'} on it ({rows.length})
                                </h3>
                                <div className="overflow-x-auto rounded-xs border border-line">
                                    <table className="w-full min-w-[32rem] text-left text-sm">
                                        <thead className="bg-well">
                                            <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                                <th className="px-3 py-2 font-semibold">Number</th>
                                                <th className="px-3 py-2 font-semibold">DV No.</th>
                                                <th className="px-3 py-2 font-semibold">Payee</th>
                                                <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rows.length === 0 ? (
                                                <tr>
                                                    <td colSpan={4} className="px-3 py-4 text-center text-subtle">
                                                        Nothing on this ACIC.
                                                    </td>
                                                </tr>
                                            ) : (
                                                rows.map((r) => (
                                                    <tr key={r.key} className="border-b border-line/60 last:border-0">
                                                        <td className="px-3 py-2 font-display font-bold text-fg">
                                                            {r.number}
                                                            {r.sub && <span className="mt-0.5 block text-xs font-normal text-subtle">{r.sub}</span>}
                                                        </td>
                                                        <td className="px-3 py-2 font-mono text-muted">{r.dv ?? '—'}</td>
                                                        <td className="px-3 py-2 text-muted">
                                                            {r.payee}
                                                            {r.receipt && <span className="mt-0.5 block text-xs text-subtle">{r.receipt}</span>}
                                                        </td>
                                                        <td className="px-3 py-2 text-right font-mono text-muted">{formatMoney(r.amount)}</td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section>
                                <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-subtle">
                                    <History className="h-4 w-4" />
                                    History
                                </h3>
                                <ol className="space-y-2">
                                    {steps.map((step) => (
                                        <li key={step.key} className="rounded-xs border border-line bg-well p-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="font-display text-sm font-semibold text-fg">{step.label}</span>
                                                <span className="text-xs text-subtle">
                                                    {step.who ?? '—'} · {step.at ? formatDateTime(step.at) : '—'}
                                                </span>
                                            </div>
                                            {step.note && <p className="mt-1.5 text-sm text-fg">{step.note}</p>}
                                        </li>
                                    ))}
                                </ol>
                            </section>
                        </>
                    )}
                </div>

                <div className="mt-5 flex justify-end">
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}
