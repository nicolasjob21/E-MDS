import { useEffect, useState, type FormEvent } from 'react';
import { X, Send } from 'lucide-react';
import { AcicApi, UserApi, toApiError } from '../lib/api';
import type { Acic, User } from '../lib/types';
import { Alert } from './ui';

interface Props {
    acic: Acic;
    onClose: () => void;
    onForwarded: (acic: Acic) => void;
}

/** Sentinel for "the recipient has no account" — the name is typed in instead. */
const OTHER = 'other';

/**
 * Forward an ACIC onward, recording the forward date and who received it.
 *
 * The recipient is normally the teller. When the ACIC is handed to someone with no account,
 * choosing "Someone else" records the typed-in name of whoever accepted it instead.
 */
export default function AcicForwardModal({ acic, onClose, onForwarded }: Props) {
    const [users, setUsers] = useState<User[]>([]);
    const [receivedBy, setReceivedBy] = useState('');
    const [receivedName, setReceivedName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const isOther = receivedBy === OTHER;

    useEffect(() => {
        void (async () => {
            try {
                setUsers((await UserApi.list()).filter((u) => u.is_active));
            } catch (err) {
                setError(toApiError(err).message);
            }
        })();
    }, []);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (!receivedBy) {
            setError('Choose who is receiving this ACIC.');
            return;
        }
        if (isOther && receivedName.trim() === '') {
            setError('Type the name of whoever accepted this ACIC.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            onForwarded(
                await AcicApi.forward(
                    acic.id,
                    isOther ? { receivedName: receivedName.trim() } : { receivedBy: Number(receivedBy) },
                ),
            );
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
                        <h2
                            id="acic-forward-title"
                            className="mt-2 font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            ACIC #{acic.acic_number}
                        </h2>
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    Forwarding stamps today’s date on this ACIC and records who received it. It carries{' '}
                    <span className="font-semibold text-fg">
                        {acic.cheque_count ?? acic.cheques?.length ?? 0}
                    </span>{' '}
                    cheque{(acic.cheque_count ?? acic.cheques?.length ?? 0) === 1 ? '' : 's'}. This cannot be
                    undone.
                </p>

                <form onSubmit={handleSubmit}>
                    <div>
                        <label htmlFor="received-by" className="label">
                            Received by
                        </label>
                        <select
                            id="received-by"
                            className="field"
                            value={receivedBy}
                            onChange={(e) => setReceivedBy(e.target.value)}
                            required
                            autoFocus
                        >
                            <option value="">Choose a user…</option>
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name} ({u.username})
                                </option>
                            ))}
                            <option value={OTHER}>Someone else…</option>
                        </select>
                    </div>

                    {isOther && (
                        <div className="mt-4">
                            <label htmlFor="received-name" className="label">
                                Name of whoever accepted it
                            </label>
                            <input
                                id="received-name"
                                className="field"
                                value={receivedName}
                                onChange={(e) => setReceivedName(e.target.value)}
                                placeholder="e.g. J. Dela Cruz"
                                maxLength={255}
                                required
                            />
                            <p className="mt-1 text-xs text-subtle">
                                Use this when the recipient has no account in the system.
                            </p>
                        </div>
                    )}

                    {error && (
                        <div className="mt-4">
                            <Alert kind="error">{error}</Alert>
                        </div>
                    )}

                    <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                            <Send className="h-4 w-4" />
                            {busy ? 'Forwarding…' : 'Forward ACIC'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
