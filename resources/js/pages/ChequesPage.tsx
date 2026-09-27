import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    Lock, BadgeCheck, CheckCircle2, Eye, Filter, ListChecks, Search, X,
    AlertTriangle, ArrowDownWideNarrow, RefreshCw, Undo2, Ban, Slash, Printer, Pencil, FileText,
} from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque, ChequeTab, ChequeValiditySummary, Paginated, Summary } from '../lib/types';
import { PageHeader, Spinner, Alert, StatusBadge, EmptyState, ValidityBadge } from '../components/ui';
import NextChequePanel from '../components/NextChequePanel';
import ChequeStepModal, { type ChequeStep } from '../components/ChequeStepModal';
import ChequeDetailModal from '../components/ChequeDetailModal';
import ChequeAssignModal from '../components/ChequeAssignModal';
import ChequeUseModal from '../components/ChequeUseModal';
import ChequeViewModal, { type ChequePrintMode } from '../components/ChequeViewModal';
import ChequeEditModal from '../components/ChequeEditModal';
import UnitSelect from '../components/UnitSelect';
import { useAuth } from '../auth/AuthContext';
import { formatDate, formatMoney } from '../lib/format';

/**
 * The Status filter: every status a cheque can hold today, in the order of the flow. The blank
 * status (stored `registered`) is "No Status". The retired statuses — Out for Signature,
 * Received, For ACIC — are left out; no cheque holds them any more.
 */
const STATUS_OPTIONS: { key: ChequeTab; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'registered', label: 'No Status' },
    { key: 'available', label: 'Available' },
    { key: 'for_checking', label: 'For Checking' },
    { key: 'for_compliance', label: 'For Compliance' },
    { key: 'for_final_print', label: 'For Final Print' },
    { key: 'for_signature', label: 'For Signature' },
    { key: 'approved', label: 'Approved' },
    { key: 'released_to_payee', label: 'Released to Payee' },
    { key: 'forwarded_to_teller', label: 'Forwarded to Teller' },
    { key: 'accepted_by_teller', label: 'Accepted' },
    { key: 'forwarded_to_land_bank', label: 'Forwarded to LBP' },
    { key: 'forwarded_to_payee', label: 'Forwarded to Payee' },
    { key: 'returned', label: 'Returned' },
    { key: 'completed', label: 'Completed' },
    { key: 'cancelled', label: 'Cancelled' },
    { key: 'spoiled', label: 'Spoiled' },
    { key: 'stale', label: 'Stale' },
];

/** Expiring Soon is a view, not a status: offered in the dropdown only while it is on. */
const EXPIRING: { key: ChequeTab; label: string } = { key: 'expiring', label: 'Expiring Soon' };

/** `?tab=` from a dashboard link, when it is one the page knows. */
function statusFrom(value: string | null): ChequeTab {
    return [...STATUS_OPTIONS, EXPIRING].some((t) => t.key === value) ? (value as ChequeTab) : 'all';
}

/** " (2 pending signature, 1 with payee, 1 with teller)" — only the parts that apply. */
function expiringBreakdown(v: ChequeValiditySummary): string {
    const parts = [
        v.expiring_soon.assigned ? `${v.expiring_soon.assigned} pending signature` : null,
        v.expiring_soon.released ? `${v.expiring_soon.released} with payee` : null,
        v.expiring_soon.for_deposit ? `${v.expiring_soon.for_deposit} with teller` : null,
    ].filter(Boolean);

    return parts.length > 0 ? ` (${parts.join(', ')})` : '';
}

export default function ChequesPage() {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin' || user?.role === 'super_admin';
    const isStaff = user?.role === 'staff';
    // Admin and staff may both put an approved cheque on an ACIC, and both may use the
    // next-in-line number straight from its row. A teller does neither.
    const canUse = isAdmin || isStaff;
    // `?status=` picks the tab, so the dashboard's tiles land on the right list.
    const [params] = useSearchParams();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    // Debounced copy — the list only refetches once typing pauses.
    const [query, setQuery] = useState('');
    const [data, setData] = useState<Paginated<Cheque> | null>(null);
    const [selected, setSelected] = useState<{ cheque: Cheque; mode: 'view' | 'action' } | null>(null);
    // Assign Cheque to ACIC: from the header (nothing ticked) or a row (that cheque ticked).
    const [assigning, setAssigning] = useState<{ preselect: Cheque | null } | null>(null);
    // Admin-only row action on a freshly added (still available) cheque.
    const [usingCheque, setUsingCheque] = useState<Cheque | null>(null);
    // The cheque face, for an approved cheque only.
    // The print view: reprint (view), Print Draft (draft) or Final Print (final).
    const [printing, setPrinting] = useState<{ cheque: Cheque; mode: ChequePrintMode } | null>(null);
    const [editing, setEditing] = useState<Cheque | null>(null);
    const [summary, setSummary] = useState<Summary | null>(null);
    // The validity axis: which tab, how it is sorted, and the banner's counts.
    const [vTab, setVTab] = useState<ChequeTab>(() => statusFrom(params.get('tab')));
    const [unit, setUnit] = useState('');
    const [appliedFrom, setAppliedFrom] = useState('');
    const [appliedTo, setAppliedTo] = useState('');
    // What Status, Unit and the dates show — applied only by the Filter button.
    const [draft, setDraft] = useState<{ tab: ChequeTab; unit: string; dateFrom: string; dateTo: string }>(() => ({
        tab: statusFrom(params.get('tab')),
        unit: '',
        dateFrom: '',
        dateTo: '',
    }));
    const [byExpiry, setByExpiry] = useState(false);
    const [validity, setValidity] = useState<ChequeValiditySummary | null>(null);
    const [moving, setMoving] = useState<{ cheque: Cheque; step: ChequeStep } | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const nextNumber = summary?.next?.cheque_number ?? null;
    // A start after the end is flagged, and Filter leaves the dates out rather than showing nothing.
    const datesBackwards = draft.dateFrom !== '' && draft.dateTo !== '' && draft.dateFrom > draft.dateTo;
    const isFiltered = query !== '' || vTab !== 'all' || unit !== '' || appliedFrom !== '' || appliedTo !== '';

    useEffect(() => {
        const timer = setTimeout(() => setQuery(search.trim()), 300);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        const tab = statusFrom(params.get('tab'));
        setVTab(tab);
        setDraft((d) => ({ ...d, tab }));
        setPage(1);
    }, [params]);

    // Any new filter starts from the first page, or a match on page 3 would be invisible.
    useEffect(() => {
        setPage(1);
    }, [query, unit, appliedFrom, appliedTo]);

    /**
     * Filter / Enter: apply Status, Unit and the dates as chosen, take the search as typed now
     * without waiting out the pause, and refetch.
     */
    function applyFilters(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const from = datesBackwards ? '' : draft.dateFrom;
        const to = datesBackwards ? '' : draft.dateTo;
        const term = search.trim();
        const unchanged =
            draft.tab === vTab && draft.unit === unit && from === appliedFrom && to === appliedTo && term === query && page === 1;
        setVTab(draft.tab);
        setUnit(draft.unit);
        setAppliedFrom(from);
        setAppliedTo(to);
        setQuery(term);
        setPage(1);
        // Nothing new to apply: refetch anyway, so Filter always shows the latest.
        if (unchanged) {
            void loadList();
        }
    }

    function clearFilters() {
        setSearch('');
        setQuery('');
        setVTab('all');
        setUnit('');
        setAppliedFrom('');
        setAppliedTo('');
        setDraft({ tab: 'all', unit: '', dateFrom: '', dateTo: '' });
        setPage(1);
    }

    const loadList = useCallback(async () => {
        setLoading(true);
        try {
            setData(
                await ChequeApi.list({
                    page,
                    search: query,
                    tab: vTab,
                    sort: byExpiry ? 'expiry' : 'number',
                    unit,
                    dateFrom: appliedFrom,
                    dateTo: appliedTo,
                }),
            );
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [page, query, vTab, byExpiry, unit, appliedFrom, appliedTo]);

    const loadSummary = useCallback(async () => {
        try {
            setSummary(await ChequeApi.summary());
        } catch {
            /* non-fatal */
        }
        try {
            setValidity(await ChequeApi.validitySummary());
        } catch {
            /* non-fatal — the banner and the teller list just won't show */
        }
    }, []);

    useEffect(() => {
        void loadList();
    }, [loadList]);

    useEffect(() => {
        void loadSummary();
    }, [loadSummary]);

    async function handleReplace(cheque: Cheque) {
        try {
            const replacement = await ChequeApi.replace(cheque.id);
            setNotice(`Cheque #${cheque.cheque_number} replaced by #${replacement.cheque_number}.`);
            refreshAll();
        } catch (err) {
            setError(toApiError(err).message);
        }
    }

    function refreshAll() {
        void loadList();
        void loadSummary();
    }

    return (
        <div>
            <PageHeader
                title="Cheques"
                subtitle="The full running sequence of cheque numbers."
                action={
                    canUse ? (
                        <button className="btn btn-outline" onClick={() => setAssigning({ preselect: null })}>
                            <ListChecks className="h-4 w-4" />
                            Assign Cheque to ACIC
                        </button>
                    ) : undefined
                }
            />

            <div className="mb-6">
                <NextChequePanel next={summary?.next ?? null} onUsed={refreshAll} compact />
            </div>

            {/* What is about to go stale, and how to get to it. */}
            {validity && validity.expiring_soon.total > 0 && vTab !== 'expiring' && (
                <button
                    type="button"
                    className="mb-4 flex w-full items-start gap-3 rounded-xs border border-amber-400/50 bg-amber-400/10 p-4 text-left transition-colors hover:bg-amber-400/15"
                    onClick={() => {
                        setVTab('expiring');
                        setDraft((d) => ({ ...d, tab: 'expiring' }));
                        setPage(1);
                    }}
                >
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-400" />
                    <span className="min-w-0">
                        <span className="block text-sm font-semibold text-fg">
                            {validity.expiring_soon.total}{' '}
                            {validity.expiring_soon.total === 1 ? 'cheque' : 'cheques'} will become stale within{' '}
                            {validity.alert_days} days
                            {expiringBreakdown(validity)}.
                        </span>
                        <span className="mt-0.5 block text-xs text-muted">Click to see just those cheques.</span>
                    </span>
                </button>
            )}

            {/* Filters — all applied on the server, together. Search waits for a pause in typing;
                the rest apply as soon as they change. */}
            <form
                className="card mb-3 grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(10rem,1fr)_10rem_12rem_9.5rem_9.5rem] lg:items-end"
                role="search"
                aria-label="Filter cheques"
                onSubmit={applyFilters}
            >
                <div className="sm:col-span-2 lg:col-span-1">
                    <label className="label" htmlFor="cheque-search">
                        Search
                    </label>
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                        <input
                            id="cheque-search"
                            type="search"
                            className="field !py-1.5 !pl-10"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cheque no., payee, or account no."
                            maxLength={100}
                        />
                    </div>
                </div>
                <div>
                    <label className="label" htmlFor="cheque-filter-status">
                        Status
                    </label>
                    <select
                        id="cheque-filter-status"
                        className="field !py-1.5"
                        value={draft.tab}
                        onChange={(e) => {
                            const tab = statusFrom(e.target.value);
                            setDraft((d) => ({ ...d, tab }));
                        }}
                    >
                        {STATUS_OPTIONS.map(({ key, label }) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                        {draft.tab === EXPIRING.key && <option value={EXPIRING.key}>{EXPIRING.label}</option>}
                    </select>
                </div>
                <div>
                    <label className="label" htmlFor="cheque-filter-unit">
                        Unit
                    </label>
                    <UnitSelect id="cheque-filter-unit" className="field !py-1.5" value={draft.unit} onChange={(u) => setDraft((d) => ({ ...d, unit: u }))} placeholder="All" />
                </div>
                <div>
                    <label className="label" htmlFor="cheque-filter-from">
                        Date Start
                    </label>
                    <input
                        id="cheque-filter-from"
                        type="date"
                        className="field !py-1.5"
                        value={draft.dateFrom}
                        onChange={(e) => {
                            const value = e.target.value;
                            setDraft((d) => ({ ...d, dateFrom: value }));
                        }}
                        aria-invalid={datesBackwards}
                        aria-describedby={datesBackwards ? 'cheque-filter-dates-error' : undefined}
                    />
                </div>
                <div>
                    <label className="label" htmlFor="cheque-filter-to">
                        Date End
                    </label>
                    <input
                        id="cheque-filter-to"
                        type="date"
                        className="field !py-1.5"
                        value={draft.dateTo}
                        onChange={(e) => {
                            const value = e.target.value;
                            setDraft((d) => ({ ...d, dateTo: value }));
                        }}
                        aria-invalid={datesBackwards}
                        aria-describedby={datesBackwards ? 'cheque-filter-dates-error' : undefined}
                    />
                </div>
                {datesBackwards && (
                    <p id="cheque-filter-dates-error" role="alert" className="text-xs text-danger-fg sm:col-span-2 lg:col-span-5">
                        Date Start is after Date End, so Filter will leave the dates out. Change one of them.
                    </p>
                )}
                <div className="flex flex-col gap-2 sm:col-span-2 sm:flex-row sm:justify-end lg:col-span-5">
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

            <div className="mb-4 flex flex-wrap items-center gap-2">
                {data && (
                    <p className="text-xs uppercase tracking-wider text-subtle" aria-live="polite">
                        Showing {data.meta.total.toLocaleString()} {data.meta.total === 1 ? 'cheque' : 'cheques'}
                        {isFiltered ? ' · filtered' : ''}
                    </p>
                )}
                <button
                    type="button"
                    onClick={() => {
                        setByExpiry((v) => !v);
                        setPage(1);
                    }}
                    aria-pressed={byExpiry}
                    className={`ml-auto inline-flex items-center gap-1.5 rounded-xs border px-3 py-1.5 text-xs transition-colors ${
                        byExpiry ? 'border-brand-400 bg-brand-500/15 text-brandink' : 'border-line text-subtle hover:text-muted'
                    }`}
                >
                    <ArrowDownWideNarrow className="h-3.5 w-3.5" />
                    Nearest expiry first
                </button>
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
                    {isFiltered ? 'No cheque matches these filters.' : 'No cheques to show.'}
                </EmptyState>
            ) : (
                data && (
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            {/* Ten columns, and an action cell that can hold three buttons: give
                                the table room and let the container scroll, rather than crushing
                                the Action column until its buttons are unusable. */}
                            <table className="w-full min-w-[78rem] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-4 py-3 font-semibold">Number</th>
                                        <th className="px-4 py-3 font-semibold">Status</th>
                                        <th className="px-4 py-3 font-semibold">Validity</th>
                                        <th className="px-4 py-3 font-semibold">Payee</th>
                                        <th className="px-4 py-3 text-right font-semibold">Amount</th>
                                        <th className="px-4 py-3 font-semibold">Received by</th>
                                        <th className="px-4 py-3 font-semibold">Used by</th>
                                        <th className="px-4 py-3 font-semibold">ACIC no.</th>
                                        <th className="px-4 py-3 font-semibold">Receipt</th>
                                        <th className="px-4 py-3 text-right font-semibold">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.data.map((cheque) => {
                                        const isNext = cheque.cheque_number === nextNumber;
                                        return (
                                            <tr
                                                key={cheque.id}
                                                className={`border-b border-line/60 last:border-0 ${
                                                    isNext ? 'bg-brand-500/10' : ''
                                                }`}
                                            >
                                                <td className="px-4 py-3">
                                                    <button
                                                        onClick={() => setSelected({ cheque, mode: 'view' })}
                                                        title="View cheque details"
                                                        className={`font-display font-bold underline-offset-4 hover:underline ${
                                                            isNext ? 'text-brandink' : 'text-fg'
                                                        }`}
                                                    >
                                                        #{cheque.cheque_number}
                                                    </button>
                                                    {isNext && (
                                                        <span className="ml-2 rounded-xs bg-brand-500/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-brandink">
                                                            Next
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="inline-flex flex-col items-start gap-1">
                                                        <StatusBadge status={cheque.effective_status ?? cheque.status} />
                                                        {cheque.replaced_by && (
                                                            <span className="text-[11px] text-muted">
                                                                Replaced by Cheque #{cheque.replaced_by.cheque_number}
                                                            </span>
                                                        )}
                                                        {cheque.replaces && (
                                                            <span className="text-[11px] text-muted">
                                                                Replaces Cheque #{cheque.replaces.cheque_number}
                                                            </span>
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <ValidityBadge cheque={cheque} />
                                                </td>
                                                <td className="px-4 py-3 text-muted">
                                                    {cheque.payee_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono text-muted">
                                                    {cheque.status !== 'available' ? formatMoney(cheque.amount) : '—'}
                                                </td>
                                                {/* Who the cheque was handed to — only a released one has been. */}
                                                <td className="px-4 py-3 text-muted">
                                                    {cheque.release?.received_by_name ? (
                                                        <>
                                                            {cheque.release.received_by_name}
                                                            <span className="mt-0.5 block text-xs text-subtle">
                                                                {formatDate(cheque.release.date_received)}
                                                            </span>
                                                        </>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted">
                                                    {cheque.used_by?.name ?? cheque.used_by_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs whitespace-nowrap text-muted">
                                                    {cheque.acic_number ? `#${cheque.acic_number}` : '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {cheque.received_at ? (
                                                        <span className="inline-flex items-center gap-1 rounded-xs border border-success/40 bg-success/10 px-2 py-0.5 text-xs font-medium text-success-fg">
                                                            <BadgeCheck className="h-3 w-3" />
                                                            {formatDate(cheque.received_at)}
                                                        </span>
                                                    ) : cheque.status === 'registered' ? (
                                                        <span className="text-xs text-subtle">Pending</span>
                                                    ) : (
                                                        <span className="text-muted">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right whitespace-nowrap">
                                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                                        {/* The one step this cheque is ready for, then the ways out. */}
                                                        {cheque.can_edit && (
                                                            <button className="btn btn-ghost !px-3 !py-1.5" onClick={() => setEditing(cheque)}>
                                                                <Pencil className="h-3.5 w-3.5" />
                                                                Edit
                                                            </button>
                                                        )}
                                                        {cheque.can_print_draft && (
                                                            <button className="btn btn-primary !px-3 !py-1.5" onClick={() => setPrinting({ cheque, mode: 'draft' })}>
                                                                <FileText className="h-3.5 w-3.5" />
                                                                Print Draft
                                                            </button>
                                                        )}
                                                        {/* Both open the draft itself, so it is checked
                                                            against the face as printed. */}
                                                        {cheque.can_check_draft && (
                                                            <>
                                                                <button className="btn btn-primary !px-3 !py-1.5" onClick={() => setPrinting({ cheque, mode: 'check' })}>
                                                                    <BadgeCheck className="h-3.5 w-3.5" />
                                                                    Approve
                                                                </button>
                                                                <button className="btn btn-ghost !px-3 !py-1.5" onClick={() => setPrinting({ cheque, mode: 'check' })}>
                                                                    <Undo2 className="h-3.5 w-3.5" />
                                                                    Return
                                                                </button>
                                                            </>
                                                        )}
                                                        {cheque.effective_status === 'for_checking' && !cheque.can_check_draft && (
                                                            <span className="text-xs text-subtle">With the admin in charge</span>
                                                        )}
                                                        {cheque.can_final_print && (
                                                            <button className="btn btn-primary !px-3 !py-1.5" onClick={() => setPrinting({ cheque, mode: 'final' })}>
                                                                <Printer className="h-3.5 w-3.5" />
                                                                Final Print
                                                            </button>
                                                        )}
                                                        {cheque.can_assign && (
                                                            <button className="btn btn-outline !px-3 !py-1.5" onClick={() => setAssigning({ preselect: cheque })}>
                                                                <ListChecks className="h-3.5 w-3.5" />
                                                                Assign to ACIC
                                                            </button>
                                                        )}
                                                        {/* Releasing is taken from the ACIC's own
                                                            view, against the cheques beside it. */}
                                                        {cheque.can_release && cheque.acic_number && (
                                                            <span className="text-xs text-subtle">
                                                                Release from ACIC #{cheque.acic_number}
                                                            </span>
                                                        )}
                                                        {cheque.can_cancel && (
                                                            <button className="btn btn-ghost !px-3 !py-1.5 !text-danger" onClick={() => setMoving({ cheque, step: 'cancel' })}>
                                                                <Ban className="h-3.5 w-3.5" />
                                                                Cancel
                                                            </button>
                                                        )}
                                                        {cheque.can_spoil && (
                                                            <button className="btn btn-ghost !px-3 !py-1.5 !text-danger" onClick={() => setMoving({ cheque, step: 'spoil' })}>
                                                                <Slash className="h-3.5 w-3.5" />
                                                                Spoiled
                                                            </button>
                                                        )}
                                                        {cheque.can_replace && (
                                                            <button className="btn btn-outline !px-3 !py-1.5" onClick={() => void handleReplace(cheque)}>
                                                                <RefreshCw className="h-3.5 w-3.5" />
                                                                Replace
                                                            </button>
                                                        )}
                                                        {cheque.effective_status === 'available' && (
                                                            isNext && canUse ? (
                                                                <button className="btn btn-primary !px-3 !py-1.5" onClick={() => setUsingCheque(cheque)}>
                                                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                                                    Use
                                                                </button>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 text-xs text-subtle">
                                                                    <Lock className="h-3 w-3" />
                                                                    {isNext ? 'Use from the panel above' : 'Locked'}
                                                                </span>
                                                            )
                                                        )}
                                                        {/* Nothing to do: say where it is instead. */}
                                                        {cheque.effective_status === 'forwarded_to_teller' && (
                                                            <span className="text-xs text-subtle">With the tellers — unclaimed</span>
                                                        )}
                                                        {cheque.effective_status === 'accepted_by_teller' && (
                                                            <span className="text-xs text-subtle">
                                                                With {cheque.acic_teller?.accepted_by?.name ?? 'a teller'}
                                                            </span>
                                                        )}
                                                        {/* The cheque face, at its real size, for
                                                            printing onto pre-printed stock. */}
                                                        {cheque.can_print && (
                                                            <button
                                                                className="btn btn-ghost !px-3 !py-1.5"
                                                                onClick={() => setPrinting({ cheque, mode: 'view' })}
                                                                title={`Print cheque #${cheque.cheque_number}`}
                                                            >
                                                                <Printer className="h-3.5 w-3.5" />
                                                                Print
                                                            </button>
                                                        )}
                                                        <button
                                                            className="btn btn-ghost !px-3 !py-1.5"
                                                            onClick={() => setSelected({ cheque, mode: 'view' })}
                                                            title="View the cheque and its history"
                                                        >
                                                            <Eye className="h-3.5 w-3.5" />
                                                            View
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
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

            {moving && (
                <ChequeStepModal
                    cheque={moving.cheque}
                    step={moving.step}
                    onClose={() => setMoving(null)}
                    onDone={(cheque, what) => {
                        setMoving(null);
                        setNotice(`Cheque #${cheque.cheque_number} ${what}.`);
                        refreshAll();
                    }}
                />
            )}

            {printing && (
                <ChequeViewModal
                    cheque={printing.cheque}
                    mode={printing.mode}
                    onClose={() => setPrinting(null)}
                    onDone={(cheque, what) => {
                        setPrinting(null);
                        setNotice(`Cheque #${cheque.cheque_number} ${what}.`);
                        refreshAll();
                    }}
                />
            )}

            {editing && (
                <ChequeEditModal
                    cheque={editing}
                    onClose={() => setEditing(null)}
                    onSaved={(cheque) => {
                        setEditing(null);
                        setNotice(`Cheque #${cheque.cheque_number} updated.`);
                        refreshAll();
                    }}
                />
            )}

            {usingCheque && (
                <ChequeUseModal
                    cheque={usingCheque}
                    onClose={() => setUsingCheque(null)}
                    onUsed={() => {
                        setUsingCheque(null);
                        refreshAll();
                    }}
                />
            )}

            {assigning && (
                /* Many cheques may share one ACIC number: tick them, type the number. */
                <ChequeAssignModal
                    preselect={assigning.preselect}
                    onClose={() => setAssigning(null)}
                    onAssigned={(acic) => {
                        setAssigning(null);
                        setNotice(`Cheques assigned to ACIC #${acic.acic_number}.`);
                        refreshAll();
                    }}
                />
            )}


            {selected && (
                <ChequeDetailModal
                    cheque={selected.cheque}
                    mode={selected.mode}
                    onClose={() => setSelected(null)}
                    onChanged={(updated) => {
                        setSelected((prev) => (prev ? { ...prev, cheque: updated } : prev));
                        refreshAll();
                    }}
                />
            )}
        </div>
    );
}
