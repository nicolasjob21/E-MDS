import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { X, Printer } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque, ChequePrintData } from '../lib/types';
import { Alert, Spinner } from './ui';

interface Props {
    cheque: Cheque;
    onClose: () => void;
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
        </div>
    );
}

/**
 * "View" on an approved cheque: the cheque as it will print, with Print and Close beneath.
 *
 * The face is rendered twice — once inside the dialog for the screen, and once portalled to
 * <body> as the print target, so printing never goes through the dialog's fixed overlay
 * (which browsers clip to one viewport-sized page).
 */
export default function ChequeViewModal({ cheque, onClose }: Props) {
    const [data, setData] = useState<ChequePrintData | null>(null);
    const [error, setError] = useState('');

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

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-labelledby="cheque-view-title"
        >
            <div className="card w-full max-w-3xl p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Cheque</span>
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

                {error ? (
                    <Alert kind="error">{error}</Alert>
                ) : data === null ? (
                    <Spinner />
                ) : (
                    <>
                        {/* Real size, so what is on screen is what prints. Scrolls sideways on a phone. */}
                        <div className="overflow-x-auto rounded-xs border border-line bg-well p-4">
                            <ChequeFace data={data} />
                        </div>
                        <p className="mt-3 text-xs text-subtle">
                            Prints the fields only, at cheque size, onto pre-printed Landbank stock. Adjust the
                            positions in <code className="font-mono">app.css</code> after a test print.
                        </p>
                    </>
                )}

                <div className="mt-5 flex flex-col gap-2 border-t border-line pt-5 sm:flex-row sm:justify-end">
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Close
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        onClick={() => window.print()}
                        disabled={data === null}
                    >
                        <Printer className="h-4 w-4" />
                        Print
                    </button>
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
