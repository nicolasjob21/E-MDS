import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { X, Printer, CheckCircle2, BadgeCheck, Undo2 } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque, ChequePrintData } from '../lib/types';
import { Alert, Spinner } from './ui';

/**
 * - `view`: reprint a cheque on an ACIC.
 * - `draft`: Print Draft — submits the cheque for checking, then prints it watermarked.
 * - `final`: Final Print — prints it clean, then asks whether it printed; "Yes" moves it on.
 * - `check`: an admin in charge checks the draft as printed, then Approves or Returns it
 *   (a comment is required to return, optional to approve).
 */
export type ChequePrintMode = 'view' | 'draft' | 'final' | 'check';

interface Props {
    cheque: Cheque;
    mode?: ChequePrintMode;
    onClose: () => void;
    /** After a draft is submitted, checked, or a final print confirmed: the cheque as it now stands. */
    onDone?: (cheque: Cheque, what: string) => void;
}

/** The cheque's date as it is written on the face: MM/DD/YYYY. */
function chequeDate(value: string | null): string {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return `${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}/${d.getFullYear()}`;
}

/**
 * The cheque's face, laid out to the Landbank cheque at its real size. Every field sits at a
 * fixed position in millimetres — the positions are the CSS custom properties on `.cheque-face`
 * in app.css, all in one block, so a test print on pre-printed stock can be tuned in one place.
 *
 * On screen the pre-printed labels are drawn faintly so the layout reads as a cheque; in print
 * only the variable fields are output, since the labels are already on the stock.
 */
function ChequeFace({ data }: { data: ChequePrintData }) {
    const refs = [
        data.acic_number != null ? `ACIC #${data.acic_number}` : null,
        data.lddap_no ? `LDDAP ${data.lddap_no}` : null,
    ].filter(Boolean);

    return (
        <div className="cheque-face" aria-label={`Cheque number ${data.cheque_number}`}>
            {/* Pre-printed on the stock; shown on screen only. */}
            <div className="cheque-label cheque-bank">
                <span className="cheque-bank-name">{data.bank_name}</span>
                <span className="cheque-bank-branch">{data.bank_branch}</span>
            </div>
            <div className="cheque-label cheque-lbl-date">DATE</div>
            <div className="cheque-label cheque-lbl-payee">PAY TO THE ORDER OF</div>
            <div className="cheque-label cheque-lbl-figures">₱</div>
            <div className="cheque-label cheque-lbl-words">PESOS</div>
            <div className="cheque-label cheque-lbl-account">ACCOUNT NO.</div>
            <div className="cheque-label cheque-lbl-sign">AUTHORIZED SIGNATURE</div>
            <div className="cheque-label cheque-rule cheque-rule-payee" />
            <div className="cheque-label cheque-rule cheque-rule-words" />
            <div className="cheque-label cheque-rule cheque-rule-sign" />

            {/* The variable fields — what actually prints. */}
            <div className="cheque-field cheque-checkno">{String(data.cheque_number).padStart(10, '0')}</div>
            <div className="cheque-field cheque-date">{chequeDate(data.cheque_date)}</div>
            <div className="cheque-field cheque-payee">{data.payee_name ?? ''}</div>
            <div className="cheque-field cheque-figures">{data.amount_figures.replace('₱', '')}</div>
            <div className="cheque-field cheque-words">{data.amount_in_words}</div>
            <div className="cheque-field cheque-account">{data.account_no}</div>
            <div className="cheque-field cheque-ref">{refs.join(' · ')}</div>
            {data.draft && <div className="cheque-draft-mark">DRAFT</div>}
        </div>
    );
}

/**
 * The cheque as it will print, with the mode's action beneath: Print (view), Print Draft (draft)
 * or Final Print (final), then Close.
 *
 * The face is rendered twice — once inside the dialog for the screen, and once portalled to
 * <body> as the print target, so printing never goes through the dialog's fixed overlay
 * (which browsers clip to one viewport-sized page).
 */
export default function ChequeViewModal({ cheque, mode = 'view', onClose, onDone }: Props) {
    const [data, setData] = useState<ChequePrintData | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    // Final only: printed, now asking whether it came out right.
    const [askingPrinted, setAskingPrinted] = useState(false);
    // Check only: the admin's comment — what to change on a return.
    const [comment, setComment] = useState('');

    const load = useCallback(async () => {
        try {
            setData(await ChequeApi.printData(cheque.id));
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        }
    }, [cheque.id]);

    useEffect(() => {
        void load();
    }, [load]);

    /** Print Draft: submit first, so a failure never leaves a draft printed but not submitted. */
    async function printDraft() {
        setBusy(true);
        setError('');
        try {
            const submitted = await ChequeApi.printDraft(cheque.id, cheque.status);
            window.print();
            onDone?.(submitted, 'draft printed and sent for checking');
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    function printFinal() {
        window.print();
        setAskingPrinted(true);
    }

    /** Check: Approve (→ For Final Print) or Return (→ For Compliance, comment required). */
    async function check(verdict: 'approve' | 'return') {
        setBusy(true);
        setError('');
        try {
            const checked =
                verdict === 'approve'
                    ? await ChequeApi.approveDraft(cheque.id, comment.trim(), cheque.status)
                    : await ChequeApi.returnDraft(cheque.id, comment.trim(), cheque.status);
            onDone?.(checked, verdict === 'approve' ? 'draft approved — now For Final Print' : 'draft returned — now For Compliance');
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    async function confirmPrinted() {
        setBusy(true);
        setError('');
        try {
            onDone?.(await ChequeApi.confirmFinalPrint(cheque.id, cheque.status), 'printed — now For Signature');
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setAskingPrinted(false);
            setBusy(false);
        }
    }

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-labelledby="cheque-view-title"
        >
            <div className="card max-h-[92vh] w-full max-w-3xl overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">
                            {mode === 'draft' ? 'Print Draft' : mode === 'final' ? 'Final Print' : mode === 'check' ? 'Check Draft' : 'Cheque'}
                        </span>
                        <h2
                            id="cheque-view-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            #{cheque.cheque_number}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {error && (
                    <div className="mb-3">
                        <Alert kind="error">{error}</Alert>
                    </div>
                )}
                {data === null && !error ? (
                    <Spinner />
                ) : data === null ? null : (
                    <>
                        {/* Real size, so what is on screen is what prints. Scrolls sideways on a phone. */}
                        <div className="overflow-x-auto rounded-xs border border-line bg-well p-4">
                            <ChequeFace data={data} />
                        </div>
                        <p className="mt-3 text-xs text-subtle">
                            {mode === 'draft'
                                ? 'Printing the draft sends it to the admin in charge for checking. It prints with a DRAFT watermark.'
                                : mode === 'check'
                                  ? 'The draft as the preparer printed it. Check every field, then approve it or return it with what to change.'
                                  : 'Prints the fields only, at cheque size, onto pre-printed Landbank stock. Adjust the positions in app.css after a test print.'}
                        </p>
                    </>
                )}

                {mode === 'check' && data !== null && (
                    <div className="mt-4">
                        <label htmlFor="check-comment" className="label !mb-1">
                            Comment <span className="normal-case tracking-normal text-subtle">(required to return — say what to change)</span>
                        </label>
                        <textarea
                            id="check-comment"
                            className="field min-h-20 !py-1.5"
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            maxLength={2000}
                        />
                    </div>
                )}

                {askingPrinted && (
                    <div className="mt-4 rounded-xs border border-accent-400/50 bg-accent-400/10 p-3 text-sm text-fg" role="alert">
                        Did cheque <span className="font-semibold">#{cheque.cheque_number}</span> print successfully? Confirming moves
                        it to <span className="font-semibold">For Signature</span>.
                    </div>
                )}

                <div className="mt-5 flex flex-col gap-2 border-t border-line pt-5 sm:flex-row sm:justify-end">
                    {askingPrinted ? (
                        <>
                            <button type="button" className="btn btn-ghost" onClick={() => setAskingPrinted(false)} disabled={busy}>
                                No — print again
                            </button>
                            <button type="button" className="btn btn-primary" onClick={() => void confirmPrinted()} disabled={busy} autoFocus>
                                <CheckCircle2 className="h-4 w-4" />
                                {busy ? 'Saving…' : 'Yes, it printed'}
                            </button>
                        </>
                    ) : mode === 'check' ? (
                        <>
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Close
                            </button>
                            <button type="button" className="btn btn-ghost" onClick={() => window.print()} disabled={data === null || busy}>
                                <Printer className="h-4 w-4" />
                                Print
                            </button>
                            <button
                                type="button"
                                className="btn btn-outline"
                                onClick={() => void check('return')}
                                disabled={data === null || busy || comment.trim() === ''}
                                title={comment.trim() === '' ? 'Add a comment saying what to change' : undefined}
                            >
                                <Undo2 className="h-4 w-4" />
                                Return
                            </button>
                            <button type="button" className="btn btn-primary" onClick={() => void check('approve')} disabled={data === null || busy}>
                                <BadgeCheck className="h-4 w-4" />
                                {busy ? 'Saving…' : 'Approve'}
                            </button>
                        </>
                    ) : (
                        <>
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Close
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => (mode === 'draft' ? void printDraft() : mode === 'final' ? printFinal() : window.print())}
                                disabled={data === null || busy}
                            >
                                <Printer className="h-4 w-4" />
                                {busy ? 'Submitting…' : mode === 'draft' ? 'Print Draft' : mode === 'final' ? 'Final Print' : 'Print'}
                            </button>
                        </>
                    )}
                </div>
            </div>

            {/* The print target, outside the overlay. */}
            {data &&
                createPortal(
                    <div className="cheque-print" aria-hidden="true">
                        <ChequeFace data={data} />
                    </div>,
                    document.body,
                )}
        </div>
    );
}
