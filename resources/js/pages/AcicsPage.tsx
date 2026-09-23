import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { FilePlus2, ListChecks, CheckCircle2, Eye } from 'lucide-react';
import { AcicApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Acic, Paginated } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState, AcicStatusBadge } from '../components/ui';
import AcicUseModal from '../components/AcicUseModal';
import LddapAssignModal from '../components/LddapAssignModal';
import AcicForwardModal from '../components/AcicForwardModal';
import AcicDetailModal from '../components/AcicDetailModal';
import { formatDate, formatDateTime } from '../lib/format';

/**
 * The tab row filters on two separate dimensions at once: what the ACIC carries, and where it is
 * in its lifecycle. They combine, so `cheques` + `forwarded` is a valid view — but only one tab
 * is lit at a time, so picking a category clears the status and vice versa.
 */
type Tab = 'all' | 'cheques' | 'lddaps' | 'forwarded' | 'completed';

const CATEGORIES: Tab[] = ['cheques', 'lddaps'];

const TABS: { key: Tab; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'cheques', label: 'Cheque ACIC' },
    { key: 'lddaps', label: 'LDDAP ACIC' },
    { key: 'forwarded', label: 'Forwarded' },
    { key: 'completed', label: 'Completed' },
];

function tabFrom(value: string | null): Tab {
    return TABS.some((t) => t.key === value) ? (value as Tab) : 'all';
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
    const isAdmin = user?.role === 'admin';
    const isTeller = user?.role === 'teller';
    const canManage = isAdmin || user?.role === 'staff';

    // `?tab=` picks the tab, so the dashboard's tiles land on the right list.
    const [params] = useSearchParams();
    const [tab, setTab] = useState<Tab>(() => tabFrom(params.get('tab')));
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Paginated<Acic> | null>(null);
    const [nextNumber, setNextNumber] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    // The next-in-sequence assign dialog. The ACIC itself is opened when it is submitted.
    const [assigningNext, setAssigningNext] = useState(false);
    const [assigningLddaps, setAssigningLddaps] = useState(false);
    const [error, setError] = useState('');
    const [forwarding, setForwarding] = useState<Acic | null>(null);
    const [viewing, setViewing] = useState<{ id: number; mode: 'view' | 'confirm' } | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const isCategory = CATEGORIES.includes(tab);
            const [list, next] = await Promise.all([
                AcicApi.list(
                    isCategory ? 'all' : tab,
                    page,
                    50,
                    isCategory ? (tab as 'cheques' | 'lddaps') : 'all',
                ),
                AcicApi.next(),
            ]);
            setData(list);
            setNextNumber(next);
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [tab, page]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        setTab(tabFrom(params.get('tab')));
        setPage(1);
    }, [params]);

    return (
        <div>
            <PageHeader
                title="ACIC"
                subtitle="Advice of Checks Issued & Cancelled — one running sequence, no skipped numbers."
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
                                    Put one approved cheque on ACIC #{nextNumber ?? '—'}. Only approved
                                    cheques not already on an ACIC are listed.
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
                                    Put approved LDDAP records on ACIC #{nextNumber ?? '—'}. Many may
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

            {error && (
                <div className="mb-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}

            {loading && !data ? (
                <Spinner />
            ) : data && data.data.length === 0 ? (
                <EmptyState>
                    {tab === 'forwarded'
                        ? 'No ACIC records are awaiting teller action.'
                        : tab === 'completed'
                          ? 'No ACIC records have been completed yet.'
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
                                                    onClick={() => setViewing({ id: acic.id, mode: 'view' })}
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
                                                <AcicStatusBadge status={acic.status} />
                                            </td>
                                            <td className="px-4 py-3 text-muted">{acic.used_by?.name ?? '—'}</td>
                                            <td className="px-4 py-3 whitespace-nowrap text-muted">
                                                {formatDate(acic.created_at)}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-muted">
                                                {acic.forwarded_at ? formatDateTime(acic.forwarded_at) : '—'}
                                            </td>
                                            {/* When the teller handed the ACIC over the counter at
                                                Land Bank, with the credit beneath once it lands. */}
                                            <td className="px-4 py-3 whitespace-nowrap text-muted">
                                                {acic.forwarded_to_land_bank_at ? (
                                                    <>
                                                        {formatDateTime(acic.forwarded_to_land_bank_at)}
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
                                                {acic.received_by?.name ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {/* Approve, Re-assign, Forward and Print all live
                                                    in the View dialog; the row only opens it. */}
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        className="btn btn-ghost !px-3 !py-1.5"
                                                        onClick={() => setViewing({ id: acic.id, mode: 'view' })}
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                        View
                                                    </button>

                                                    {acic.status === 'forwarded' && isTeller && (
                                                        <button
                                                            className="btn btn-primary !px-3 !py-1.5"
                                                            onClick={() =>
                                                                setViewing({ id: acic.id, mode: 'confirm' })
                                                            }
                                                        >
                                                            <CheckCircle2 className="h-3.5 w-3.5" />
                                                            Complete
                                                        </button>
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

            {viewing && (
                <AcicDetailModal
                    acicId={viewing.id}
                    mode={viewing.mode}
                    onClose={() => setViewing(null)}
                    onCompleted={() => {
                        setViewing(null);
                        void load();
                    }}
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
                <AcicUseModal
                    nextNumber={nextNumber}
                    single
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
