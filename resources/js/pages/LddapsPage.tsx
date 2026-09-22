import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ListChecks, Search, Gavel, FilePlus2, Send, Inbox, Filter, X, Eye } from 'lucide-react';
import { LddapApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Lddap, LddapOptions, LddapStatus, Paginated } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState, LddapStatusBadge } from '../components/ui';
import LddapRegisterModal from '../components/LddapRegisterModal';
import LddapAssignModal from '../components/LddapAssignModal';
import LddapStepModal, { type LddapStep } from '../components/LddapStepModal';
import LddapDetailModal from '../components/LddapDetailModal';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';

type StatusFilter = 'all' | LddapStatus;

const STATUS_OPTIONS: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'registered', label: 'Registered' },
    { value: 'for_out', label: 'For Out' },
    { value: 'returned_for_acic', label: 'Returned for ACIC' },
    { value: 'approved', label: 'Approved' },
    { value: 'rts', label: 'RTS' },
    { value: 'canceled', label: 'Canceled' },
];

const PER_PAGE = 50;

/** The three filter fields, as typed into the bar. */
interface Filters {
    search: string;
    status: StatusFilter;
    nature: string;
}

const NO_FILTERS: Filters = { search: '', status: 'all', nature: 'all' };

function isStatusFilter(value: string | null): value is StatusFilter {
    return STATUS_OPTIONS.some((o) => o.value === value);
}

/**
 * The applied filters live in the URL (`?search=…&status=…&nature=…&page=…`), so a filtered
 * view survives a refresh and can be bookmarked; the bar's inputs are a draft of them until
 * Filter is pressed.
 */
function readFilters(params: URLSearchParams): Filters & { page: number } {
    const status = params.get('status');
    const page = Number(params.get('page') ?? '1');

    return {
        search: params.get('search') ?? '',
        status: isStatusFilter(status) ? status : 'all',
        nature: params.get('nature') || 'all',
        page: Number.isInteger(page) && page > 0 ? page : 1,
    };
}

function writeFilters(filters: Filters, page: number): URLSearchParams {
    const params = new URLSearchParams();
    const search = filters.search.trim();

    if (search) params.set('search', search);
    if (filters.status !== 'all') params.set('status', filters.status);
    if (filters.nature !== 'all') params.set('nature', filters.nature);
    if (page > 1) params.set('page', String(page));

    return params;
}

export default function LddapsPage() {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin';
    const canManage = isAdmin || user?.role === 'staff';

    const [params, setParams] = useSearchParams();
    const applied = readFilters(params);
    const { search, status, nature, page } = applied;
    const isFiltered = search !== '' || status !== 'all' || nature !== 'all';

    // What the bar shows — the applied filters until the person edits them.
    const [draft, setDraft] = useState<Filters>({ search, status, nature });
    const [options, setOptions] = useState<LddapOptions | null>(null);

    const [data, setData] = useState<Paginated<Lddap> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const [registering, setRegistering] = useState(false);

    const [assigning, setAssigning] = useState<{
        preselect: Lddap | null;
    } | null>(null);
    // The routing step being taken on a record: Forward, Receive, or the admin's Action.
    const [stepping, setStepping] = useState<{
        lddap: Lddap;
        step: LddapStep;
    } | null>(null);
    const [viewing, setViewing] = useState<{
        lddap: Lddap;
        mode: 'view' | 'action';
    } | null>(null);

    // Back/forward (or a pasted link) changes the URL under us: the bar follows it.
    useEffect(() => {
        setDraft({ search, status, nature });
    }, [search, status, nature]);

    // The Nature of Payment select offers the same list as the register form.
    useEffect(() => {
        LddapApi.options()
            .then(setOptions)
            .catch(() => setOptions(null));
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            setData(
                await LddapApi.list({
                    search,
                    status,
                    nature,
                    page,
                    perPage: PER_PAGE,
                }),
            );
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [search, status, nature, page]);

    useEffect(() => {
        void load();
    }, [load]);

    /** Apply the bar's draft (Filter, or Enter in the search box) — always from page 1. */
    function applyFilters(e?: FormEvent) {
        e?.preventDefault();
        setParams(writeFilters(draft, 1));
    }

    function clearFilters() {
        setDraft(NO_FILTERS);
        setParams(writeFilters(NO_FILTERS, 1));
    }

    function goToPage(next: number) {
        setParams(writeFilters(applied, next));
    }

    /** After a change to the records: back to page 1 of the current filters, refetched. */
    function refreshFromFirstPage() {
        if (page === 1) void load();
        else goToPage(1);
    }

    const resultCount =
        data === null
            ? null
            : data.meta.total === 0
              ? 'No records found'
              : data.meta.last_page > 1
                ? `Showing ${data.meta.from ?? 0}–${data.meta.to ?? 0} of ${data.meta.total} records`
                : `Showing ${data.meta.total} record${data.meta.total === 1 ? '' : 's'}`;

    return (
        <div>
            <PageHeader
                title="LDDAP"
                subtitle="LDDAP-ADA records — registered, routed, reviewed, then put on an ACIC, which is when each takes the next check number."
                action={
                    canManage ? (
                        <div className="flex flex-wrap gap-2">
                            <button className="btn btn-primary" onClick={() => setRegistering(true)}>
                                <FilePlus2 className="h-4 w-4" />
                                Add LDDAP
                            </button>
                            <button className="btn btn-outline" onClick={() => setAssigning({ preselect: null })}>
                                <ListChecks className="h-4 w-4" />
                                Assign LDDAP to ACIC
                            </button>
                        </div>
                    ) : undefined
                }
            />

            {/* Filter bar — applied on Filter / Enter, carried in the URL. */}
            <form
                className="card mb-4 grid grid-cols-1 gap-3 p-4 md:grid-cols-[minmax(0,1fr)_minmax(0,14rem)_minmax(0,16rem)_auto] md:items-end"
                onSubmit={applyFilters}
                role="search"
                aria-label="Filter LDDAP records"
            >
                <div>
                    <label className="label" htmlFor="lddap-filter-search">
                        Search
                    </label>
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                        <input
                            id="lddap-filter-search"
                            type="search"
                            className="field !py-1.5 !pl-10"
                            value={draft.search}
                            onChange={(e) =>
                                setDraft((d) => ({
                                    ...d,
                                    search: e.target.value,
                                }))
                            }
                            placeholder="LDDAP no., check no., or gross amount (e.g. ₱194,032)"
                            maxLength={100}
                        />
                    </div>
                </div>
                <div>
                    <label className="label" htmlFor="lddap-filter-status">
                        Status
                    </label>
                    <select
                        id="lddap-filter-status"
                        className="field !py-1.5"
                        value={draft.status}
                        onChange={(e) => {
                            const value = e.target.value;
                            setDraft((d) => ({
                                ...d,
                                status: isStatusFilter(value) ? value : 'all',
                            }));
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
                    <label className="label" htmlFor="lddap-filter-nature">
                        Nature of Payment
                    </label>
                    <select
                        id="lddap-filter-nature"
                        className="field !py-1.5"
                        value={draft.nature}
                        onChange={(e) => setDraft((d) => ({ ...d, nature: e.target.value }))}
                    >
                        <option value="all">All</option>
                        {(options?.natures ?? []).map((n) => (
                            <option key={n.value} value={n.value}>
                                {n.label}
                            </option>
                        ))}
                        {/* Keep a bookmarked value selectable while the list loads. */}
                        {options === null && draft.nature !== 'all' && (
                            <option value={draft.nature}>{draft.nature}</option>
                        )}
                    </select>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" className="btn btn-primary !py-1.5">
                        <Filter className="h-4 w-4" />
                        Filter
                    </button>
                    <button type="button" className="btn btn-ghost !py-1.5" onClick={clearFilters}>
                        <X className="h-4 w-4" />
                        Clear
                    </button>
                </div>
            </form>

            {resultCount && (
                <p className="mb-3 text-xs uppercase tracking-wider text-subtle" aria-live="polite">
                    {resultCount}
                    {isFiltered && data && data.meta.total > 0 ? ' · filtered' : ''}
                </p>
            )}

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
                    {isFiltered ? (
                        <span>
                            No records found.{' '}
                            <button
                                type="button"
                                className="text-brandink underline-offset-4 hover:underline"
                                onClick={clearFilters}
                            >
                                Clear the filters
                            </button>{' '}
                            to show all records.
                        </span>
                    ) : page > 1 ? (
                        'No records found on this page.'
                    ) : (
                        'No LDDAP records yet. Add the first one with Add LDDAP.'
                    )}
                </EmptyState>
            ) : (
                data && (
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[66rem] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-4 py-3 font-semibold">Check No.</th>
                                        <th className="px-4 py-3 font-semibold">Status</th>
                                        <th className="px-4 py-3 font-semibold">LDDAP No.</th>
                                        <th className="px-4 py-3 text-right font-semibold">Amount</th>
                                        <th className="px-4 py-3 font-semibold">Nature</th>
                                        <th className="px-4 py-3 font-semibold">Used By</th>
                                        <th className="px-4 py-3 font-semibold">ACIC No.</th>
                                        <th className="px-4 py-3 font-semibold">Forward To / Date</th>
                                        <th className="px-4 py-3 text-right font-semibold">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.data.map((l) => (
                                        <tr key={l.id} className="border-b border-line/60 last:border-0">
                                            <td className="px-4 py-3 font-display font-bold text-fg">
                                                {l.check_no != null ? (
                                                    <>#{l.check_no}</>
                                                ) : (
                                                    <span className="text-xs font-normal text-subtle">
                                                        {l.status === 'approved' ? 'Assigned with ACIC' : '—'}
                                                    </span>
                                                )}
                                                {l.check_date && (
                                                    <div className="mt-0.5 text-xs font-normal text-subtle">
                                                        {formatDate(l.check_date)}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="inline-flex flex-wrap items-center gap-1.5">
                                                    <LddapStatusBadge status={l.status} />
                                                    {(l.rts_count ?? 0) > 0 && (
                                                        <span
                                                            className="inline-flex items-center rounded-xs border border-amber-400/50 bg-amber-400/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-400"
                                                            title={`Returned to sender ${l.rts_count} time${l.rts_count === 1 ? '' : 's'}`}
                                                        >
                                                            RTS: {l.rts_count}
                                                        </span>
                                                    )}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-muted">
                                                <button
                                                    onClick={() =>
                                                        setViewing({
                                                            lddap: l,
                                                            mode: 'view',
                                                        })
                                                    }
                                                    title="View full details"
                                                    className="text-fg underline-offset-4 hover:underline"
                                                >
                                                    {l.lddap_no}
                                                </button>
                                                {l.payee_name && (
                                                    <div className="mt-0.5 text-xs text-subtle">
                                                        {l.payee_name}
                                                        {l.payee_account_no && (
                                                            <span className="ml-1.5 font-mono">
                                                                {l.payee_account_no}
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right font-mono text-muted">
                                                {formatMoney(l.amount)}
                                            </td>
                                            {/* NCA/ORB/DV, unit and UACS code live in the detail dialog. */}
                                            <td className="px-4 py-3 text-xs text-muted">
                                                {l.nature_of_payment_label ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-muted">{l.used_by?.name ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                {l.acic_number == null ? (
                                                    <span className="text-subtle">—</span>
                                                ) : (
                                                    <span className="font-display font-bold text-fg">
                                                        #{l.acic_number}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-muted">
                                                {l.forwarded_at ? (
                                                    <>
                                                        {l.forwarded_to ?? '—'}
                                                        <div className="mt-0.5 text-xs text-subtle">
                                                            {formatDateTime(l.forwarded_at)}
                                                        </div>
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    {/* View opens the full record — every status. */}
                                                    <button
                                                        className="btn btn-ghost !px-3 !py-1.5"
                                                        onClick={() =>
                                                            setViewing({
                                                                lddap: l,
                                                                mode: 'view',
                                                            })
                                                        }
                                                        title={`View ${l.lddap_no}`}
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                        View
                                                    </button>
                                                    {/* Then exactly one next step per status; anything else is
                                                    not offered. A pending correction holds the record. */}
                                                    {l.has_pending_update && !l.is_final ? (
                                                        <span className="text-xs text-subtle">
                                                            On hold — resolve the update request first
                                                        </span>
                                                    ) : l.status === 'registered' || l.status === 'rts' ? (
                                                        /* Registered, or sent back to be corrected: Forward. */
                                                        canManage ? (
                                                            <button
                                                                className="btn btn-primary !px-3 !py-1.5"
                                                                onClick={() =>
                                                                    setStepping({
                                                                        lddap: l,
                                                                        step: 'forward',
                                                                    })
                                                                }
                                                            >
                                                                <Send className="h-3.5 w-3.5" />
                                                                Forward
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-subtle">
                                                                Awaiting forwarding
                                                            </span>
                                                        )
                                                    ) : l.status === 'for_out' ? (
                                                        canManage ? (
                                                            <button
                                                                className="btn btn-primary !px-3 !py-1.5"
                                                                onClick={() =>
                                                                    setStepping({
                                                                        lddap: l,
                                                                        step: 'receive',
                                                                    })
                                                                }
                                                            >
                                                                <Inbox className="h-3.5 w-3.5" />
                                                                Receive
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-subtle">
                                                                Out
                                                                {l.forward_to ? ` — ${l.forward_to}` : ''}
                                                            </span>
                                                        )
                                                    ) : l.status === 'returned_for_acic' ? (
                                                        isAdmin ? (
                                                            <button
                                                                className="btn btn-outline !px-3 !py-1.5"
                                                                onClick={() =>
                                                                    setStepping({
                                                                        lddap: l,
                                                                        step: 'action',
                                                                    })
                                                                }
                                                            >
                                                                <Gavel className="h-3.5 w-3.5" />
                                                                Action
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-subtle">Awaiting action</span>
                                                        )
                                                    ) : l.status === 'approved' ? (
                                                        /* Approved — the next step is going on an ACIC. Once
                                                       it is on one, the ACIC No. column says so. */
                                                        l.acic_number ? null : canManage ? (
                                                            <button
                                                                className="btn btn-outline !px-3 !py-1.5"
                                                                onClick={() =>
                                                                    setAssigning({
                                                                        preselect: l,
                                                                    })
                                                                }
                                                            >
                                                                <ListChecks className="h-3.5 w-3.5" />
                                                                Assign
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-subtle">
                                                                Awaiting assignment
                                                            </span>
                                                        )
                                                    ) : (
                                                        /* Canceled — read-only; nothing is offered. */
                                                        <span className="text-xs text-subtle">
                                                            Canceled {formatDate(l.date_canceled ?? l.reviewed_at)}
                                                        </span>
                                                    )}
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
                                        onClick={() => goToPage(data.meta.current_page - 1)}
                                    >
                                        Prev
                                    </button>
                                    <button
                                        className="btn btn-ghost"
                                        disabled={data.meta.current_page >= data.meta.last_page}
                                        onClick={() => goToPage(data.meta.current_page + 1)}
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                )
            )}

            {registering && (
                <LddapRegisterModal
                    onClose={() => setRegistering(false)}
                    onSaved={(lddap) => {
                        setRegistering(false);
                        setNotice(`Registered ${lddap.lddap_no} — out for routing.`);
                        refreshFromFirstPage();
                    }}
                />
            )}


            {assigning && (
                <LddapAssignModal
                    preselect={assigning.preselect}
                    onClose={() => setAssigning(null)}
                    onAssigned={(acic) => {
                        setAssigning(null);
                        setNotice(`Assigned to ACIC #${acic.acic_number}.`);
                        void load();
                    }}
                />
            )}

            {viewing && (
                <LddapDetailModal
                    lddap={viewing.lddap}
                    mode={viewing.mode}
                    onClose={() => setViewing(null)}
                    onChanged={() => void load()}
                />
            )}

            {stepping && (
                <LddapStepModal
                    lddap={stepping.lddap}
                    step={stepping.step}
                    onClose={() => setStepping(null)}
                    onDone={(lddap, what) => {
                        setStepping(null);
                        setNotice(`${lddap.lddap_no}: ${what}.`);
                        void load();
                    }}
                />
            )}
        </div>
    );
}
