import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { HandCoins, Undo2, Inbox, Send, ClipboardCheck, Search, X, Eye, UserRound } from 'lucide-react';
import { AcicTellerApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Acic, AcicTellerStatus, TellerForwardTo, TellerQueue } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState } from '../components/ui';
import TellerActionModal from '../components/TellerActionModal';
import AcicViewModal from '../components/AcicViewModal';
import PayeeForwardModal from '../components/PayeeForwardModal';
import { formatDateTime, formatMoney, formatManila } from '../lib/format';

/** The dashboard's lists, in the order a teller works through them. */
const TABS: { key: keyof Omit<TellerQueue, 'bank_name'>; label: string }[] = [
    { key: 'pending', label: 'Pending' },
    { key: 'accepted', label: 'My Accepted' },
    { key: 'forwarded', label: 'Forwarded' },
    { key: 'rts', label: 'RTS' },
    { key: 'completed', label: 'Completed' },
];

/** Every teller status an ACIC can hold today, in the order the teller works through them. */
const STATUS_OPTIONS: { value: AcicTellerStatus | ''; label: string }[] = [
    { value: '', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'accepted_by_teller', label: 'Accepted' },
    { value: 'forwarded_to_land_bank', label: 'Forwarded to LBP' },
    { value: 'forwarded_to_payee', label: 'Forwarded to Payee' },
    { value: 'rts', label: 'RTS' },
    { value: 'completed', label: 'Completed' },
];

const EMPTY: TellerQueue = { pending: [], accepted: [], forwarded: [], rts: [], completed: [], bank_name: 'Land Bank of the Philippines' };

function Field({ label, htmlFor, optional = false, children }: { label: string; htmlFor: string; optional?: boolean; children: ReactNode }) {
    return (
        <div>
            <label htmlFor={htmlFor} className="label !mb-1">
                {label}
                {optional && <span className="ml-1 normal-case tracking-normal text-subtle">(optional)</span>}
            </label>
            {children}
        </div>
    );
}

/** Where the ACIC stands with the tellers and the bank. */
function TellerStatusBadge({ status }: { status?: AcicTellerStatus | null }) {
    if (!status) return <span className="text-subtle">—</span>;

    const config: Record<AcicTellerStatus, { styles: string; label: string }> = {
        pending: { styles: 'border-accent-400/50 bg-accent-400/10 text-accent-400', label: 'Pending' },
        accepted_by_teller: { styles: 'border-indigo-400/50 bg-indigo-400/10 text-indigo-300', label: 'Accepted' },
        forwarded_to_land_bank: { styles: 'border-blue-400/50 bg-blue-400/10 text-blue-300', label: 'Forwarded to LBP' },
        forwarded_to_payee: { styles: 'border-teal-400/50 bg-teal-400/10 text-teal-300', label: 'Forwarded to Payee' },
        rts: { styles: 'border-amber-400/50 bg-amber-400/10 text-amber-400', label: 'RTS' },
        returned_by_bank: { styles: 'border-amber-400/50 bg-amber-400/10 text-amber-400', label: 'Returned by Bank' },
        completed: { styles: 'border-success/40 bg-success/10 text-success-fg', label: 'Completed' },
    };
    const { styles, label } = config[status];

    return (
        <span className={`inline-flex items-center rounded-xs border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider whitespace-nowrap ${styles}`}>
            {label}
        </span>
    );
}

/** The type badge, shown wherever ACICs are listed. */
function TypeBadge({ type }: { type?: string | null }) {
    if (!type) return <span className="text-subtle">—</span>;
    const cheque = type === 'cheque';
    return (
        <span className={`inline-flex items-center rounded-xs border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider ${
            cheque ? 'border-brand-400/50 bg-brand-500/10 text-brandink' : 'border-purple-400/50 bg-purple-400/10 text-purple-300'
        }`}>
            {cheque ? 'Cheque' : 'LDDAP'}
        </span>
    );
}

/**
 * The teller's dashboard for the whole bank journey — the same for cheque and LDDAP ACICs.
 *
 * **Pending** is everyone's: the first teller to accept an ACIC claims it, and it leaves the
 * other tellers' lists. The rest are the viewer's own work (an admin sees every teller's).
 */
export default function TellerQueuePage() {
    const { user, isAdmin } = useAuth();
    const [queue, setQueue] = useState<TellerQueue>(EMPTY);
    const [tab, setTab] = useState<(typeof TABS)[number]['key']>('pending');
    const [type, setType] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');
    // Debounced copy — the lists only refetch once typing pauses.
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState<number | null>(null);

    // Return to Admin — the one step kept in this page's own dialog.
    const [returning, setReturning] = useState<Acic | null>(null);
    const [reason, setReason] = useState('');
    // The accepting teller's Forward and Action.
    const [stepping, setStepping] = useState<{ acic: Acic; step: 'forward' | 'action'; forwardTo?: TellerForwardTo } | null>(null);
    // Forward to Payee: the cheques, the receiver, the date and the unit.
    const [payeeForwarding, setPayeeForwarding] = useState<Acic | null>(null);
    // The read-only View, on every row.
    const [viewingId, setViewingId] = useState<number | null>(null);

    const load = useCallback(async () => {
        try {
            setQueue(
                await AcicTellerApi.queue({
                    type: type || undefined,
                    from: from || undefined,
                    to: to || undefined,
                    search: query || undefined,
                    status: status || undefined,
                }),
            );
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [type, from, to, query, status]);

    useEffect(() => {
        const timer = setTimeout(() => setQuery(search.trim()), 300);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        void load();
    }, [load]);

    function openReturn(acic: Acic) {
        setReturning(acic);
        setReason('');
        setError('');
    }

    async function accept(acic: Acic) {
        setBusy(acic.id);
        setError('');
        setNotice('');
        try {
            await AcicTellerApi.accept(acic.id, acic.teller_status ?? undefined);
            setNotice(`You accepted ACIC #${acic.acic_number}.`);
        } catch (err) {
            const apiErr = toApiError(err);
            // Someone else got there first; the list is already out of date.
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setBusy(null);
            await load();
        }
    }

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!returning) return;
        const acic = returning;
        setBusy(acic.id);
        setError('');
        try {
            await AcicTellerApi.returnToAdmin(acic.id, reason.trim(), acic.teller_status ?? undefined);
            setNotice(`ACIC #${acic.acic_number} returned to the admin.`);
            setReturning(null);
            await load();
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setBusy(null);
        }
    }

    const rows = queue[tab];
    const mine = (acic: Acic) => acic.accepted_by?.id === user?.id;

    return (
        <div>
            <PageHeader
                title="Deposit queue"
                subtitle={
                    isAdmin
                        ? 'Every ACIC with the tellers, and where each one stands with the bank.'
                        : 'ACICs forwarded for deposit. The first teller to accept one takes it.'
                }
            />

            {notice && (
                <div className="mb-4">
                    <Alert kind="success">{notice}</Alert>
                </div>
            )}
            {error && (
                <div className="mb-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}

            {/* Filters — on the server, together; they only narrow each list. */}
            <div
                className="card mb-4 grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(15rem,1fr)_11rem_7rem_9.5rem_9.5rem_auto] lg:items-end"
                role="search"
                aria-label="Filter ACICs"
            >
                <div className="sm:col-span-2 lg:col-span-1">
                    <Field label="Search" htmlFor="q-search">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                            <input
                                id="q-search"
                                type="search"
                                className="field !py-1.5 !pl-10"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="ACIC no., cheque no., LDDAP no., or DV no."
                                maxLength={100}
                            />
                        </div>
                    </Field>
                </div>
                <Field label="Status" htmlFor="q-status">
                    <select id="q-status" className="field !py-1.5" value={status} onChange={(e) => setStatus(e.target.value)}>
                        {STATUS_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Type" htmlFor="q-type">
                    <select id="q-type" className="field !py-1.5" value={type} onChange={(e) => setType(e.target.value)}>
                        <option value="">All</option>
                        <option value="cheque">Cheque</option>
                        <option value="lddap">LDDAP</option>
                    </select>
                </Field>
                <Field label="Forwarded from" htmlFor="q-from" optional>
                    <input id="q-from" type="date" className="field !py-1.5" value={from} onChange={(e) => setFrom(e.target.value)} />
                </Field>
                <Field label="Forwarded to" htmlFor="q-to" optional>
                    <input id="q-to" type="date" className="field !py-1.5" value={to} onChange={(e) => setTo(e.target.value)} />
                </Field>
                <button
                    type="button"
                    className="btn btn-ghost !py-1.5 sm:col-span-2 lg:col-span-1"
                    onClick={() => {
                        setSearch('');
                        setQuery('');
                        setStatus('');
                        setType('');
                        setFrom('');
                        setTo('');
                    }}
                >
                    <X className="h-4 w-4" />
                    Clear
                </button>
            </div>

            {/* The lists. */}
            <div className="mb-4 flex flex-wrap gap-1 border-b border-line">
                {TABS.map(({ key, label }) => (
                    <button
                        key={key}
                        onClick={() => setTab(key)}
                        className={`-mb-px border-b-2 px-4 py-2.5 font-display text-xs font-semibold uppercase tracking-widest transition-colors ${
                            tab === key ? 'border-accent-400 text-fg' : 'border-transparent text-subtle hover:text-muted'
                        }`}
                    >
                        {label}
                        {queue[key].length > 0 && <span className="ml-1.5 text-brandink">{queue[key].length}</span>}
                    </button>
                ))}
            </div>

            {loading ? (
                <Spinner />
            ) : rows.length === 0 ? (
                <EmptyState>
                    {tab === 'pending' ? 'No ACICs are waiting to be accepted.' : 'Nothing in this list.'}
                </EmptyState>
            ) : (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[62rem] text-left text-sm">
                            <thead>
                                <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                    <th className="px-4 py-3 font-semibold">ACIC #</th>
                                    <th className="px-4 py-3 font-semibold">Type</th>
                                    <th className="px-4 py-3 text-right font-semibold">Records</th>
                                    <th className="px-4 py-3 text-right font-semibold">Total</th>
                                    <th className="px-4 py-3 font-semibold">Forwarded by</th>
                                    <th className="px-4 py-3 font-semibold">Forwarded to teller</th>
                                    <th className="px-4 py-3 font-semibold">Forwarded out</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-4 py-3 font-semibold">Last note</th>
                                    <th className="px-4 py-3 text-right font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((acic) => {
                                    const total =
                                        (acic.cheques ?? []).reduce((s, c) => s + Number(c.amount ?? 0), 0) +
                                        (acic.lddaps ?? []).reduce((s, l) => s + Number(l.amount ?? 0), 0);
                                    const lastNote =
                                        acic.rts_reason ?? acic.bank_return_reason ?? acic.land_bank_note ?? acic.forward_note ?? acic.completion_note ?? null;

                                    return (
                                        <tr key={acic.id} className="border-b border-line/60 last:border-0">
                                            <td className="px-4 py-3 font-display font-bold text-fg">#{acic.acic_number}</td>
                                            <td className="px-4 py-3">
                                                <TypeBadge type={acic.type} />
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted">{acic.total_records ?? 0}</td>
                                            <td className="px-4 py-3 text-right font-mono text-muted">{formatMoney(total)}</td>
                                            <td className="px-4 py-3 text-muted">{acic.forwarded_to_teller_by?.name ?? '—'}</td>
                                            <td className="px-4 py-3 whitespace-nowrap text-xs text-muted">
                                                {formatDateTime(acic.forwarded_to_teller_at)}
                                                {acic.accepted_by && (
                                                    <span className="mt-0.5 block text-subtle">
                                                        accepted by {acic.accepted_by.name}
                                                    </span>
                                                )}
                                            </td>
                                            {/* Where the accepting teller took it, and when. */}
                                            <td className="px-4 py-3 whitespace-nowrap text-xs text-muted">
                                                {acic.teller_forwarded_to === 'payee' && acic.teller_forwarded_at ? (
                                                    <>
                                                        To Payee
                                                        <span className="mt-0.5 block text-subtle">{formatManila(acic.teller_forwarded_at)}</span>
                                                    </>
                                                ) : acic.forwarded_to_land_bank_at ? (
                                                    <>
                                                        Forwarded to Bank
                                                        <span className="mt-0.5 block text-subtle">
                                                            {formatManila(acic.forwarded_to_land_bank_at)}
                                                        </span>
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <TellerStatusBadge status={acic.teller_status} />
                                                {acic.payee_progress && acic.payee_progress.forwarded > 0 && (
                                                    <span className="mt-1 block text-xs text-teal-300">
                                                        {acic.payee_progress.forwarded}/{acic.payee_progress.total} forwarded to payee
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 max-w-[16rem] truncate text-xs text-muted" title={lastNote ?? ''}>
                                                {lastNote ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    {acic.teller_status === 'pending' && (
                                                        <button
                                                            className="btn btn-primary !px-3 !py-1.5"
                                                            onClick={() => void accept(acic)}
                                                            disabled={busy === acic.id}
                                                        >
                                                            <HandCoins className="h-3.5 w-3.5" />
                                                            {busy === acic.id ? 'Accepting…' : 'Accept'}
                                                        </button>
                                                    )}

                                                    {/* Only the teller who accepted it forwards it or takes the Action. */}
                                                    {/* LBP for every ACIC; the payee for a cheque ACIC only. */}
                                                    {acic.teller_forward_options?.includes('land_bank') && (
                                                        <button
                                                            className="btn btn-primary !px-3 !py-1.5"
                                                            onClick={() => setStepping({ acic, step: 'forward', forwardTo: 'land_bank' })}
                                                        >
                                                            <Send className="h-3.5 w-3.5" />
                                                            Forward to LBP
                                                        </button>
                                                    )}
                                                    {/* Cheque ACICs: one, several or all cheques to their payees. */}
                                                    {acic.can_forward_to_payee && (
                                                        <button
                                                            className="btn btn-outline !px-3 !py-1.5"
                                                            onClick={() => setPayeeForwarding(acic)}
                                                        >
                                                            <UserRound className="h-3.5 w-3.5" />
                                                            Forward to Payee
                                                        </button>
                                                    )}

                                                    {acic.can_teller_act && (
                                                        <button
                                                            className="btn btn-primary !px-3 !py-1.5"
                                                            onClick={() => setStepping({ acic, step: 'action' })}
                                                        >
                                                            <ClipboardCheck className="h-3.5 w-3.5" />
                                                            Action
                                                        </button>
                                                    )}

                                                    {acic.can_return_to_admin && (
                                                        <button className="btn btn-ghost !px-3 !py-1.5" onClick={() => openReturn(acic)}>
                                                            <Undo2 className="h-3.5 w-3.5" />
                                                            Return to Admin
                                                        </button>
                                                    )}

                                                    {acic.teller_status === 'completed' && (
                                                        <span className="text-xs text-subtle">
                                                            Completed {formatDateTime(acic.teller_action_at ?? acic.completed_at)}
                                                            {acic.teller_action_by && ` by ${acic.teller_action_by.name}`}
                                                        </span>
                                                    )}

                                                    <button className="btn btn-ghost !px-3 !py-1.5" onClick={() => setViewingId(acic.id)}>
                                                        <Eye className="h-3.5 w-3.5" />
                                                        View
                                                    </button>

                                                    {acic.teller_status !== 'completed' && acic.teller_status !== 'pending' && !mine(acic) && (
                                                        <span className="text-xs text-subtle">
                                                            With {acic.accepted_by?.name ?? 'another teller'}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {returning && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                    onClick={() => setReturning(null)}
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="return-admin-title"
                >
                    <form className="card w-full max-w-md space-y-3 p-6" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
                        <h2 id="return-admin-title" className="font-display text-xl font-extrabold text-fg">
                            Return to Admin
                        </h2>
                        <p className="text-sm text-muted">
                            ACIC #{returning.acic_number} · {returning.type_label ?? '—'} · {returning.total_records ?? 0} record(s) — it
                            goes back to the admin to fix.
                        </p>

                        {error && <Alert kind="error">{error}</Alert>}

                        <Field label="Reason" htmlFor="t-reason">
                            <textarea
                                id="t-reason"
                                className="field min-h-24 !py-1.5"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                minLength={3}
                                maxLength={2000}
                                required
                                autoFocus
                            />
                        </Field>

                        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={() => setReturning(null)}>
                                Cancel
                            </button>
                            <button type="submit" className="btn btn-primary" disabled={busy !== null || reason.trim().length < 3}>
                                {busy !== null ? 'Saving…' : 'Return to Admin'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {stepping && (
                <TellerActionModal
                    acic={stepping.acic}
                    step={stepping.step}
                    forwardTo={stepping.forwardTo}
                    onClose={() => setStepping(null)}
                    onDone={(_acic, message) => {
                        setStepping(null);
                        setNotice(message);
                        void load();
                    }}
                />
            )}

            {viewingId !== null && <AcicViewModal acicId={viewingId} onClose={() => setViewingId(null)} />}

            {payeeForwarding && (
                <PayeeForwardModal
                    acic={payeeForwarding}
                    onClose={() => setPayeeForwarding(null)}
                    onDone={(_acic, message) => {
                        setPayeeForwarding(null);
                        setNotice(message);
                        void load();
                    }}
                />
            )}

            <p className="mt-6 flex items-start gap-2 text-xs text-subtle">
                <Inbox className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    Accepting an ACIC claims it — the first teller to accept takes it, and it leaves every other teller&rsquo;s
                    Pending list. Only that teller forwards it — to <strong>LBP</strong> ({queue.bank_name}), or a cheque ACIC to
                    the payee — and takes the Action.
                </span>
            </p>
        </div>
    );
}
