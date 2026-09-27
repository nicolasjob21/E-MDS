import { useCallback, useEffect, useState } from 'react';
import { Check, X, ArrowRight } from 'lucide-react';
import { LddapUpdateRequestApi, toApiError } from '../lib/api';
import type { LddapUpdateRequest, Paginated } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState } from '../components/ui';
import { formatDateTime, formatMoney } from '../lib/format';

type Mode = 'idle' | 'reject';

/** One "was → now" field comparison; highlights the proposed value when it differs. */
function Diff({ label, from, to }: { label: string; from: string; to: string }) {
    const changed = from !== to;
    return (
        <div className="bg-card p-3">
            <div className="text-xs uppercase tracking-wider text-subtle">{label}</div>
            <div className="mt-1 flex flex-wrap items-center gap-1.5 text-sm">
                <span className={changed ? 'text-muted line-through' : 'text-fg'}>{from || '—'}</span>
                {changed && (
                    <>
                        <ArrowRight className="h-3.5 w-3.5 text-subtle" />
                        <span className="font-semibold text-brandink">{to || '—'}</span>
                    </>
                )}
            </div>
        </div>
    );
}

function LddapRequestCard({
    request,
    onResolved,
}: {
    request: LddapUpdateRequest;
    onResolved: () => void;
}) {
    const lddap = request.lddap;
    const [mode, setMode] = useState<Mode>('idle');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function run(action: 'approve' | 'reject') {
        setBusy(true);
        setError('');
        try {
            if (action === 'approve') {
                await LddapUpdateRequestApi.approve(request.id, note.trim() || undefined);
            } else {
                await LddapUpdateRequestApi.reject(request.id, note.trim() || undefined);
            }
            onResolved();
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    return (
        <div className="card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <span className="eyebrow">LDDAP</span>
                    <div className="mt-1 font-display text-2xl font-extrabold tracking-tight text-brandink">
                        {lddap?.lddap_no ?? '—'}
                    </div>
                    <div className="mt-1 text-xs text-subtle">Check #{lddap?.check_no ?? '—'}</div>
                </div>
                <div className="text-right text-xs text-subtle">
                    <div>
                        Requested by{' '}
                        <span className="font-semibold text-fg">{request.requested_by?.name ?? '—'}</span>
                    </div>
                    <div>{formatDateTime(request.created_at)}</div>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
                <Diff label="LDDAP No." from={lddap?.lddap_no ?? ''} to={request.proposed_lddap_no} />
                <Diff label="OBJ No." from={lddap?.obj_no ?? ''} to={request.proposed_obj_no ?? ''} />
                <Diff label="Payee" from={lddap?.payee_name ?? ''} to={request.proposed_payee_name ?? ''} />
                <Diff
                    label="Amount"
                    from={formatMoney(lddap?.amount)}
                    to={formatMoney(request.proposed_amount)}
                />
            </div>

            <div className="mt-4 rounded-xs border border-line bg-well p-3">
                <div className="text-xs font-semibold uppercase tracking-wider text-subtle">Reason given</div>
                <p className="mt-1 text-sm text-fg">{request.reason}</p>
            </div>

            {error && (
                <div className="mt-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}

            {mode === 'reject' ? (
                <div className="mt-4 space-y-3">
                    <div>
                        <label className="label" htmlFor={`lrnote-${request.id}`}>
                            Reason for rejecting (optional)
                        </label>
                        <input
                            id={`lrnote-${request.id}`}
                            className="field"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="Explain why this request is declined"
                            autoFocus
                        />
                    </div>
                    <div className="flex gap-2">
                        <button className="btn btn-primary" onClick={() => run('reject')} disabled={busy}>
                            <X className="h-4 w-4" />
                            {busy ? 'Rejecting…' : 'Confirm reject'}
                        </button>
                        <button className="btn btn-ghost" onClick={() => setMode('idle')} disabled={busy}>
                            Cancel
                        </button>
                    </div>
                </div>
            ) : (
                <div className="mt-4 flex gap-2">
                    <button className="btn btn-primary" onClick={() => run('approve')} disabled={busy}>
                        <Check className="h-4 w-4" />
                        {busy ? 'Approving…' : 'Approve & apply'}
                    </button>
                    <button className="btn btn-outline" onClick={() => setMode('reject')} disabled={busy}>
                        <X className="h-4 w-4" />
                        Reject
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * The admin queue of staff-proposed LDDAP corrections. (Cheques take no correction requests:
 * their details are edited directly while they have no status or are For Compliance.)
 */
export default function UpdateRequestsPage() {
    const [lddaps, setLddaps] = useState<Paginated<LddapUpdateRequest> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            setLddaps(await LddapUpdateRequestApi.list('pending'));
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    return (
        <div>
            <PageHeader
                title="Update Requests"
                subtitle="Staff-proposed corrections to LDDAP details. Review the change and approve or reject."
            />

            {error && (
                <div className="mb-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}

            {loading && lddaps === null ? (
                <Spinner />
            ) : lddaps !== null && lddaps.data.length === 0 ? (
                <EmptyState>No pending LDDAP update requests. You’re all caught up.</EmptyState>
            ) : (
                <div className="space-y-4">
                    {lddaps?.data.map((request) => (
                        <LddapRequestCard key={request.id} request={request} onResolved={load} />
                    ))}
                </div>
            )}
        </div>
    );
}
