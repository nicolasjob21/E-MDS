import { useCallback, useEffect, useState } from 'react';
import { Lock, BadgeCheck, CheckCircle2, Clock, Gavel, ListChecks, PencilLine, Search, X } from 'lucide-react';
import { AcicApi, ChequeApi, toApiError } from '../lib/api';
import type { Cheque, Paginated, Summary } from '../lib/types';
import { PageHeader, Spinner, Alert, StatusBadge, EmptyState } from '../components/ui';
import NextChequePanel from '../components/NextChequePanel';
import ChequeDetailModal from '../components/ChequeDetailModal';
import ChequeReviewModal, { type ReviewMode } from '../components/ChequeReviewModal';
import AcicUseModal from '../components/AcicUseModal';
import ChequeUseModal from '../components/ChequeUseModal';
import { useAuth } from '../auth/AuthContext';
import { formatDate, formatMoney } from '../lib/format';

type Tab = 'all' | 'available' | 'used' | 'received' | 'approved' | 'complies' | 'disapproved';

const TABS: { key: Tab; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'available', label: 'Available' },
    { key: 'used', label: 'Used' },
    { key: 'received', label: 'Received' },
    { key: 'approved', label: 'Approved' },
    { key: 'complies', label: 'Returned' },
    { key: 'disapproved', label: 'Disapproved' },
];

export default function ChequesPage() {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin';
    const isStaff = user?.role === 'staff';
    // Admin and staff may both put an approved cheque on an ACIC, and both may use the
    // next-in-line number straight from its row. A teller does neither.
    const canAssign = isAdmin || isStaff;
    const canUse = isAdmin || isStaff;
    const [tab, setTab] = useState<Tab>('all');
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    // Debounced copy — the list only refetches once typing pauses.
    const [query, setQuery] = useState('');
    const [data, setData] = useState<Paginated<Cheque> | null>(null);
    const [selected, setSelected] = useState<{ cheque: Cheque; mode: 'view' | 'action' } | null>(null);
    const [reviewing, setReviewing] = useState<{ cheque: Cheque; mode: ReviewMode } | null>(null);
    const [assigning, setAssigning] = useState<Cheque | null>(null);
    // Admin-only row action on a freshly added (still available) cheque.
    const [usingCheque, setUsingCheque] = useState<Cheque | null>(null);
    const [summary, setSummary] = useState<Summary | null>(null);
    // The number the ACIC opened by the Assign dialog will take.
    const [acicNext, setAcicNext] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const nextNumber = summary?.next?.cheque_number ?? null;

    useEffect(() => {
        const timer = setTimeout(() => setQuery(search.trim()), 300);
        return () => clearTimeout(timer);
    }, [search]);

    // Any new query starts from the first page, or a match on page 3 would be invisible.
    useEffect(() => {
        setPage(1);
    }, [query]);

    const loadList = useCallback(async () => {
        setLoading(true);
        try {
            setData(await ChequeApi.list(tab, page, 50, query));
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [tab, page, query]);

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
    }, []);

    useEffect(() => {
        void loadList();
    }, [loadList]);

    useEffect(() => {
        void loadSummary();
    }, [loadSummary]);

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

            {/* Tabs */}
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
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-4 py-3 font-semibold">Number</th>
                                        <th className="px-4 py-3 font-semibold">Status</th>
                                        <th className="px-4 py-3 font-semibold">Payee</th>
                                        <th className="px-4 py-3 text-right font-semibold">Amount</th>
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
                                                    <StatusBadge
                                                        status={cheque.status}
                                                        complied={cheque.has_pending_update}
                                                        viewer={user?.role}
                                                    />
                                                </td>
                                                <td className="px-4 py-3 text-muted">
                                                    {cheque.payee_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono text-muted">
                                                    {cheque.status !== 'available' ? formatMoney(cheque.amount) : '—'}
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
                                                    ) : cheque.status === 'used' ? (
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
                                                <td className="px-4 py-3 text-right">
                                                    {cheque.status === 'available' ? (
                                                        isNext ? (
                                                            /* A newly added cheque is actionable the
                                                               moment it is the lowest available number.
                                                               Admin and staff use it straight from the
                                                               row; a teller is pointed at the panel. */
                                                            canUse ? (
                                                                <button
                                                                    className="btn btn-primary !px-3 !py-1.5"
                                                                    onClick={() => setUsingCheque(cheque)}
                                                                >
                                                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                                                    Use
                                                                </button>
                                                            ) : (
                                                                <span className="text-xs text-brandink">
                                                                    Use from the panel above ↑
                                                                </span>
                                                            )
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 text-xs text-subtle">
                                                                <Lock className="h-3 w-3" />
                                                                Locked
                                                            </span>
                                                        )
                                                    ) : cheque.status === 'approved' ? (
                                                        /* Approved — the next step is going on an ACIC. */
                                                        cheque.acic_number ? (
                                                            <span className="text-xs text-subtle">
                                                                On ACIC #{cheque.acic_number}
                                                            </span>
                                                        ) : canAssign ? (
                                                            <button
                                                                className="btn btn-outline !px-3 !py-1.5"
                                                                onClick={() => setAssigning(cheque)}
                                                            >
                                                                <ListChecks className="h-3.5 w-3.5" />
                                                                Assign
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-subtle">
                                                                Awaiting assignment
                                                            </span>
                                                        )
                                                    ) : cheque.is_final ? (
                                                        <span className="text-xs text-subtle">
                                                            Reviewed {formatDate(cheque.reviewed_at)}
                                                        </span>
                                                    ) : cheque.awaits_compliance ? (
                                                        /* Returned — the staff member's turn to fix the
                                                           details; the admin waits for that update. */
                                                        cheque.has_pending_update ? (
                                                            <span className="text-xs text-subtle">
                                                                Update awaiting admin approval
                                                            </span>
                                                        ) : isStaff && cheque.used_by?.id === user?.id ? (
                                                            /* Returned to this staff member — only
                                                               they can act on it. */
                                                            <button
                                                                className="btn btn-primary !px-3 !py-1.5"
                                                                onClick={() =>
                                                                    setSelected({ cheque, mode: 'action' })
                                                                }
                                                            >
                                                                <PencilLine className="h-3.5 w-3.5" />
                                                                Action
                                                            </button>
                                                        ) : isAdmin ? (
                                                            <button
                                                                className="btn btn-outline !px-3 !py-1.5"
                                                                onClick={() =>
                                                                    setReviewing({ cheque, mode: 'review' })
                                                                }
                                                            >
                                                                <Gavel className="h-3.5 w-3.5" />
                                                                Review
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-subtle">
                                                                Returned to{' '}
                                                                {cheque.used_by?.name ??
                                                                    cheque.used_by_name ??
                                                                    'the staff member who used it'}
                                                            </span>
                                                        )
                                                    ) : isAdmin ? (
                                                        /* Used (or received) — awaiting the admin's review. */
                                                        cheque.has_pending_update ? (
                                                            <span className="text-xs text-subtle">
                                                                On hold — resolve the update request first
                                                            </span>
                                                        ) : (
                                                            <button
                                                                className="btn btn-outline !px-3 !py-1.5"
                                                                onClick={() =>
                                                                    setReviewing({ cheque, mode: 'review' })
                                                                }
                                                            >
                                                                <Gavel className="h-3.5 w-3.5" />
                                                                Review
                                                            </button>
                                                        )
                                                    ) : (
                                                        <span className="text-xs text-subtle">Awaiting review</span>
                                                    )}
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

            {reviewing && (
                <ChequeReviewModal
                    cheque={reviewing.cheque}
                    mode={reviewing.mode}
                    onClose={() => setReviewing(null)}
                    onReviewed={() => {
                        setReviewing(null);
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
