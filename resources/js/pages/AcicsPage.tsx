import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { FilePlus2, ListChecks, HandCoins, Eye, Search, X, Send, UserRound, ClipboardCheck } from 'lucide-react';
import { AcicApi, AcicTellerApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Acic, AcicDisplayStatus, Paginated, TellerForwardTo } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState, AcicDisplayStatusBadge } from '../components/ui';
import ChequeAssignModal from '../components/ChequeAssignModal';
import LddapAssignModal from '../components/LddapAssignModal';
import AcicForwardModal from '../components/AcicForwardModal';
import AcicDetailModal from '../components/AcicDetailModal';
import AcicViewModal from '../components/AcicViewModal';
import TellerActionModal from '../components/TellerActionModal';
import PayeeForwardModal from '../components/PayeeForwardModal';
import { formatDate, formatDateTime, formatManila } from '../lib/format';

/**
 * The tab row picks what the ACIC carries; the filter container above the table picks its status
 * and searches it. They combine, on the server.
 */
type Tab = 'all' | 'cheques' | 'lddaps';

const TABS: { key: Tab; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'cheques', label: 'Cheque ACIC' },
    { key: 'lddaps', label: 'LDDAP ACIC' },
];

/**
 * Every status the table shows, in the order an ACIC lives through them: its own while it is
 * with the admin, then the teller's from the moment it is forwarded to the tellers (Pending).
 */
const STATUS_OPTIONS: { value: AcicDisplayStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'open', label: 'Open' },
    { value: 'used', label: 'Used' },
    { value: 'approved', label: 'Approved' },
    { value: 'pending', label: 'Pending' },
    { value: 'accepted_by_teller', label: 'Accepted' },
    { value: 'forwarded_to_land_bank', label: 'Forwarded to LBP' },
    { value: 'forwarded_to_payee', label: 'Forwarded to Payee' },
    { value: 'rts', label: 'RTS' },
    { value: 'completed', label: 'Completed' },
];

function tabFrom(value: string | null): Tab {
    return TABS.some((t) => t.key === value) ? (value as Tab) : 'all';
}

/** `?status=` (and the older `?tab=completed`) pick the Status filter. */
function statusFrom(params: URLSearchParams): AcicDisplayStatus | 'all' {
    const value = params.get('status') ?? params.get('tab');
    return STATUS_OPTIONS.some((o) => o.value === value) ? (value as AcicDisplayStatus | 'all') : 'all';
}

/**
 * What an ACIC is carrying. One sequence serves both record types, so an ACIC is a Cheque ACIC,
 * an LDDAP ACIC, or — when it holds some of each — Mixed. An ACIC opened but not yet filled has
 * no category to show yet.
 */
function AcicCategory({ acic }: { acic: Acic }) {
    const cheques = acic.cheque_count ?? 0;
    const lddaps = acic.lddap_count ?? 0;

    if (cheques === 0 && lddaps === 0) {
        return <span className="text-xs text-subtle">Empty</span>;
    }

    const parts: string[] = [];
    if (cheques > 0) parts.push(`${cheques} cheque${cheques === 1 ? '' : 's'}`);
    if (lddaps > 0) parts.push(`${lddaps} LDDAP${lddaps === 1 ? '' : 's'}`);

    const [label, styles] =
        cheques > 0 && lddaps > 0
            ? ['Mixed', 'border-line text-muted bg-well']
            : cheques > 0
              ? ['Cheque ACIC', 'border-accent-400/50 text-accent-400 bg-accent-400/10']
              : ['LDDAP ACIC', 'border-brand-400/40 text-brandink bg-brand-500/10'];

    return (
        <div>
            <span
                className={`inline-flex items-center rounded-xs border px-2 py-0.5 text-xs font-medium ${styles}`}
            >
                {label}
            </span>
            <div className="mt-0.5 text-xs text-subtle">{parts.join(' · ')}</div>
        </div>
    );
}

export default function AcicsPage() {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin' || user?.role === 'super_admin';
    const isTeller = user?.role === 'teller';
    const canManage = isAdmin || user?.role === 'staff';

    // `?tab=` picks the tab, so the dashboard's tiles land on the right list.
    const [params] = useSearchParams();
    const [tab, setTab] = useState<Tab>(() => tabFrom(params.get('tab')));
    const [status, setStatus] = useState<AcicDisplayStatus | 'all'>(() => statusFrom(params));
    const [search, setSearch] = useState('');
    // Debounced copy — the list only refetches once typing pauses.
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Paginated<Acic> | null>(null);
    const [nextNumber, setNextNumber] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    // The next-in-sequence assign dialog. The ACIC itself is opened when it is submitted.
    const [assigningNext, setAssigningNext] = useState(false);
    const [assigningLddaps, setAssigningLddaps] = useState(false);
    const [error, setError] = useState('');
    const [forwarding, setForwarding] = useState<Acic | null>(null);
    const [viewing, setViewing] = useState<{ id: number } | null>(null);
    const [accepting, setAccepting] = useState<number | null>(null);
    // The accepting teller's Forward (to LBP / the payee) and Action (Completed / RTS).
    const [payeeForwarding, setPayeeForwarding] = useState<Acic | null>(null);
    const [stepping, setStepping] = useState<{ acic: Acic; step: 'forward' | 'action'; forwardTo?: TellerForwardTo } | null>(null);
    const [notice, setNotice] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [list, next] = await Promise.all([AcicApi.list(status, page, 50, tab, query), AcicApi.next()]);
            setData(list);
            setNextNumber(next);
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [tab, status, query, page]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        setTab(tabFrom(params.get('tab')));
        setStatus(statusFrom(params));
        setPage(1);
    }, [params]);

    useEffect(() => {
        const timer = setTimeout(() => {
            setQuery(search.trim());
            setPage(1);
        }, 300);
        return () => clearTimeout(timer);
    }, [search]);

    /** A teller claims a Pending ACIC; if another got there first, the message says who. */
    async function accept(acic: Acic) {
        setAccepting(acic.id);
        setError('');
        setNotice('');
        try {
            await AcicTellerApi.accept(acic.id, acic.teller_status ?? undefined);
            setNotice(`You accepted ACIC #${acic.acic_number}. It is yours to forward.`);
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setAccepting(null);
            await load();
        }
    }

    /**
     * Forward's summary and the Action both work from the records on the ACIC, so they get the
     * full record, not the list row.
     */
    async function openStep(acic: Acic, step: 'forward' | 'action', forwardTo?: TellerForwardTo) {
        setAccepting(acic.id);
        setError('');
        try {
            setStepping({ acic: await AcicApi.show(acic.id), step, forwardTo });
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setAccepting(null);
        }
    }

    /** Forward to Payee lists the ACIC's cheques, so it too gets the full record. */
    async function openPayee(acic: Acic) {
        setAccepting(acic.id);
        setError('');
        try {
            setPayeeForwarding(await AcicApi.show(acic.id));
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setAccepting(null);
        }
    }

    function clearFilters() {
        setSearch('');
        setQuery('');
        setStatus('all');
        setPage(1);
    }

    const isFiltered = query !== '' || status !== 'all';

    return (
        <div>
            <PageHeader
                title="ACIC"
                subtitle={
                    isTeller
                        ? 'ACICs forwarded to the tellers — Pending until a teller accepts one; the first to accept takes it.'
                        : 'Advice of Checks Issued & Cancelled — one running sequence, no skipped numbers.'
                }
            />

            {canManage && (
                /* The two things an ACIC can be opened for, side by side. Both draw the SAME
                   number — there is one ACIC sequence, not one per record type — so the number is
                   stated once above the pair rather than twice inside it. */
                <div className="mb-6">
                    <div className="card flex flex-wrap items-center justify-between gap-4 border-b-0 p-5">
                        <div>
                            <span className="eyebrow">Next in series</span>
                            <div className="mt-2 font-display text-3xl font-extrabold tracking-tight text-brandink">
                                #{nextNumber ?? '—'}
                            </div>
                        </div>
                        <p className="max-w-sm text-sm text-muted">
                            {nextNumber === null
                                ? 'No ACIC numbers are available. An administrator has to register a block before another ACIC can be opened.'
                                : 'The lowest unused number in the registered series is taken next. Cheques and LDDAPs share one series — whichever you assign first takes this number.'}
                        </p>
                    </div>

                    {nextNumber === null && isAdmin && (
                        <div className="border-x border-b border-line bg-well p-4 text-sm text-muted">
                            Register a block of ACIC numbers from{' '}
                            <Link to="/admin/acic-series" className="text-brandink underline-offset-4 hover:underline">
                                ACIC Series
                            </Link>
                            .
                        </div>
                    )}

                    <div className="grid gap-px bg-line sm:grid-cols-2">
                        <div className="flex flex-col justify-between gap-4 bg-card p-5">
                            <div>
                                <h3 className="flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-widest text-fg">
                                    <FilePlus2 className="h-4 w-4 text-accent-400" />
                                    Cheque ACIC
                                </h3>
                                <p className="mt-1.5 text-sm text-muted">
                                    Put For Signature cheques on ACIC #{nextNumber ?? '—'}, or on an existing
                                    one. Many may share one ACIC number.
                                </p>
                            </div>
                            <button
                                className="btn btn-primary w-full"
                                onClick={() => setAssigningNext(true)}
                                disabled={nextNumber === null}
                            >
                                <FilePlus2 className="h-4 w-4" />
                                Assign cheque to ACIC
                            </button>
                        </div>

                        <div className="flex flex-col justify-between gap-4 bg-card p-5">
                            <div>
                                <h3 className="flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-widest text-fg">
                                    <ListChecks className="h-4 w-4 text-brandink" />
                                    LDDAP ACIC
                                </h3>
                                <p className="mt-1.5 text-sm text-muted">
                                    Put For Signature LDDAP records on ACIC #{nextNumber ?? '—'}. Many may
                                    share one ACIC number.
                                </p>
                            </div>
                            <button
                                className="btn btn-outline w-full"
                                onClick={() => setAssigningLddaps(true)}
                                disabled={nextNumber === null}
                            >
                                <ListChecks className="h-4 w-4" />
                                Assign LDDAP to ACIC
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Filters — search and status, on the server, together; the tabs below pick the kind. */}
            <div
                className="card mb-3 grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(12rem,1fr)_14rem_auto] lg:items-end"
                role="search"
                aria-label="Filter ACICs"
            >
                <div className="sm:col-span-2 lg:col-span-1">
                    <label className="label" htmlFor="acic-search">
                        Search
                    </label>
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                        <input
                            id="acic-search"
                            type="search"
                            className="field !py-1.5 !pl-10"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="ACIC no., cheque no., LDDAP no., or DV no."
                            maxLength={100}
                        />
                    </div>
                </div>
                <div>
                    <label className="label" htmlFor="acic-filter-status">
                        Status
                    </label>
                    <select
                        id="acic-filter-status"
                        className="field !py-1.5"
                        value={status}
                        onChange={(e) => {
                            const value = e.target.value;
                            setStatus(STATUS_OPTIONS.some((o) => o.value === value) ? (value as AcicDisplayStatus | 'all') : 'all');
                            setPage(1);
                        }}
                    >
                        {STATUS_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <button type="button" className="btn btn-ghost w-full !py-1.5 lg:w-auto" onClick={clearFilters}>
                        <X className="h-4 w-4" />
                        Clear
                    </button>
                </div>
            </div>

            {data && (
                <p className="mb-3 text-xs uppercase tracking-wider text-subtle" aria-live="polite">
                    Showing {data.meta.total.toLocaleString()} ACIC{data.meta.total === 1 ? '' : 's'}
                    {isFiltered ? ' · filtered' : ''}
                </p>
            )}

            {/* What the ACIC carries */}
            <div className="mb-4 flex flex-wrap gap-1 border-b border-line">
                {TABS.map(({ key, label }) => (
                    <button
                        key={key}
                        onClick={() => {
                            setTab(key);
                            setPage(1);
                        }}
                        className={`-mb-px border-b-2 px-4 py-2.5 font-display text-xs font-semibold uppercase tracking-widest transition-colors ${
                            tab === key
                                ? 'border-accent-400 text-fg'
                                : 'border-transparent text-subtle hover:text-muted'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

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

            {loading && !data ? (
                <Spinner />
            ) : data && data.data.length === 0 ? (
                <EmptyState>
                    {isFiltered
                        ? 'No ACIC matches these filters.'
                        : tab === 'cheques'
                          ? 'No ACIC carries a cheque yet.'
                          : tab === 'lddaps'
                            ? 'No ACIC carries an LDDAP record yet.'
                            : 'No ACIC records yet.'}
                </EmptyState>
            ) : (
                data && (
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[64rem] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-4 py-3 font-semibold">ACIC No.</th>
                                        <th className="px-4 py-3 font-semibold">Category</th>
                                        <th className="px-4 py-3 font-semibold">Status</th>
                                        <th className="px-4 py-3 font-semibold">Used By</th>
                                        <th className="px-4 py-3 font-semibold">Created Date</th>
                                        <th className="px-4 py-3 font-semibold">Forward Date</th>
                                        <th className="px-4 py-3 font-semibold">Forwarded to Bank</th>
                                        <th className="px-4 py-3 font-semibold">Received By</th>
                                        <th className="px-4 py-3 text-right font-semibold">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.data.map((acic) => (
                                        <tr key={acic.id} className="border-b border-line/60 last:border-0">
                                            <td className="px-4 py-3">
                                                <button
                                                    onClick={() => setViewing({ id: acic.id })}
                                                    title="View full details"
                                                    className="font-display font-bold text-fg underline-offset-4 hover:underline"
                                                >
                                                    #{acic.acic_number}
                                                </button>
                                            </td>
                                            <td className="px-4 py-3">
                                                <AcicCategory acic={acic} />
                                            </td>
                                            <td className="px-4 py-3">
                                                {/* The teller's status once it is with the tellers — Pending until one accepts. */}
                                                <AcicDisplayStatusBadge status={acic.display_status} label={acic.display_status_label} />
                                                {acic.payee_progress && acic.payee_progress.forwarded > 0 && (
                                                    <span className="mt-1 block text-xs text-teal-300">
                                                        {acic.payee_progress.forwarded}/{acic.payee_progress.total} forwarded to payee
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted">{acic.used_by?.name ?? '—'}</td>
                                            <td className="px-4 py-3 whitespace-nowrap text-muted">
                                                {formatDate(acic.created_at)}
                                            </td>
                                            {/* Date over time, so the column stays narrow enough for the actions. */}
                                            <td className="px-4 py-3 text-muted">
                                                {acic.forwarded_to_teller_at || acic.forwarded_at ? (
                                                    <>
                                                        <span className="whitespace-nowrap">
                                                            {formatDate(acic.forwarded_to_teller_at ?? acic.forwarded_at)}
                                                        </span>
                                                        <span className="block whitespace-nowrap text-xs text-subtle">
                                                            {new Date(acic.forwarded_to_teller_at ?? acic.forwarded_at ?? '').toLocaleTimeString('en-PH', {
                                                                hour: 'numeric',
                                                                minute: '2-digit',
                                                            })}
                                                        </span>
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            {/* Forwarded to Bank: when the accepting teller forwarded it to LBP, in
                                                Philippine time (server time at confirmation); "—" before then, and for
                                                ACICs forwarded before this was recorded. Wraps, to keep the table narrow. */}
                                            <td className="px-4 py-3 text-muted">
                                                {acic.forwarded_to_land_bank_at ? (
                                                    <>
                                                        <span>{formatManila(acic.forwarded_to_land_bank_at)}</span>
                                                        {acic.transmittal_no && (
                                                            <span className="mt-0.5 block font-mono text-xs text-subtle">
                                                                {acic.transmittal_no}
                                                            </span>
                                                        )}
                                                        {acic.credited_at && (
                                                            <span className="mt-0.5 block text-xs text-blue-300">
                                                                Credited {formatDateTime(acic.credited_at)}
                                                            </span>
                                                        )}
                                                    </>
                                                ) : (
                                                    <span className="text-subtle">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted">
                                                {acic.accepted_by?.name ?? acic.received_by?.name ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {/* A teller's steps sit here beside View: Accept (Pending), then
                                                    Forward to LBP / Payee (the accepting teller), then Action. An
                                                    admin's Approve, Re-assign, Forward and Print live in the View
                                                    dialog; the row only opens it. */}
                                                {/* Stacked, so the column stays one button wide and the table fits. */}
                                                <div className="flex flex-col items-end gap-1.5">
                                                    {/* Pending: any teller may take it — the first to accept wins. */}
                                                    {acic.can_accept && (
                                                        <button
                                                            className="btn btn-primary !px-3 !py-1.5"
                                                            onClick={() => void accept(acic)}
                                                            disabled={accepting === acic.id}
                                                        >
                                                            <HandCoins className="h-3.5 w-3.5" />
                                                            {accepting === acic.id ? 'Accepting…' : 'Accept'}
                                                        </button>
                                                    )}
                                                    {/* Accepted: only the teller who accepted it forwards it — to LBP,
                                                        and a cheque ACIC also to the payee. Once out, Action replaces them. */}
                                                    {(acic.teller_forward_options?.length ?? 0) > 0 && (
                                                        <>
                                                            {acic.teller_forward_options?.includes('land_bank') && (
                                                                <button
                                                                    className="btn btn-primary !px-3 !py-1.5"
                                                                    onClick={() => void openStep(acic, 'forward', 'land_bank')}
                                                                    disabled={accepting === acic.id}
                                                                >
                                                                    <Send className="h-3.5 w-3.5" />
                                                                    Forward to LBP
                                                                </button>
                                                            )}
                                                        </>
                                                    )}
                                                    {/* Cheque ACICs: one, several or all cheques to their payees. */}
                                                    {acic.can_forward_to_payee && (
                                                        <button
                                                            className="btn btn-outline !px-3 !py-1.5"
                                                            onClick={() => void openPayee(acic)}
                                                            disabled={accepting === acic.id}
                                                        >
                                                            <UserRound className="h-3.5 w-3.5" />
                                                            Forward to Payee
                                                        </button>
                                                    )}
                                                    {acic.can_teller_act && (
                                                        <button
                                                            className="btn btn-primary !px-3 !py-1.5"
                                                            onClick={() => void openStep(acic, 'action')}
                                                            disabled={accepting === acic.id}
                                                        >
                                                            <ClipboardCheck className="h-3.5 w-3.5" />
                                                            {accepting === acic.id ? 'Opening…' : 'Action'}
                                                        </button>
                                                    )}

                                                    <button
                                                        className="btn btn-ghost !px-3 !py-1.5"
                                                        onClick={() => setViewing({ id: acic.id })}
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                        View
                                                    </button>


                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {data.meta.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-line px-4 py-3 text-sm text-muted">
                                <span>
                                    Page {data.meta.current_page} of {data.meta.last_page} · {data.meta.total} total
                                </span>
                                <div className="flex gap-2">
                                    <button
                                        className="btn btn-ghost"
                                        disabled={data.meta.current_page <= 1}
                                        onClick={() => setPage((p) => p - 1)}
                                    >
                                        Prev
                                    </button>
                                    <button
                                        className="btn btn-ghost"
                                        disabled={data.meta.current_page >= data.meta.last_page}
                                        onClick={() => setPage((p) => p + 1)}
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                )
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

            {/* A teller gets the read-only view, with the ACIC's history. */}
            {viewing && isTeller && <AcicViewModal acicId={viewing.id} onClose={() => setViewing(null)} />}

            {viewing && !isTeller && (
                <AcicDetailModal
                    acicId={viewing.id}
                    onClose={() => setViewing(null)}
                    onChanged={() => void load()}
                    onForward={(acic) => {
                        setViewing(null);
                        setForwarding(acic);
                    }}
                />
            )}

            {assigningLddaps && (
                <LddapAssignModal
                    onClose={() => setAssigningLddaps(false)}
                    onAssigned={() => {
                        setAssigningLddaps(false);
                        setPage(1);
                        void load();
                    }}
                />
            )}

            {assigningNext && (
                <ChequeAssignModal
                    onClose={() => setAssigningNext(false)}
                    onAssigned={() => {
                        setAssigningNext(false);
                        setPage(1);
                        void load();
                    }}
                />
            )}

            {forwarding && (
                <AcicForwardModal
                    acic={forwarding}
                    onClose={() => setForwarding(null)}
                    onForwarded={() => {
                        setForwarding(null);
                        void load();
                    }}
                />
            )}
        </div>
    );
}
