import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import {
    X,
    CheckCircle2,
    Clock,
    FileText,
    Landmark,
    Repeat,
    Send,
    Printer,
    ShieldCheck,
    Plus,
} from 'lucide-react';
import { AcicApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Acic } from '../lib/types';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';
import { Alert, Spinner, StatusBadge, LddapStatusBadge, AcicStatusBadge } from './ui';
import AcicReassignModal from './AcicReassignModal';
import AcicUseModal from './AcicUseModal';
import LddapAssignModal from './LddapAssignModal';

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 border-b border-line/60 py-2.5 last:border-0">
            <span className="text-xs font-semibold uppercase tracking-wider text-subtle">{label}</span>
            <span className="text-right text-sm text-fg">{value}</span>
        </div>
    );
}

/** The form prints dates as m/d/Y, not the UI's long form. */
function printDate(value?: string | null): string {
    if (!value) return '';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? ''
        : `${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}/${d.getFullYear()}`;
}

/** Grouped to two decimals, with no currency symbol — as on the sheet. */
function printMoney(value?: string | null): string {
    return Number(value ?? 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

interface Props {
    acicId: number;
    /** `confirm` adds the teller's finalise step on top of the same details. */
    mode: 'view' | 'confirm';
    onClose: () => void;
    onCompleted?: (acic: Acic) => void;
    /** The ACIC changed in here (approved, re-assigned); the list behind needs a refresh. */
    onChanged?: () => void;
    /** Hand the approved ACIC to the page's forward dialog. */
    onForward?: (acic: Acic) => void;
}

/**
 * The full record for one ACIC: its own fields plus every cheque on it.
 *
 * In `confirm` mode this doubles as the teller's completion prompt — the teller sees exactly
 * which cheques they are finalising before committing, rather than confirming a bare number.
 *
 * Laid out to match ChequeDetailModal: same width, same header, same Row list, same
 * well-boxed sections and stacked sub-cards.
 */
export default function AcicDetailModal({
    acicId,
    mode,
    onClose,
    onCompleted,
    onChanged,
    onForward,
}: Props) {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin';
    const canManage = isAdmin || user?.role === 'staff';

    const [acic, setAcic] = useState<Acic | null>(null);
    const [loadError, setLoadError] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [reassigning, setReassigning] = useState(false);
    // Adding more records to this ACIC while it still accepts them.
    const [addingCheques, setAddingCheques] = useState(false);
    const [addingLddaps, setAddingLddaps] = useState(false);

    const load = useCallback(async () => {
        try {
            setAcic(await AcicApi.show(acicId));
        } catch (err) {
            setLoadError(toApiError(err).message);
        }
    }, [acicId]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    async function handleComplete() {
        if (!acic) return;
        setBusy(true);
        setError('');
        try {
            const updated = await AcicApi.complete(acic.id);
            onCompleted?.(updated);
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    async function handleApprove() {
        if (!acic) return;
        setBusy(true);
        setError('');
        try {
            setAcic(await AcicApi.approve(acic.id));
            onChanged?.();
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setBusy(false);
        }
    }

    const cheques = acic?.cheques ?? [];
    const lddaps = acic?.lddaps ?? [];
    const total =
        cheques.reduce((sum, c) => sum + Number(c.amount ?? 0), 0) +
        lddaps.reduce((sum, l) => sum + Number(l.amount ?? 0), 0);
    const isConfirm = mode === 'confirm';
    const isUsed = acic?.status === 'used';
    const isApproved = acic?.status === 'approved';
    /**
     * Used says nothing about capacity — it only means the ACIC already carries something. More
     * records may go on until it is signed off, so Open and Used both keep the Add actions.
     */
    const acceptsRecords = acic?.status === 'open' || isUsed;

    /**
     * The ACIC's category, derived from what it carries exactly as the ACIC table derives it,
     * and what each category may still take on:
     *
     *  - **Cheque ACIC** (cheques, no LDDAPs) — closed to both; what is on it is what it is for.
     *  - **LDDAP ACIC** (LDDAPs, no cheques) — keeps taking LDDAPs, never cheques.
     *  - **Mixed** and one still **empty** — both Add actions stay.
     *
     * Re-assign and Approve are unaffected by any of this.
     */
    const isChequeAcic = cheques.length > 0 && lddaps.length === 0;
    const isLddapAcic = lddaps.length > 0 && cheques.length === 0;

    const canAddCheques = acceptsRecords && !isChequeAcic && !isLddapAcic;
    const canAddLddaps = acceptsRecords && !isChequeAcic;

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="acic-detail-title"
        >
            <div className="card max-h-[90vh] w-full max-w-lg overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">{isConfirm ? 'Confirm completion' : 'ACIC'}</span>
                        <div
                            id="acic-detail-title"
                            className="mt-2 font-display text-4xl font-extrabold tracking-tight text-brandink"
                        >
                            #{acic?.acic_number ?? '—'}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {acic && <AcicStatusBadge status={acic.status} />}
                        <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                </div>

                {loadError ? (
                    <Alert kind="error">{loadError}</Alert>
                ) : acic === null ? (
                    <Spinner />
                ) : (
                    <div className="space-y-5">
                        {isConfirm && (
                            <p className="flex items-start gap-2 rounded-xs border border-accent-400/40 bg-accent-400/10 p-3 text-sm text-accent-400">
                                <Clock className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Confirm you have finished this transaction. Completing records receipt
                                    against the {cheques.length} cheque{cheques.length === 1 ? '' : 's'} below
                                    and cannot be undone.
                                </span>
                            </p>
                        )}

                        {/* ACIC record */}
                        <section>
                            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wider text-subtle">
                                ACIC details
                            </h3>
                            <Row label="Created date" value={formatDateTime(acic.created_at)} />
                            <Row label="Created by" value={acic.created_by?.name ?? '—'} />
                            <Row label="Used by" value={acic.used_by?.name ?? '—'} />
                            <Row label="Used at" value={acic.used_at ? formatDateTime(acic.used_at) : '—'} />
                        </section>

                        {/* Forwarding — the admin lifecycle step */}
                        <section className="rounded-xs border border-line bg-well p-4">
                            <h3 className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brandink">
                                <Landmark className="h-4 w-4" />
                                Forwarding
                            </h3>
                            {acic.forwarded_at ? (
                                <>
                                    <Row label="Forward date" value={formatDateTime(acic.forwarded_at)} />
                                    <Row label="Received by" value={acic.received_by?.name ?? '—'} />
                                    {acic.completed_at ? (
                                        <>
                                            <Row label="Completed at" value={formatDateTime(acic.completed_at)} />
                                            <Row label="Completed by" value={acic.completed_by?.name ?? '—'} />
                                            <p className="mt-3 flex items-center gap-1.5 text-xs text-success-fg">
                                                <CheckCircle2 className="h-3.5 w-3.5" />
                                                This ACIC has been completed.
                                            </p>
                                        </>
                                    ) : (
                                        <p className="mt-3 flex items-center gap-1.5 text-xs text-accent-400">
                                            <Clock className="h-3.5 w-3.5" />
                                            Awaiting the teller to complete the transaction.
                                        </p>
                                    )}
                                </>
                            ) : (
                                <p className="text-sm text-muted">
                                    Not yet forwarded. An admin forwards this ACIC once its cheques are assigned.
                                </p>
                            )}
                        </section>

                        {/* Everything on this ACIC, in ONE table — the same shape as the
                            printed sheet. Cheques and LDDAPs share the ACIC number, so they
                            share its table rather than being split into a list apiece. */}
                        <section>
                            <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-subtle">
                                <FileText className="h-4 w-4" />
                                Records on this ACIC ({cheques.length + lddaps.length})
                            </h3>

                            {cheques.length + lddaps.length === 0 ? (
                                <div className="rounded-xs border border-line bg-well px-4 py-6 text-center text-sm text-muted">
                                    Nothing has been assigned to this ACIC yet.
                                </div>
                            ) : (
                                <div className="overflow-x-auto rounded-xs border border-line">
                                    <table className="w-full min-w-[30rem] text-left text-sm">
                                        <thead>
                                            <tr className="border-b border-line bg-well text-xs uppercase tracking-wider text-subtle">
                                                <th className="px-3 py-2 font-semibold">Check No.</th>
                                                <th className="px-3 py-2 font-semibold">Date of issue</th>
                                                <th className="px-3 py-2 font-semibold">Payee</th>
                                                <th className="px-3 py-2 text-right font-semibold">Amount</th>
                                                <th className="px-3 py-2 font-semibold">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {cheques.map((c) => (
                                                <tr key={`c-${c.id}`} className="border-b border-line/60 last:border-0">
                                                    <td className="px-3 py-2 font-display font-bold text-fg">
                                                        #{c.cheque_number}
                                                    </td>
                                                    <td className="px-3 py-2 whitespace-nowrap text-muted">
                                                        {formatDate(c.cheque_date)}
                                                    </td>
                                                    <td className="px-3 py-2 text-fg">{c.payee_name ?? '—'}</td>
                                                    <td className="px-3 py-2 text-right font-mono text-muted">
                                                        {formatMoney(c.amount)}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <StatusBadge status={c.status} />
                                                    </td>
                                                </tr>
                                            ))}
                                            {lddaps.map((l) => (
                                                <tr key={`l-${l.id}`} className="border-b border-line/60 last:border-0">
                                                    <td className="px-3 py-2 font-display font-bold text-fg">
                                                        {l.check_no ?? '—'}
                                                    </td>
                                                    <td className="px-3 py-2 whitespace-nowrap text-muted">
                                                        {formatDate(l.check_date)}
                                                    </td>
                                                    {/* The sheet prints the LDDAP number as the payee. */}
                                                    <td className="px-3 py-2 text-fg">
                                                        {l.lddap_no}
                                                        {l.payee_name && (
                                                            <span className="mt-0.5 block text-xs text-subtle">
                                                                {l.payee_name}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2 text-right font-mono text-muted">
                                                        {formatMoney(l.amount)}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <LddapStatusBadge status={l.status} viewer={user?.role} />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                        <tfoot>
                                            <tr className="border-t border-line">
                                                <td className="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-subtle" colSpan={3}>
                                                    Total · {cheques.length + lddaps.length} record
                                                    {cheques.length + lddaps.length === 1 ? '' : 's'}
                                                </td>
                                                <td className="px-3 py-2 text-right font-mono text-sm font-semibold text-fg">
                                                    {formatMoney(total)}
                                                </td>
                                                <td />
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            )}
                        </section>

                        {error && <Alert kind="error">{error}</Alert>}

                        {/* Actions, below the record they act on. What is offered follows the
                            status: a Used ACIC is signed off; an Approved one is forwarded or
                            printed; membership can be swapped either side of that. Dismissing is
                            the X in the header — only the teller's confirm keeps an explicit
                            Cancel beside the irreversible button. */}
                        <div className="flex flex-col gap-2 border-t border-line pt-5 sm:flex-row">
                            {isConfirm && (
                                <button
                                    type="button"
                                    className="btn btn-ghost sm:flex-1"
                                    onClick={onClose}
                                    disabled={busy}
                                >
                                    Cancel
                                </button>
                            )}

                            {!isConfirm && canManage && canAddCheques && (
                                <button
                                    type="button"
                                    className="btn btn-outline sm:flex-1"
                                    onClick={() => setAddingCheques(true)}
                                    disabled={busy}
                                >
                                    <Plus className="h-4 w-4" />
                                    Add cheque
                                </button>
                            )}

                            {!isConfirm && canManage && canAddLddaps && (
                                <button
                                    type="button"
                                    className="btn btn-outline sm:flex-1"
                                    onClick={() => setAddingLddaps(true)}
                                    disabled={busy}
                                >
                                    <Plus className="h-4 w-4" />
                                    Add LDDAP
                                </button>
                            )}

                            {!isConfirm && canManage && (isUsed || isApproved) && (
                                <button
                                    type="button"
                                    className="btn btn-outline sm:flex-1"
                                    onClick={() => setReassigning(true)}
                                    disabled={busy}
                                >
                                    <Repeat className="h-4 w-4" />
                                    Re-assign
                                </button>
                            )}

                            {!isConfirm && isApproved && (
                                <button
                                    type="button"
                                    className="btn btn-outline sm:flex-1"
                                    onClick={() => window.print()}
                                    disabled={busy}
                                >
                                    <Printer className="h-4 w-4" />
                                    Print
                                </button>
                            )}

                            {!isConfirm && isApproved && isAdmin && onForward && (
                                <button
                                    type="button"
                                    className="btn btn-outline sm:flex-1"
                                    onClick={() => onForward(acic)}
                                    disabled={busy}
                                >
                                    <Send className="h-4 w-4" />
                                    Forward
                                </button>
                            )}

                            {!isConfirm && isUsed && isAdmin && (
                                <button
                                    type="button"
                                    className="btn btn-primary sm:flex-1"
                                    onClick={() => void handleApprove()}
                                    disabled={busy}
                                >
                                    <ShieldCheck className="h-4 w-4" />
                                    {busy ? 'Approving…' : 'Approve'}
                                </button>
                            )}

                            {isConfirm && (
                                <button
                                    type="button"
                                    className="btn btn-primary sm:flex-1"
                                    onClick={() => void handleComplete()}
                                    disabled={busy}
                                >
                                    <CheckCircle2 className="h-4 w-4" />
                                    {busy ? 'Completing…' : 'Confirm & complete'}
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* The printed ACIC form, laid out to the pre-agreed sheet the bank accepts.
                Hidden on screen; `@media print` in app.css hides everything else.

                Portalled to <body>: this dialog is a `position: fixed` overlay inside a
                `max-h`/`overflow-y-auto` card, and a fixed, clipped ancestor makes browsers
                clip printed content to a single viewport-sized page and repeat it on the next —
                which is what split one ACIC's table into several. As a direct child of <body>
                the form is in the normal print flow and paginates as one table. */}
            {acic &&
                isApproved &&
                acic.form &&
                createPortal(
                    <div className="acic-print" aria-hidden="true">
                        <div className="acic-head">
                            <div className="acic-head-bank">
                                <div>{acic.form.bank_name}</div>
                                <div>{acic.form.bank_branch}</div>
                                <div>{acic.form.bank_address}</div>
                                <div className="acic-prepared">
                                    DATE PREPARED&nbsp;&nbsp;{acic.form.date_prepared ?? ''}
                                </div>
                            </div>

                            <div className="acic-head-agency">
                                <div>{acic.form.agency_name}</div>
                                <div>{acic.form.agency_address}</div>
                            </div>

                            <dl className="acic-head-codes">
                                <dt>ACIC NO.:</dt>
                                <dd>{acic.form.acic_no}</dd>
                                <dt>ORG CODE:</dt>
                                <dd>{acic.form.org_code}</dd>
                                <dt>FUNDING SOURCE:</dt>
                                <dd>{acic.form.funding_source}</dd>
                                <dt>AREA CODE:</dt>
                                <dd>{acic.form.area_code}</dd>
                                <dt>ALLOCATION NO :</dt>
                                <dd>{acic.form.allocation_no}</dd>
                            </dl>
                        </div>

                        <h1>ADVICE OF CHECKS ISSUED AND CANCELLED</h1>

                        <div className="acic-account">ACCOUNT NO.: {acic.form.account_no}</div>

                        <table className="acic-checks">
                            <thead>
                                <tr>
                                    <th className="c-check">CHECK NO</th>
                                    <th className="c-date">DATE OF ISSUE</th>
                                    <th className="c-payee">PAYEE</th>
                                    <th className="c-amount">AMOUNT</th>
                                    <th className="c-obj">OBJ CODE</th>
                                    <th className="c-remarks">REMARKS</th>
                                </tr>
                            </thead>
                            <tbody>
                                {cheques.map((c) => (
                                    <tr key={`c-${c.id}`}>
                                        <td>{c.cheque_number}</td>
                                        <td>{printDate(c.cheque_date)}</td>
                                        <td>{c.payee_name ?? ''}</td>
                                        <td className="num">{printMoney(c.amount)}</td>
                                        <td />
                                        <td />
                                    </tr>
                                ))}
                                {lddaps.map((l) => (
                                    <tr key={`l-${l.id}`}>
                                        <td>{l.check_no ?? ''}</td>
                                        <td>{printDate(l.check_date)}</td>
                                        <td>{l.lddap_no}</td>
                                        <td className="num">{printMoney(l.amount)}</td>
                                        <td>{l.obj_no ?? ''}</td>
                                        <td />
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <div className="acic-totals">
                            <span>TOTAL ACIC AMOUNT :&nbsp;&nbsp;{acic.form.total_amount}</span>
                            <span>TOTAL NO. OF CHECKS :&nbsp;&nbsp;{acic.form.total_checks}</span>
                        </div>

                        <div className="acic-words">
                            <span className="lbl">AMOUNT IN WORDS :</span>
                            <span>{acic.form.amount_in_words}</span>
                        </div>

                        <div className="acic-gap" />

                        <div className="acic-foot">
                            <div className="acic-cancelled">
                                <div className="acic-cancelled-title">CANCELLED CHECKS</div>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>CHECK NO</th>
                                            <th>CHECK DATE</th>
                                            <th>REMARKS</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td />
                                            <td />
                                            <td />
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div className="acic-sigs">
                                <div>
                                    <span className="lbl">CERTIFIED CORRECT BY:</span>
                                    <span className="rule" />
                                    <span className="name">{acic.form.certified_by}</span>
                                </div>
                                <div>
                                    <span className="lbl">VERIFIED BY:</span>
                                    <span className="rule" />
                                    <span className="name" />
                                </div>
                                <div>
                                    <span className="lbl">RECEIVED BY:</span>
                                    <span className="rule" />
                                    <span className="name" />
                                </div>
                                <div>
                                    <span className="lbl">APPROVED BY:</span>
                                    <span className="rule" />
                                    <span className="name">{acic.form.approved_by}</span>
                                </div>
                                <div>
                                    <span className="lbl">POSTED BY:</span>
                                    <span className="rule" />
                                    <span className="name" />
                                </div>
                                <div>
                                    <span className="lbl">DELIVERED BY:</span>
                                    <span className="rule" />
                                    <span className="name" />
                                </div>
                            </div>
                        </div>

                        <div className="acic-file">
                            <span>**FILENAME:&nbsp;&nbsp;{acic.form.filename}</span>
                            <span>** FOR LBP USE ONLY</span>
                        </div>
                    </div>,
                    document.body,
                )}

            {addingCheques && acic && (
                <AcicUseModal
                    acic={acic}
                    onClose={() => setAddingCheques(false)}
                    onAssigned={() => {
                        setAddingCheques(false);
                        void load();
                        onChanged?.();
                    }}
                />
            )}

            {addingLddaps && acic && (
                <LddapAssignModal
                    acic={acic}
                    onClose={() => setAddingLddaps(false)}
                    onAssigned={() => {
                        setAddingLddaps(false);
                        void load();
                        onChanged?.();
                    }}
                />
            )}

            {reassigning && acic && (
                <AcicReassignModal
                    acic={acic}
                    onClose={() => setReassigning(false)}
                    onReassigned={(updated) => {
                        setReassigning(false);
                        setAcic(updated);
                        void load();
                        onChanged?.();
                    }}
                />
            )}
        </div>
    );
}
