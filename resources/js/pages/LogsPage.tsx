import { useCallback, useEffect, useState } from 'react';
import { LogApi, UserApi, toApiError } from '../lib/api';
import type { ChequeLog, Paginated, User } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState } from '../components/ui';
import { actionLabel, formatDateTime } from '../lib/format';

const ACTIONS = [
    'login',
    'logout',
    'used_cheque',
    'cashed_cheque',
    'added_cheque_range',
    'created_user',
    'updated_user',
    'deleted_user',
];

interface Filters {
    user_id: string;
    action: string;
    from: string;
    to: string;
    search: string;
}

const EMPTY: Filters = { user_id: '', action: '', from: '', to: '', search: '' };

export default function LogsPage() {
    const [filters, setFilters] = useState<Filters>(EMPTY);
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Paginated<ChequeLog> | null>(null);
    const [users, setUsers] = useState<User[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        void UserApi.list().then(setUsers).catch(() => undefined);
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const params: Record<string, string | number> = { page };
            Object.entries(filters).forEach(([k, v]) => {
                if (v) params[k] = v;
            });
            setData(await LogApi.list(params));
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [filters, page]);

    useEffect(() => {
        void load();
    }, [load]);

    function update<K extends keyof Filters>(key: K, value: string) {
        setPage(1);
        setFilters((f) => ({ ...f, [key]: value }));
    }

    return (
        <div>
            <PageHeader title="Audit log" subtitle="Every login, cheque use and admin action, recorded." />

            {/* Filters */}
            <div className="card mb-6 grid gap-4 p-4 md:grid-cols-5">
                <div>
                    <label className="label">User</label>
                    <select className="field" value={filters.user_id} onChange={(e) => update('user_id', e.target.value)}>
                        <option value="">All users</option>
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.username}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="label">Action</label>
                    <select className="field" value={filters.action} onChange={(e) => update('action', e.target.value)}>
                        <option value="">All actions</option>
                        {ACTIONS.map((a) => (
                            <option key={a} value={a}>
                                {actionLabel(a)}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="label">From</label>
                    <input type="date" className="field" value={filters.from} onChange={(e) => update('from', e.target.value)} />
                </div>
                <div>
                    <label className="label">To</label>
                    <input type="date" className="field" value={filters.to} onChange={(e) => update('to', e.target.value)} />
                </div>
                <div className="flex items-end">
                    <button className="btn btn-ghost w-full" onClick={() => { setFilters(EMPTY); setPage(1); }}>
                        Clear filters
                    </button>
                </div>
            </div>

            {error && (
                <div className="mb-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}

            {loading && !data ? (
                <Spinner />
            ) : data && data.data.length === 0 ? (
                <EmptyState>No log entries match these filters.</EmptyState>
            ) : (
                data && (
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-4 py-3 font-semibold">When</th>
                                        <th className="px-4 py-3 font-semibold">User</th>
                                        <th className="px-4 py-3 font-semibold">Action</th>
                                        <th className="px-4 py-3 font-semibold">Cheque</th>
                                        <th className="px-4 py-3 font-semibold">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.data.map((log) => (
                                        <tr key={log.id} className="border-b border-line/60 last:border-0 align-top">
                                            <td className="whitespace-nowrap px-4 py-3 text-muted">
                                                {formatDateTime(log.created_at)}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-muted">{log.username}</td>
                                            <td className="px-4 py-3">
                                                <span className="rounded-xs border border-line bg-well px-2 py-0.5 text-xs text-brandink">
                                                    {actionLabel(log.action)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-muted">
                                                {log.cheque_number ? `#${log.cheque_number}` : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-muted">{log.description ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {data.meta.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-line px-4 py-3 text-sm text-muted">
                                <span>
                                    Page {data.meta.current_page} of {data.meta.last_page} · {data.meta.total} entries
                                </span>
                                <div className="flex gap-2">
                                    <button className="btn btn-ghost" disabled={data.meta.current_page <= 1} onClick={() => setPage((p) => p - 1)}>
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
        </div>
    );
}
