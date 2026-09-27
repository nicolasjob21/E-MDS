import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { X, CheckCircle2, Landmark, Undo2, Route, Printer } from 'lucide-react';
import { ChequeApi } from '../lib/api';
import type { Cheque, ChequeStatusStep } from '../lib/types';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';
import { StatusBadge } from './ui';
import ChequeViewModal from './ChequeViewModal';

/** Each timeline step, named for what happened. Anything not listed reads as its status change. */
const STEP_LABELS: Record<string, string> = {
    used: 'Cheque used',
    draft_printed: 'Draft printed — sent for checking',
    draft_approved: 'Draft approved',
    draft_returned: 'Draft returned for compliance',
    final_printed: 'Final print confirmed',
    edited: 'Details edited',
    assigned: 'Assigned to ACIC',
    unassigned: 'Taken off the ACIC',
    released: 'Released to payee',
    forwarded_to_teller: 'Forwarded to teller',
    accepted_by_teller: 'Accepted by teller',
    completed: 'Completed',
    returned_to_admin: 'Returned to admin',
    cancelled: 'Cancelled',
    spoiled: 'Spoiled',
    staled: 'Went stale',
    replaced: 'Replaced',
};

const FIELD_LABELS: Record<string, string> = {
    payee_name: 'Payee',
    account_no: 'Account No.',
    unit_name: 'Unit',
    amount: 'Amount',
    cheque_date: 'Cheque date',
};

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 border-b border-line/60 py-2.5 last:border-0">
            <span className="text-xs font-semibold uppercase tracking-wider text-subtle">{label}</span>
            <span className="text-right text-sm text-fg">{value}</span>
        </div>
    );
}

interface Props {
    cheque: Cheque;
    /** Kept for the table's call sites; the dialog shows the same thing either way. */
    mode?: 'view' | 'action';
    onClose: () => void;
    /** Kept for the table's call sites; nothing in the dialog changes the cheque now. */
    onChanged?: (cheque: Cheque) => void;
}

/**
 * A cheque's details and its timeline — every step in order, with who took it, when, and any
 * comment (a returned draft says what to change; an edit, what changed).
 */
export default function ChequeDetailModal({ cheque, onClose }: Props) {
    const current = cheque;

    // The cheque's own status history, straight from the server: every step it took, who
    // took it and when. Nothing here is inferred.
    const [timeline, setTimeline] = useState<ChequeStatusStep[]>([]);
    // The cheque face, for a cheque that is on an ACIC.
    const [printing, setPrinting] = useState(false);

    const loadHistory = useCallback(async () => {
        try {
            setTimeline(await ChequeApi.statusHistory(cheque.id));
        } catch {
            /* non-fatal — the timeline just won't show */
        }
    }, [cheque.id]);

    useEffect(() => {
        void loadHistory();
    }, [loadHistory]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const isIssued = current.status !== 'available';
    const isReceived = !!current.received_at;
    // For Compliance: what the admin in charge asked to change, from the latest return.
    const lastReturn = [...timeline].reverse().find((step) => step.action === 'draft_returned');

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
        >
            <div className="card max-h-[90vh] w-full max-w-md overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Cheque</span>
                        <div className="mt-2 font-display text-4xl font-extrabold tracking-tight text-brandink">
                            #{current.cheque_number}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <StatusBadge status={current.effective_status ?? current.status} />
                        {current.can_print && (
                            <button
                                className="btn btn-ghost !px-2.5 !py-1"
                                onClick={() => setPrinting(true)}
                                title={`Print cheque #${current.cheque_number}`}
                            >
                                <Printer className="h-3.5 w-3.5" />
                                Print
                            </button>
                        )}
                        <button className="btn btn-ghost !px-2" onClick={onClose} aria-label="Close">
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                </div>

                {current.effective_status === 'for_compliance' && lastReturn && (
                    <section className="mb-5 rounded-xs border border-amber-400/50 bg-amber-400/10 p-4">
                        <h3 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-amber-400">
                            <Undo2 className="h-4 w-4" />
                            For Compliance — what to change
                        </h3>
                        <p className="mt-2 text-sm text-fg">{lastReturn.note ?? 'No comment was recorded.'}</p>
                        <p className="mt-2 text-xs text-subtle">
                            {lastReturn.user?.name ?? 'The admin in charge'} · {formatDateTime(lastReturn.created_at)} — edit
                            the details, then print a new draft.
                        </p>
                    </section>
                )}

                {!isIssued ? (
                    <div className="rounded-xs border border-line bg-well px-4 py-6 text-center text-sm text-muted">
                        This cheque is still <span className="font-semibold text-brandink">available</span> and has not
                        been used yet.
                    </div>
                ) : (
                    <div className="space-y-5">
                        {/* Cheque details */}
                        <section>
                            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wider text-subtle">
                                Cheque details
                            </h3>
                            <Row label="Payee / name" value={current.payee_name ?? '—'} />
                            <Row label="Account No." value={current.account_no ?? '—'} />
                            <Row label="Unit" value={current.unit_name ?? '—'} />
                            <Row label="Amount" value={formatMoney(current.amount)} />
                            <Row label="Cheque date" value={formatDate(current.cheque_date)} />
                            <Row
                                label="ACIC no."
                                value={current.acic_number ? `#${current.acic_number}` : 'Not on an ACIC'}
                            />
                            <Row label="Used by" value={current.used_by?.name ?? current.used_by_name ?? '—'} />
                            <Row label="Used at" value={formatDateTime(current.used_at)} />
                            {current.replaces && (
                                <Row label="Replaces" value={`Cheque #${current.replaces.cheque_number}`} />
                            )}
                            {current.replaced_by && (
                                <Row label="Replaced by" value={`Cheque #${current.replaced_by.cheque_number}`} />
                            )}
                            {current.spoiled_from_acic && (
                                <Row label="Was on ACIC" value={`#${current.spoiled_from_acic.acic_number} (taken off when spoiled)`} />
                            )}
                            {current.previous_acic && !current.acic_number && (
                                <Row
                                    label="Previous ACIC"
                                    value={
                                        current.previous_acic.allowed
                                            ? `#${current.previous_acic.acic_number} — can be used again`
                                            : `#${current.previous_acic.acic_number} — ${current.previous_acic.reason}`
                                    }
                                />
                            )}
                        </section>

                        {/* Who received it at the payee's end — Forward to Payee (teller) or Release to Payee (admin). */}
                        {current.payee_receipt && (
                            <section>
                                <h3 className="mb-1 text-xs font-semibold uppercase tracking-wider text-subtle">
                                    {current.status === 'released_to_payee' ? 'Released to payee' : 'Forwarded to payee'}
                                </h3>
                                <Row label="Received By" value={current.payee_receipt.received_by ?? '—'} />
                                <Row label="Date Received" value={formatDate(current.payee_receipt.date_received)} />
                                <Row label="Unit" value={current.payee_receipt.unit ?? '—'} />
                                <Row
                                    label="Recorded by"
                                    value={
                                        current.payee_receipt.recorded_by
                                            ? `${current.payee_receipt.recorded_by.name} · ${formatDateTime(current.payee_receipt.recorded_at)}`
                                            : formatDateTime(current.payee_receipt.recorded_at)
                                    }
                                />
                            </section>
                        )}

                        {current.spoil && (
                            <section className="rounded-xs border border-danger/40 bg-danger/10 p-4">
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-danger-fg">Spoiled</h3>
                                <Row label="Marked by" value={current.spoil.spoiled_by?.name ?? '—'} />
                                <Row label="Date" value={formatDateTime(current.spoil.spoiled_at)} />
                                <Row label="Reason" value={current.spoil.reason ?? '—'} />
                            </section>
                        )}

                        {/* Receipt confirmation — the teller lifecycle step */}
                        <section className="rounded-xs border border-line bg-well p-4">
                            <h3 className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brandink">
                                <Landmark className="h-4 w-4" />
                                Receipt confirmation
                            </h3>

                            {isReceived ? (
                                <>
                                    <Row
                                        label="Received by teller"
                                        value={current.received_by?.name ?? current.received_by_name ?? '—'}
                                    />
                                    <Row label="Confirmed at" value={formatDateTime(current.received_at)} />
                                    <p className="mt-3 flex items-center gap-1.5 text-xs text-success-fg">
                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                        This cheque has been confirmed as received.
                                    </p>
                                </>
                            ) : (
                                <p className="text-sm text-muted">
                                    Not yet confirmed as received — tellers confirm receipt from the Deposit Queue.
                                </p>
                            )}
                        </section>

                        {/* Where the cheque has been: assigned, then released, or forwarded and
                            deposited — with whatever happened to it along the way. */}
                        {timeline.length > 0 && (
                            <section>
                                <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-subtle">
                                    <Route className="h-4 w-4" />
                                    Timeline
                                </h3>
                                <ol className="space-y-0">
                                    {timeline.map((entry, i) => (
                                        <li key={entry.id} className="flex gap-3">
                                            <span className="flex flex-col items-center">
                                                <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-400" />
                                                {i < timeline.length - 1 && <span className="w-px flex-1 bg-line" />}
                                            </span>
                                            <span className="min-w-0 flex-1 pb-4">
                                                <span className="block text-sm font-medium text-fg">
                                                    {STEP_LABELS[entry.action] ??
                                                        (entry.from_status_label
                                                            ? `${entry.from_status_label} → ${entry.to_status_label}`
                                                            : entry.to_status_label)}
                                                </span>
                                                {entry.action !== 'edited' && entry.to_status_label && entry.from_status_label !== entry.to_status_label && (
                                                    <span className="mt-0.5 block text-xs text-subtle">Status: {entry.to_status_label}</span>
                                                )}
                                                {entry.note && <span className="mt-0.5 block text-xs text-muted">“{entry.note}”</span>}
                                                {entry.action === 'edited' && entry.details && (
                                                    <span className="mt-0.5 block text-xs text-muted">
                                                        {Object.entries(entry.details as Record<string, { from: unknown; to: unknown }>)
                                                            .map(([field, c]) => `${FIELD_LABELS[field] ?? field}: ${String(c.from ?? '—')} → ${String(c.to ?? '—')}`)
                                                            .join(' · ')}
                                                    </span>
                                                )}
                                                <span className="mt-0.5 block text-xs text-subtle">
                                                    {entry.user?.name ? `${entry.user.name} · ` : ''}
                                                    {formatDateTime(entry.created_at)}
                                                    {entry.acic_number ? ` · ACIC #${entry.acic_number}` : ''}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            </section>
                        )}

                    </div>
                )}
            </div>

            {/* The cheque face, at its real size. Its own overlay sits above this one. */}
            {printing && (
                <div onClick={(e) => e.stopPropagation()}>
                    <ChequeViewModal cheque={current} onClose={() => setPrinting(false)} />
                </div>
            )}
        </div>
    );
}
