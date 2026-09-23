import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    Lock, BadgeCheck, CheckCircle2, Clock, Eye, ListChecks, Search, X,
    AlertTriangle, ArrowDownWideNarrow, Send, Inbox, RefreshCw, Undo2, Ban, Slash,
} from 'lucide-react';
import { AcicApi, ChequeApi, toApiError } from '../lib/api';
import type { Cheque, ChequeTab, ChequeValiditySummary, Paginated, Summary } from '../lib/types';
import { PageHeader, Spinner, Alert, StatusBadge, EmptyState, ValidityBadge } from '../components/ui';
import NextChequePanel from '../components/NextChequePanel';
import ChequeStepModal, { type ChequeStep } from '../components/ChequeStepModal';
import ChequeDetailModal from '../components/ChequeDetailModal';
import AcicUseModal from '../components/AcicUseModal';
import ChequeUseModal from '../components/ChequeUseModal';
import ChequeViewModal from '../components/ChequeViewModal';
import { useAuth } from '../auth/AuthContext';
import { formatDate, formatMoney } from '../lib/format';

/** The validity tabs, in the order the page shows them. */
/** The tabs the page shows. */
const VALIDITY_TABS: { key: ChequeTab; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'available', label: 'Available' },
    { key: 'registered', label: 'Registered' },
    { key: 'released_to_payee', label: 'Released' },
    { key: 'completed', label: 'Completed' },
    { key: 'expiring', label: 'Expiring Soon' },
    { key: 'stale', label: 'Stale' },
    { key: 'cancelled', label: 'Cancelled' },
    { key: 'voided', label: 'Voided' },
];

/**
 * Filters that work but have no button of their own — the mid-flow statuses. The dashboard
 * links straight to them, so the page still has to honour them; it just shows the active one
 * as a chip beside the tabs rather than carrying a button for each.
 */
const EXTRA_TABS: { key: ChequeTab; label: string }[] = [
    { key: 'out_for_signature', label: 'Out for Signature' },
    { key: 'for_acic', label: 'For ACIC' },
    { key: 'approved', label: 'Approved' },
    { key: 'forwarded_to_teller', label: 'Forwarded to Teller' },
    { key: 'forwarded_to_land_bank', label: 'With Land Bank' },
    { key: 'returned_by_bank', label: 'Returned by Bank' },
    { key: 'accepted_by_teller', label: 'Accepted by Teller' },
];


function validityTabFrom(value: string | null): ChequeTab {
    return [...VALIDITY_TABS, ...EXTRA_TABS].some((t) => t.key === value) ? (value as ChequeTab) : 'all';
}

/** The label for a filter that has no tab of its own, when one is active. */
function extraTabLabel(tab: ChequeTab): string | null {
    return EXTRA_TABS.find((t) => t.key === tab)?.label ?? null;
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
    const isAdmin = user?.role === 'admin';
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
    const [assigning, setAssigning] = useState<Cheque | null>(null);
    // Admin-only row action on a freshly added (still available) cheque.
    const [usingCheque, setUsingCheque] = useState<Cheque | null>(null);
    // The cheque face, for an approved cheque only.
    const [viewingCheque, setViewingCheque] = useState<Cheque | null>(null);
    const [summary, setSummary] = useState<Summary | null>(null);
    // The validity axis: which tab, how it is sorted, and the banner's counts.
    const [vTab, setVTab] = useState<ChequeTab>(() => validityTabFrom(params.get('tab')));
    const [byExpiry, setByExpiry] = useState(false);
    const [validity, setValidity] = useState<ChequeValiditySummary | null>(null);
    const [moving, setMoving] = useState<{ cheque: Cheque; step: ChequeStep } | null>(null);
    // The number the ACIC opened by the Assign dialog will take.
    const [acicNext, setAcicNext] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const nextNumber = summary?.next?.cheque_number ?? null;

    useEffect(() => {
        const timer = setTimeout(() => setQuery(search.trim()), 300);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        setVTab(validityTabFrom(params.get('tab')));
        setPage(1);
    }, [params]);

    // Any new query starts from the first page, or a match on page 3 would be invisible.
    useEffect(() => {
        setPage(1);
    }, [query]);

    const loadList = useCallback(async () => {
        setLoading(true);
        try {
            setData(await ChequeApi.list('all', page, 50, query, vTab, byExpiry ? 'expiry' : 'number'));
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [page, query, vTab, byExpiry]);

    const loadSummary = useCallback(async () => {
        try {
            setSummary(await ChequeApi.summary());
        } catch {
            /* non-fatal */
        }
        try {
            setAcicNext(await AcicApi.next());
        } catch {
            /* non-fatal — the Assign dialog just won't preview the number */
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
            <PageHeader title="Cheques" subtitle="The full running sequence of cheque numbers." />

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

            {/* The flow's statuses — the page's one filter. */}
            <div className="mb-4 flex flex-wrap items-center gap-2 border-b border-line pb-2">
                <div className="flex flex-wrap gap-1">
                    {VALIDITY_TABS.map(({ key, label }) => (
                        <button
                            key={key}
                            onClick={() => {
                                setVTab(key);
                                setPage(1);
                            }}
                            className={`rounded-xs border px-3 py-1.5 font-display text-xs font-semibold uppercase tracking-wider transition-colors ${
                                vTab === key
                                    ? 'border-brand-400 bg-brand-500/15 text-brandink'
                                    : 'border-line text-subtle hover:text-muted'
                            }`}
                        >
                            {label}
                            {key === 'expiring' && validity && validity.expiring_soon.total > 0 && (
                                <span className="ml-1.5 text-amber-400">{validity.expiring_soon.total}</span>
                            )}
                            {key === 'stale' && validity && validity.stale > 0 && (
                                <span className="ml-1.5 text-danger-fg">{validity.stale}</span>
                            )}
                        </button>
                    ))}
                </div>
                {extraTabLabel(vTab) && (
                    <button
                        type="button"
                        onClick={() => {
                            setVTab('all');
                            setPage(1);
                        }}
                        className="inline-flex items-center gap-1.5 rounded-xs border border-brand-400 bg-brand-500/15 px-3 py-1.5 font-display text-xs font-semibold uppercase tracking-wider text-brandink"
                        title="Clear this filter"
                    >
                        {extraTabLabel(vTab)}
                        <X className="h-3 w-3" />
                    </button>
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

            {/* Search */}
            <div className="mb-4">
                <label htmlFor="cheque-search" className="sr-only">
                    Search by cheque number or ACIC no.
                </label>
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                    <input
                        id="cheque-search"
                        type="search"
                        className="field !pl-10 !pr-10"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search by cheque number or ACIC no.…"
                    />
                    {search && (
                        <button
                            type="button"
                            onClick={() => setSearch('')}
                            aria-label="Clear search"
                            className="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 text-subtle hover:text-fg"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    )}
                </div>
                {query && data && (
                    <p className="mt-2 text-xs text-subtle" aria-live="polite">
                        {data.meta.total.toLocaleString()} match{data.meta.total === 1 ? '' : 'es'} for “{query}”
                    </p>
                )}
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
                    {query
                        ? `No cheque matches “${query}” in this view.`
                        : 'No cheques to show in this view.'}
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
                                                        {cheque.has_pending_update && (
                                                            <span className="rounded-xs border border-accent-400/50 bg-accent-400/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-accent-400">
                                                                On hold
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
                                                        cheque.has_pending_update ? (
                                                            <span className="inline-flex items-center gap-1 rounded-xs border border-accent-400/50 bg-accent-400/10 px-2 py-0.5 text-xs font-medium text-accent-400">
                                                                <Clock className="h-3 w-3" />
                                                                On hold
                                                            </span>
                                                        ) : (
                                                            <span className="text-xs text-subtle">Pending</span>
                                                        )
                                                    ) : (
                                                        <span className="text-muted">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right whitespace-nowrap">
                                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                                        {/* The one step this cheque is ready for, then the ways out. */}
                                                        {cheque.can_route && (
                                                            <button className="btn btn-primary !px-3 !py-1.5" onClick={() => setMoving({ cheque, step: 'route' })}>
                                                                <Send className="h-3.5 w-3.5" />
                                                                Route for Signature
                                                            </button>
                                                        )}
                                                        {cheque.can_receive && (
                                                            <button className="btn btn-primary !px-3 !py-1.5" onClick={() => setMoving({ cheque, step: 'receive' })}>
                                                                <Inbox className="h-3.5 w-3.5" />
                                                                Mark as Received
                                                            </button>
                                                        )}
                                                        {cheque.can_assign && (
                                                            <button className="btn btn-outline !px-3 !py-1.5" onClick={() => setAssigning(cheque)}>
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
                                                        {cheque.can_rts && (
                                                            <button className="btn btn-ghost !px-3 !py-1.5" onClick={() => setMoving({ cheque, step: 'rts' })}>
                                                                <Undo2 className="h-3.5 w-3.5" />
                                                                RTS
                                                            </button>
                                                        )}
                                                        {cheque.can_cancel && (
                                                            <button className="btn btn-ghost !px-3 !py-1.5 !text-danger" onClick={() => setMoving({ cheque, step: 'cancel' })}>
                                                                <Ban className="h-3.5 w-3.5" />
                                                                Cancel
                                                            </button>
                                                        )}
                                                        {cheque.can_void && (
                                                            <button className="btn btn-ghost !px-3 !py-1.5 !text-danger" onClick={() => setMoving({ cheque, step: 'void' })}>
                                                                <Slash className="h-3.5 w-3.5" />
                                                                Void
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
                                                                    Register
                                                                </button>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 text-xs text-subtle">
                                                                    <Lock className="h-3 w-3" />
                                                                    {isNext ? 'Register from the panel above' : 'Locked'}
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

            {viewingCheque && (
                <ChequeViewModal cheque={viewingCheque} onClose={() => setViewingCheque(null)} />
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
                /* The same dialog the ACIC page's "Assign cheque to ACIC" uses: one cheque, onto
                   the next number in the sequence, which is opened on submit. */
                <AcicUseModal
                    nextNumber={acicNext}
                    single
                    preselect={assigning.id}
                    onClose={() => setAssigning(null)}
                    onAssigned={() => {
                        setAssigning(null);
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
