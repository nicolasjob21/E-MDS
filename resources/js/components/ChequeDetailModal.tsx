import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { X, Banknote, CheckCircle2, Landmark } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Cheque } from '../lib/types';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';
import { Alert, StatusBadge } from './ui';

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

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
    onClose: () => void;
    onChanged: (cheque: Cheque) => void;
}

export default function ChequeDetailModal({ cheque, onClose, onChanged }: Props) {
    const [current, setCurrent] = useState<Cheque>(cheque);
    const [recording, setRecording] = useState(false);
    const [tellerName, setTellerName] = useState('');
    const [cashedAt, setCashedAt] = useState(today());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const isUsed = current.status === 'used';
    const isCashed = !!current.is_cashed;

    async function handleCash(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            const updated = await ChequeApi.cash(current.id, {
                teller_name: tellerName.trim(),
                cashed_at: cashedAt,
            });
            setCurrent(updated);
            setRecording(false);
            onChanged(updated);
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setBusy(false);
        }
    }

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
                        <StatusBadge status={current.status} />
                        {isCashed && (
                            <span className="inline-flex items-center gap-1 rounded-xs border border-success/40 bg-success/10 px-2 py-0.5 text-xs font-medium text-success-fg">
                                <Banknote className="h-3 w-3" />
                                Cashed
                            </span>
                        )}
                        <button className="btn btn-ghost !px-2" onClick={onClose} aria-label="Close">
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                </div>

                {!isUsed ? (
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
                            <Row label="Amount" value={formatMoney(current.amount)} />
                            <Row label="Cheque date" value={formatDate(current.cheque_date)} />
                            <Row label="Used by" value={current.used_by?.name ?? current.used_by_name ?? '—'} />
                            <Row label="Used at" value={formatDateTime(current.used_at)} />
                        </section>

                        {/* Bank encashment — separate lifecycle step */}
                        <section className="rounded-xs border border-line bg-well p-4">
                            <h3 className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brandink">
                                <Landmark className="h-4 w-4" />
                                Bank encashment
                            </h3>

                            {isCashed ? (
                                <>
                                    <Row label="Received by teller" value={current.teller_name ?? '—'} />
                                    <Row label="Date received" value={formatDate(current.cashed_at)} />
                                    <p className="mt-3 flex items-center gap-1.5 text-xs text-success-fg">
                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                        This cheque has been cashed — converted into money.
                                    </p>
                                </>
                            ) : recording ? (
                                <form onSubmit={handleCash} className="space-y-3">
                                    <div>
                                        <label htmlFor="teller" className="label">
                                            Teller name
                                        </label>
                                        <input
                                            id="teller"
                                            className="field"
                                            value={tellerName}
                                            onChange={(e) => setTellerName(e.target.value)}
                                            placeholder="Name of the bank teller"
                                            autoFocus
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label htmlFor="received" className="label">
                                            Date received by teller
                                        </label>
                                        <input
                                            id="received"
                                            type="date"
                                            className="field"
                                            value={cashedAt}
                                            onChange={(e) => setCashedAt(e.target.value)}
                                            required
                                        />
                                    </div>
                                    {error && <Alert kind="error">{error}</Alert>}
                                    <div className="flex gap-2">
                                        <button type="submit" className="btn btn-primary flex-1" disabled={busy}>
                                            {busy ? 'Saving…' : 'Confirm cashed'}
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            onClick={() => setRecording(false)}
                                            disabled={busy}
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            ) : (
                                <>
                                    <p className="mb-3 text-sm text-muted">
                                        Not yet cashed. Record it once the cheque has been received by the bank teller
                                        and turned into money.
                                    </p>
                                    <button className="btn btn-primary w-full" onClick={() => setRecording(true)}>
                                        <Banknote className="h-4 w-4" />
                                        Mark as cashed
                                    </button>
                                </>
                            )}
                        </section>
                    </div>
                )}
            </div>
        </div>
    );
}
