import { useCallback, useEffect, useState } from 'react';
import { Hash, ListChecks, Search, Gavel, PencilLine } from 'lucide-react';
import { LddapApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Lddap, LddapStatus, Paginated } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState, LddapStatusBadge } from '../components/ui';
import LddapUseModal from '../components/LddapUseModal';
import LddapAssignModal from '../components/LddapAssignModal';
import LddapReviewModal from '../components/LddapReviewModal';
import LddapDetailModal from '../components/LddapDetailModal';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';

type Tab = 'all' | LddapStatus;

const TABS: { key: Tab; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'used', label: 'Used' },
    { key: 'received', label: 'Received' },
    { key: 'approved', label: 'Approved' },
    { key: 'compliance', label: 'Returned' },
    { key: 'cancelled', label: 'Cancelled' },
];

export default function LddapsPage() {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin';
    const isStaff = user?.role === 'staff';
    const canManage = isAdmin || user?.role === 'staff';

    const [tab, setTab] = useState<Tab>('all');
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [data, setData] = useState<Paginated<Lddap> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const [using, setUsing] = useState(false);
    const [assigning, setAssigning] = useState<{ preselect: Lddap | null } | null>(null);
    const [reviewing, setReviewing] = useState<Lddap | null>(null);
    const [viewing, setViewing] = useState<{ lddap: Lddap; mode: 'view' | 'action' } | null>(null);

    // Debounce the search box so typing doesn't fire a request per keystroke.
    useEffect(() => {
        const id = setTimeout(() => {
            setQuery(search.trim());
            setPage(1);
        }, 300);
        return () => clearTimeout(id);
    }, [search]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            setData(await LddapApi.list(tab, page, 50, query));
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [tab, page, query]);

    useEffect(() => {
        void load();
    }, [load]);

    return (
        <div>
            <PageHeader
                title="LDDAP"
                subtitle="LDDAP-ADA records — each takes one number from the LDDAP check series, lowest unused first."
                action={
                    canManage ? (
                        <button className="btn btn-primary" onClick={() => setUsing(true)}>
                            <Hash className="h-4 w-4" />
                            Use Check Number
                        </button>
                    ) : undefined
                }
            />

            {canManage && (
                <div className="mb-6 flex flex-wrap gap-2">
                    <button
                        className="btn btn-outline"
                        onClick={() => setAssigning({ preselect: null })}
                    >
                        <ListChecks className="h-4 w-4" />
                        Assign LDDAP to ACIC
                    </button>
                </div>
            )}

            {/* Status tabs */}
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

            <div className="relative mb-4">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                <input
                    type="search"
                    className="field !pl-10"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search by LDDAP no., OBJ no., payee, check no. or ACIC no.…"
                    aria-label="Search LDDAP records"
                />
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
                        ? 'No LDDAP record matches that search.'
                        : tab === 'all'
                          ? 'No LDDAP records yet. Use a check number to register the first one.'
                          : 'No LDDAP records with that status.'}
                </EmptyState>
            ) : (
                data && (
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[62rem] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-4 py-3 font-semibold">Check No.</th>
                                        <th className="px-4 py-3 font-semibold">Status</th>
                                        <th className="px-4 py-3 font-semibold">LDDAP No.</th>
                                        <th className="px-4 py-3 text-right font-semibold">Amount</th>
                                        <th className="px-4 py-3 font-semibold">OBJ No.</th>
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
                                                #{l.check_no}
                                                {l.check_date && (
                                                    <div className="mt-0.5 text-xs font-normal text-subtle">
                                                        {formatDate(l.check_date)}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <LddapStatusBadge
                                                    status={l.status}
                                                    complied={l.has_pending_update}
                                                    viewer={user?.role}
                                                />
                                            </td>
                                            <td className="px-4 py-3 text-muted">
                                                <button
                                                    onClick={() => setViewing({ lddap: l, mode: 'view' })}
                                                    title="View full details"
                                                    className="text-fg underline-offset-4 hover:underline"
                                                >
                                                    {l.lddap_no}
                                                </button>
                                                {l.payee_name && (
                                                    <div className="mt-0.5 text-xs text-subtle">
                                                        {l.payee_name}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right font-mono text-muted">
                                                {formatMoney(l.amount)}
                                            </td>
                                            <td className="px-4 py-3 text-muted">{l.obj_no ?? '—'}</td>
                                            <td className="px-4 py-3 text-muted">
                                                {l.used_by?.name ?? '—'}
                                            </td>
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
                                                {l.status === 'approved' ? (
                                                    /* Approved — the next step is going on an ACIC. */
                                                    l.acic_number ? (
                                                        /* Forwarding is the ACIC's own step, taken
                                                           from the ACIC page's View dialog. */
                                                        <span className="text-xs text-subtle">
                                                            On ACIC #{l.acic_number}
                                                        </span>
                                                    ) : canManage ? (
                                                        <button
                                                            className="btn btn-outline !px-3 !py-1.5"
                                                            onClick={() => setAssigning({ preselect: l })}
                                                        >
                                                            <ListChecks className="h-3.5 w-3.5" />
                                                            Assign
                                                        </button>
                                                    ) : (
                                                        <span className="text-xs text-subtle">
                                                            Awaiting assignment
                                                        </span>
                                                    )
                                                ) : l.is_final ? (
                                                    <span className="text-xs text-subtle">
                                                        Reviewed {formatDate(l.reviewed_at)}
                                                    </span>
                                                ) : l.awaits_compliance ? (
                                                    /* Returned — the staff member's turn to fix the
                                                       details; the admin waits for that update. */
                                                    l.has_pending_update ? (
                                                        <span className="text-xs text-subtle">
                                                            Update awaiting admin approval
                                                        </span>
                                                    ) : isStaff && l.used_by?.id === user?.id ? (
                                                        /* Returned to this staff member — only they
                                                           can act on it. */
                                                        <button
                                                            className="btn btn-primary !px-3 !py-1.5"
                                                            onClick={() =>
                                                                setViewing({ lddap: l, mode: 'action' })
                                                            }
                                                        >
                                                            <PencilLine className="h-3.5 w-3.5" />
                                                            Action
                                                        </button>
                                                    ) : isAdmin ? (
                                                        <button
                                                            className="btn btn-outline !px-3 !py-1.5"
                                                            onClick={() => setReviewing(l)}
                                                        >
                                                            <Gavel className="h-3.5 w-3.5" />
                                                            Review
                                                        </button>
                                                    ) : (
                                                        <span className="text-xs text-subtle">
                                                            Returned to{' '}
                                                            {l.used_by?.name ??
                                                                'the staff member who used it'}
                                                        </span>
                                                    )
                                                ) : isAdmin ? (
                                                    /* Used (or received) — awaiting the admin's review. */
                                                    l.has_pending_update ? (
                                                        <span className="text-xs text-subtle">
                                                            On hold — resolve the update request first
                                                        </span>
                                                    ) : (
                                                        <button
                                                            className="btn btn-outline !px-3 !py-1.5"
                                                            onClick={() => setReviewing(l)}
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
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {data.meta.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-line px-4 py-3 text-sm text-muted">
                                <span>
                                    Page {data.meta.current_page} of {data.meta.last_page} · {data.meta.total}{' '}
                                    total
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

            {using && (
                <LddapUseModal
                    onClose={() => setUsing(false)}
                    onUsed={(count) => {
                        setUsing(false);
                        setNotice(
                            `Registered ${count} LDDAP record${count === 1 ? '' : 's'} against ${count === 1 ? 'a check number' : `${count} check numbers`}.`,
                        );
                        setPage(1);
                        void load();
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

            {reviewing && (
                <LddapReviewModal
                    lddap={reviewing}
                    onClose={() => setReviewing(null)}
                    onChanged={() => void load()}
                    onReviewed={(lddap) => {
                        setReviewing(null);
                        setNotice(`${lddap.lddap_no} reviewed.`);
                        void load();
                    }}
                />
            )}
        </div>
    );
}
