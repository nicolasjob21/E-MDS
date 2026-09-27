import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { X, Send, CheckCircle2, RotateCcw } from 'lucide-react';
import { AcicTellerApi, toApiError } from '../lib/api';
import type { Acic, RtsOutcome, TellerForwardTo } from '../lib/types';
import { formatDate, formatManila, formatMoney } from '../lib/format';
import { Alert } from './ui';

interface Props {
    acic: Acic;
    /** Forward (to LBP or the payee — the button already chose), or the Action on a forwarded ACIC. */
    step: 'forward' | 'action';
    /** Forward only: where the teller's button sends it. */
    forwardTo?: TellerForwardTo;
    onClose: () => void;
    onDone: (acic: Acic, message: string) => void;
}

/** One check on the ACIC, of either kind, keyed the way the server keys the Action's fields. */
interface CheckRow {
    key: string;
    label: string;
    payee: string;
    amount: string | null | undefined;
    isCheque: boolean;
}

const OUTCOMES: { value: RtsOutcome; label: string; chequeOnly?: boolean }[] = [
    { value: 'completed', label: 'Completed' },
    { value: 'returned', label: 'Returned' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'stale', label: 'Stale', chequeOnly: true },
];

/**
 * The accepting teller's two steps on an ACIC.
 *
 * **Forward** — the row's Forward to LBP / Forward to Payee button picked where; this only asks
 * the teller to confirm. **Action** (once it is out) —
 * **Completed**, which for an ACIC forwarded to the payee also takes who received each check and
 * when; or **RTS**, a required reason and a status for each check (Completed, Returned,
 * Cancelled, or — cheques only — Stale). The server checks it is the accepting teller.
 */
export default function TellerActionModal({ acic, step, forwardTo, onClose, onDone }: Props) {
    const to = forwardTo ?? null;
    const [action, setAction] = useState<'completed' | 'rts' | null>(null);
    const [outcomes, setOutcomes] = useState<Record<string, RtsOutcome | ''>>({});
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    // Forward to LBP shows "now" in Philippine time; the server stamps its own time on confirm.
    const [now, setNow] = useState(() => new Date());
    const [error, setError] = useState('');

    // The checks that are out — what the Action settles.
    useEffect(() => {
        if (step !== 'forward' || forwardTo !== 'land_bank') return;
        const timer = setInterval(() => setNow(new Date()), 15000);
        return () => clearInterval(timer);
    }, [step, forwardTo]);

    // Forward to LBP's summary: every record assigned to the ACIC, as it stands.
    const assigned = useMemo(
        () => [
            ...(acic.cheques ?? []).map((c) => ({
                key: `cheque:${c.id}`,
                number: `Check #${c.cheque_number}`,
                sub: null as string | null,
                payee: c.payee_name ?? '—',
                amount: c.amount,
            })),
            ...(acic.lddaps ?? []).map((l) => ({
                key: `lddap:${l.id}`,
                number: l.lddap_no,
                sub: l.check_no ? `Check #${l.check_no}` : null,
                payee: l.payee_name ?? '—',
                amount: l.amount,
            })),
        ],
        [acic],
    );
    const assignedTotal = assigned.reduce((sum, r) => sum + (Number(r.amount) || 0), 0);

    const checks = useMemo<CheckRow[]>(
        () => [
            ...(acic.cheques ?? [])
                .filter((c) => c.status === 'forwarded_to_land_bank' || c.status === 'forwarded_to_payee')
                .map((c) => ({
                    key: `cheque:${c.id}`,
                    label: `Cheque #${c.cheque_number}`,
                    payee: c.payee_name ?? '—',
                    amount: c.amount,
                    isCheque: true,
                })),
            ...(acic.lddaps ?? [])
                .filter((l) => l.status === 'forwarded_to_land_bank' || l.status === 'forwarded_to_payee')
                .map((l) => ({
                    key: `lddap:${l.id}`,
                    label: l.check_no ? `Check #${l.check_no}` : l.lddap_no,
                    payee: l.payee_name ?? l.lddap_no,
                    amount: l.amount,
                    isCheque: false,
                })),
        ],
        [acic],
    );

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    const ready =
        step === 'forward'
            ? to !== null
            : action === 'completed'
              ? true
              : action === 'rts'
                ? reason.trim().length >= 3 && checks.every((c) => outcomes[c.key])
                : false;

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (!ready) return;
        setBusy(true);
        setError('');
        const expected = acic.teller_status ?? undefined;
        try {
            if (step === 'forward' && to) {
                const done = await AcicTellerApi.forward(acic.id, to, expected);
                onDone(done, `ACIC #${acic.acic_number} forwarded ${to === 'land_bank' ? 'to LBP' : 'to the payee'}.`);
            } else if (action === 'completed') {
                onDone(await AcicTellerApi.complete(acic.id, expected), `ACIC #${acic.acic_number} completed.`);
            } else if (action === 'rts') {
                const sent = Object.fromEntries(checks.map((c) => [c.key, outcomes[c.key] as RtsOutcome]));
                onDone(
                    await AcicTellerApi.rts(acic.id, reason.trim(), sent, expected),
                    `ACIC #${acic.acic_number} marked RTS.`,
                );
            }
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    const choice = (selected: boolean) =>
        `flex cursor-pointer gap-3 rounded-xs border p-3 text-sm ${
            selected ? 'border-brand-400 bg-brand-500/10' : 'border-line hover:border-brand-400/40'
        }`;

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="teller-action-title"
        >
            <form
                className="card flex max-h-[92vh] w-full max-w-2xl flex-col p-6"
                onClick={(e) => e.stopPropagation()}
                onSubmit={handleSubmit}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">{step === 'forward' ? 'Forward' : 'Action'}</span>
                        <h2
                            id="teller-action-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            ACIC #{acic.acic_number}
                        </h2>
                        <p className="mt-1 text-sm text-muted">
                            {acic.type_label ?? '—'} · {assigned.length || (acic.total_records ?? 0)} record(s)
                            {step === 'action' && ` · ${acic.teller_status_label}`}
                        </p>
                    </div>
                    <button
                        type="button"
                        className="btn btn-ghost !px-2"
                        onClick={onClose}
                        disabled={busy}
                        aria-label="Close"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {step === 'forward' && to === 'land_bank' ? (
                        <div className="space-y-4">
                            {/* 1. The ACIC as assigned — nothing here is new data. */}
                            <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-3">
                                <div className="bg-card p-3">
                                    <dt className="text-xs uppercase tracking-wider text-subtle">ACIC #</dt>
                                    <dd className="mt-1 font-display font-bold text-fg">#{acic.acic_number}</dd>
                                </div>
                                <div className="bg-card p-3">
                                    <dt className="text-xs uppercase tracking-wider text-subtle">ACIC Date</dt>
                                    <dd className="mt-1 text-fg">{formatDate(acic.created_at)}</dd>
                                </div>
                                <div className="col-span-2 bg-card p-3 sm:col-span-1">
                                    <dt className="text-xs uppercase tracking-wider text-subtle">Items</dt>
                                    <dd className="mt-1 text-fg">
                                        {assigned.length} {acic.type === 'lddap' ? 'LDDAP' : 'cheque'}
                                        {assigned.length === 1 ? '' : 's'}
                                    </dd>
                                </div>
                            </dl>

                            <div className="overflow-x-auto rounded-xs border border-line">
                                <table className="w-full min-w-[28rem] text-left text-sm">
                                    <thead className="bg-well">
                                        <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                            <th className="px-3 py-2 font-semibold">LDDAP No. / Check No.</th>
                                            <th className="px-3 py-2 font-semibold">Payee</th>
                                            <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {assigned.map((r) => (
                                            <tr key={r.key} className="border-b border-line/60 last:border-0">
                                                <td className="px-3 py-2 font-display font-bold text-fg">
                                                    {r.number}
                                                    {r.sub && (
                                                        <span className="block text-xs font-normal text-subtle">
                                                            {r.sub}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-muted">{r.payee}</td>
                                                <td className="px-3 py-2 text-right font-mono text-muted">
                                                    {formatMoney(r.amount)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t border-line">
                                            <td
                                                colSpan={2}
                                                className="px-3 py-2 text-right text-xs uppercase tracking-wider text-subtle"
                                            >
                                                Total amount
                                            </td>
                                            <td className="px-3 py-2 text-right font-mono font-semibold text-fg">
                                                {formatMoney(assignedTotal)}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            {/* 2. The date it goes to LBP — shown now, stamped by the server on confirm. */}
                            <div className="rounded-xs border border-brand-400/40 bg-brand-500/10 p-3">
                                <div className="text-xs uppercase tracking-wider text-subtle">Forwarded to Bank</div>
                                <div className="mt-1 font-display text-lg font-bold text-fg" aria-live="polite">
                                    {formatManila(now)}
                                </div>
                                <p className="mt-1 text-xs text-muted">
                                    Philippine time. The exact time is recorded when you confirm.
                                </p>
                            </div>
                        </div>
                    ) : step === 'forward' ? (
                        <p className="text-sm text-muted">
                            Forward ACIC #{acic.acic_number} {to === 'payee' ? 'to the payee' : 'to LBP'}? You are
                            recorded as forwarding it, now. The Forward buttons are then replaced by Action (Completed /
                            RTS).
                        </p>
                    ) : (
                        <>
                            <fieldset className="mb-4">
                                <legend className="label">What happened</legend>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <label className={choice(action === 'completed')}>
                                        <input
                                            type="radio"
                                            name="teller-action"
                                            className="mt-0.5"
                                            checked={action === 'completed'}
                                            onChange={() => setAction('completed')}
                                        />
                                        <span>
                                            <span className="flex items-center gap-1.5 font-semibold text-fg">
                                                <CheckCircle2 className="h-4 w-4 text-success-fg" />
                                                Completed
                                            </span>
                                            <span className="mt-0.5 block text-xs text-muted">
                                                Closes the ACIC and every check. Final.
                                            </span>
                                        </span>
                                    </label>
                                    <label className={choice(action === 'rts')}>
                                        <input
                                            type="radio"
                                            name="teller-action"
                                            className="mt-0.5"
                                            checked={action === 'rts'}
                                            onChange={() => setAction('rts')}
                                        />
                                        <span>
                                            <span className="flex items-center gap-1.5 font-semibold text-fg">
                                                <RotateCcw className="h-4 w-4 text-amber-400" />
                                                RTS
                                            </span>
                                            <span className="mt-0.5 block text-xs text-muted">
                                                Returned to sender: a reason, and a status for each check.
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </fieldset>

                            {action === 'rts' && (
                                <div className="overflow-x-auto rounded-xs border border-line">
                                    <table className="w-full min-w-[32rem] text-left text-sm">
                                        <thead className="bg-well">
                                            <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                                <th className="px-3 py-2 font-semibold">Check</th>
                                                <th className="px-3 py-2 font-semibold">Payee</th>
                                                <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                                <th className="px-3 py-2 font-semibold">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {checks.map((c) => (
                                                <tr key={c.key} className="border-b border-line/60 last:border-0">
                                                    <td className="px-3 py-2 font-display font-bold text-fg">
                                                        {c.label}
                                                    </td>
                                                    <td className="px-3 py-2 text-muted">{c.payee}</td>
                                                    <td className="px-3 py-2 text-right font-mono text-muted">
                                                        {formatMoney(c.amount)}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <select
                                                            className="field !py-1"
                                                            aria-label={`Status — ${c.label}`}
                                                            value={outcomes[c.key] ?? ''}
                                                            onChange={(e) =>
                                                                setOutcomes((o) => ({
                                                                    ...o,
                                                                    [c.key]: e.target.value as RtsOutcome,
                                                                }))
                                                            }
                                                            required
                                                        >
                                                            <option value="">Choose…</option>
                                                            {OUTCOMES.filter((o) => c.isCheque || !o.chequeOnly).map(
                                                                (o) => (
                                                                    <option key={o.value} value={o.value}>
                                                                        {o.label}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {action === 'completed' && (
                                <p className="text-sm text-muted">
                                    Complete ACIC #{acic.acic_number} and its {checks.length} check(s)? This is final.
                                </p>
                            )}

                            {action === 'rts' && (
                                <div className="mt-4">
                                    <label htmlFor="teller-rts-reason" className="label">
                                        Reason
                                    </label>
                                    <textarea
                                        id="teller-rts-reason"
                                        className="field min-h-24"
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        minLength={3}
                                        maxLength={2000}
                                        required
                                    />
                                    <p className="mt-1 text-xs text-subtle">
                                        Returned checks go out again with the next Forward; Completed, Cancelled and
                                        Stale ones are done.
                                    </p>
                                </div>
                            )}
                        </>
                    )}
                </div>

                {error && (
                    <div className="mt-4">
                        <Alert kind="error">{error}</Alert>
                    </div>
                )}

                <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                        Cancel
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={busy || !ready}>
                        <Send className="h-4 w-4" />
                        {busy
                            ? 'Saving…'
                            : step === 'forward'
                              ? to === 'payee'
                                  ? 'Yes, forward to Payee'
                                  : to === 'land_bank'
                                    ? 'Confirm Forward to LBP'
                                    : 'Forward'
                              : action === 'rts'
                                ? 'Save RTS'
                                : action === 'completed'
                                  ? 'Mark Completed'
                                  : 'Save'}
                    </button>
                </div>
            </form>
        </div>
    );
}
