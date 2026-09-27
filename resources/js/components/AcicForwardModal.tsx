import { useEffect, useState, type FormEvent } from 'react';
import { X, Send } from 'lucide-react';
import { AcicTellerApi, toApiError } from '../lib/api';
import type { Acic } from '../lib/types';
import { Alert } from './ui';

interface Props {
    acic: Acic;
    onClose: () => void;
    onForwarded: (acic: Acic) => void;
}

/**
 * Forward an ACIC — cheque or LDDAP — to the tellers (`POST acics/{acic}/forward-to-teller`).
 *
 * There is nobody to pick: every teller is notified, it shows as **Pending** until one accepts
 * it, and the first to accept takes it. So the dialog only asks the admin to confirm.
 */
export default function AcicForwardModal({ acic, onClose, onForwarded }: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const cheques = acic.cheque_count ?? acic.cheques?.length ?? 0;
    const lddaps = acic.lddap_count ?? acic.lddaps?.length ?? 0;
    const carries = [
        cheques > 0 ? `${cheques} cheque${cheques === 1 ? '' : 's'}` : null,
        lddaps > 0 ? `${lddaps} LDDAP record${lddaps === 1 ? '' : 's'}` : null,
    ]
        .filter(Boolean)
        .join(' and ');

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            onForwarded(await AcicTellerApi.forwardToTeller(acic.id));
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
            setBusy(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={() => !busy && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="acic-forward-title"
        >
            <div className="card w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Forward</span>
                        <h2 id="acic-forward-title" className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg">
                            ACIC #{acic.acic_number}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <form onSubmit={handleSubmit}>
                    <p className="mb-4 text-sm text-muted">
                        Forward ACIC #{acic.acic_number} to the tellers? It carries{' '}
                        <span className="font-semibold text-fg">{carries || 'nothing yet'}</span>. It shows as{' '}
                        <span className="font-semibold text-fg">Pending</span> until a teller accepts it; every teller is
                        notified, and the first to accept takes it.
                    </p>

                    {error && (
                        <div className="mb-4">
                            <Alert kind="error">{error}</Alert>
                        </div>
                    )}

                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy} autoFocus>
                            <Send className="h-4 w-4" />
                            {busy ? 'Forwarding…' : 'Yes, forward to Teller'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
