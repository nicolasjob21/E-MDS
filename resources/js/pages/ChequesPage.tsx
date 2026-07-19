import { useCallback, useEffect, useState } from 'react';
import { Lock, BadgeCheck, Clock } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque, Paginated, Summary } from '../lib/types';
import { PageHeader, Spinner, Alert, StatusBadge, EmptyState } from '../components/ui';
import NextChequePanel from '../components/NextChequePanel';
import ChequeDetailModal from '../components/ChequeDetailModal';
import { formatDate, formatMoney } from '../lib/format';

type Tab = 'all' | 'available' | 'used' | 'received';

const TABS: { key: Tab; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'available', label: 'Available' },
    { key: 'used', label: 'Used' },
    { key: 'received', label: 'Received' },
];

export default function ChequesPage() {
    const [tab, setTab] = useState<Tab>('all');
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Paginated<Cheque> | null>(null);
    const [selected, setSelected] = useState<Cheque | null>(null);
    const [summary, setSummary] = useState<Summary | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const nextNumber = summary?.next?.cheque_number ?? null;

    const loadList = useCallback(async () => {
        setLoading(true);
        try {
            setData(await ChequeApi.list(tab, page));
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [tab, page]);

    const loadSummary = useCallback(async () => {
        try {
            setSummary(await ChequeApi.summary());
        } catch {
            /* non-fatal */
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
            <div className="mb-4 flex gap-1 border-b border-line">
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

            {error && (
                <div className="mb-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}

            {loading && !data ? (
                <Spinner />
            ) : data && data.data.length === 0 ? (
                <EmptyState>No cheques to show in this view.</EmptyState>
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
                                                        onClick={() => setSelected(cheque)}
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
                                                    <StatusBadge status={cheque.status} />
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
                                                <td className="px-4 py-3">
                                                    {cheque.status === 'received' ? (
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
                                                    {cheque.status === 'available' &&
                                                        (isNext ? (
                                                            <span className="text-xs text-brandink">
                                                                Use from the panel above ↑
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 text-xs text-subtle">
                                                                <Lock className="h-3 w-3" />
                                                                Locked
                                                            </span>
                                                        ))}
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

            {selected && (
                <ChequeDetailModal
                    cheque={selected}
                    onClose={() => setSelected(null)}
                    onChanged={(updated) => {
                        setSelected(updated);
                        refreshAll();
                    }}
                />
            )}
        </div>
    );
}
