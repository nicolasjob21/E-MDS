import { useEffect, useState, type FormEvent } from 'react';
import { PlusSquare } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import { PageHeader, Alert } from '../components/ui';

export default function AddRangePage() {
    const [count, setCount] = useState('100');
    const [startAt, setStartAt] = useState('1');
    const [isFirstRange, setIsFirstRange] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    useEffect(() => {
        void (async () => {
            try {
                const summary = await ChequeApi.summary();
                setIsFirstRange(summary.counts.total === 0);
            } catch {
                /* ignore */
            }
        })();
    }, []);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        setSuccess('');
        const n = Number(count);
        if (!Number.isInteger(n) || n < 1) {
            setError('Enter how many cheque numbers to add (a whole number of at least 1).');
            return;
        }
        setBusy(true);
        try {
            const result = await ChequeApi.addRange(n, isFirstRange ? Number(startAt) : undefined);
            setSuccess(`Added ${result.count} cheques — numbers #${result.from} to #${result.to}.`);
            setIsFirstRange(false);
        } catch (err) {
            const apiErr = toApiError(err);
            setError(apiErr.errors.count?.[0] ?? apiErr.errors.start_at?.[0] ?? apiErr.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="mx-auto max-w-xl">
            <PageHeader title="Add cheque range" subtitle="Extend the sequence — new numbers always continue from the last one." />

            <form onSubmit={handleSubmit} className="card space-y-5 p-6">
                {error && <Alert kind="error">{error}</Alert>}
                {success && <Alert kind="success">{success}</Alert>}

                <p className="rounded-xs border border-line bg-well px-4 py-3 text-sm text-muted">
                    New cheques are appended to the end of the running sequence. Gaps and duplicate numbers are impossible —
                    the starting number is determined by the system.
                </p>

                {isFirstRange && (
                    <div>
                        <label htmlFor="startAt" className="label">
                            First cheque number
                        </label>
                        <input
                            id="startAt"
                            type="number"
                            min={1}
                            className="field"
                            value={startAt}
                            onChange={(e) => setStartAt(e.target.value)}
                        />
                        <p className="mt-1 text-xs text-subtle">
                            No cheques exist yet, so you can choose where the sequence begins.
                        </p>
                    </div>
                )}

                <div>
                    <label htmlFor="count" className="label">
                        How many to add
                    </label>
                    <input
                        id="count"
                        type="number"
                        min={1}
                        max={100000}
                        className="field"
                        value={count}
                        onChange={(e) => setCount(e.target.value)}
                        required
                    />
                </div>

                <button type="submit" className="btn btn-primary w-full" disabled={busy}>
                    <PlusSquare className="h-4 w-4" />
                    {busy ? 'Adding…' : 'Add to sequence'}
                </button>
            </form>
        </div>
    );
}
