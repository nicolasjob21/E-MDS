import { useCallback, useEffect, useState } from 'react';
import { Check, X, ArrowRight } from 'lucide-react';
import { LddapUpdateRequestApi, UpdateRequestApi, toApiError } from '../lib/api';
import type { LddapUpdateRequest, Paginated, UpdateRequest } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState } from '../components/ui';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';

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

function RequestCard({ request, onResolved }: { request: UpdateRequest; onResolved: () => void }) {
    const cheque = request.cheque;
    const [mode, setMode] = useState<Mode>('idle');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function run(action: 'approve' | 'reject') {
        setBusy(true);
        setError('');
        try {
            if (action === 'approve') {
                await UpdateRequestApi.approve(request.id, note.trim() || undefined);
            } else {
                await UpdateRequestApi.reject(request.id, note.trim() || undefined);
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
                    <span className="eyebrow">Cheque</span>
                    <div className="mt-1 font-display text-2xl font-extrabold tracking-tight text-brandink">
                        #{cheque?.cheque_number ?? '—'}
                    </div>
                </div>
                <div className="text-right text-xs text-subtle">
                    <div>
                        Requested by{' '}
                        <span className="font-semibold text-fg">{request.requested_by?.name ?? '—'}</span>
                    </div>
                    <div>{formatDateTime(request.created_at)}</div>
                </div>
            </div>

            {/* Current → proposed */}
            <div className="mt-4 grid grid-cols-1 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-3">
                <Diff
                    label="Payee"
                    from={cheque?.payee_name ?? ''}
                    to={request.proposed_payee_name ?? ''}
                />
                <Diff
                    label="Amount"
                    from={formatMoney(cheque?.amount)}
                    to={formatMoney(request.proposed_amount)}
                />
                <Diff
                    label="Cheque date"
                    from={formatDate(cheque?.cheque_date)}
                    to={formatDate(request.proposed_cheque_date)}
                />
            </div>

            {/* Reason */}
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
                        <label className="label" htmlFor={`rnote-${request.id}`}>
                            Reason for rejecting (optional)
                        </label>
                        <input
                            id={`rnote-${request.id}`}
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

/** The LDDAP equivalent of RequestCard — same layout, LDDAP's own correctable fields. */
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

type Register = 'cheques' | 'lddaps';

export default function UpdateRequestsPage() {
    const [register, setRegister] = useState<Register>('cheques');
    const [cheques, setCheques] = useState<Paginated<UpdateRequest> | null>(null);
    const [lddaps, setLddaps] = useState<Paginated<LddapUpdateRequest> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    // Both queues are fetched together so each tab can show its pending count up front.
    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [c, l] = await Promise.all([
                UpdateRequestApi.list('pending'),
                LddapUpdateRequestApi.list('pending'),
            ]);
            setCheques(c);
            setLddaps(l);
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

    const tabs: { key: Register; label: string; count: number }[] = [
        { key: 'cheques', label: 'Cheques', count: cheques?.meta.total ?? 0 },
        { key: 'lddaps', label: 'LDDAP', count: lddaps?.meta.total ?? 0 },
    ];

    const current = register === 'cheques' ? cheques : lddaps;
    const isEmpty = current !== null && current.data.length === 0;

    return (
        <div>
            <PageHeader
                title="Update Requests"
                subtitle="Staff-proposed corrections to cheque and LDDAP details. Review the change and approve or reject."
            />

            <div className="mb-4 flex flex-wrap gap-1 border-b border-line">
                {tabs.map(({ key, label, count }) => (
                    <button
                        key={key}
                        onClick={() => setRegister(key)}
                        className={`-mb-px border-b-2 px-4 py-2.5 font-display text-xs font-semibold uppercase tracking-widest transition-colors ${
                            register === key
                                ? 'border-accent-400 text-fg'
                                : 'border-transparent text-subtle hover:text-muted'
                        }`}
                    >
                        {label}
                        {count > 0 && (
                            <span className="ml-2 rounded-xs bg-accent-400/15 px-1.5 py-0.5 text-accent-400">
                                {count}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {error && (
                <div className="mb-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}

            {loading && current === null ? (
                <Spinner />
            ) : isEmpty ? (
                <EmptyState>
                    {register === 'cheques'
                        ? 'No pending cheque update requests. You’re all caught up.'
                        : 'No pending LDDAP update requests. You’re all caught up.'}
                </EmptyState>
            ) : (
                <div className="space-y-4">
                    {register === 'cheques'
                        ? cheques?.data.map((request) => (
                              <RequestCard key={request.id} request={request} onResolved={load} />
                          ))
                        : lddaps?.data.map((request) => (
                              <LddapRequestCard key={request.id} request={request} onResolved={load} />
                          ))}
                </div>
            )}
        </div>
    );
}
