import { useState, type FormEvent } from 'react';
import { PlusSquare, BookOpen } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import { PageHeader, Alert } from '../components/ui';

export default function AddRangePage() {
    const [startAt, setStartAt] = useState('');
    const [endAt, setEndAt] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    const start = Number(startAt);
    const end = Number(endAt);
    const valid =
        startAt !== '' &&
        endAt !== '' &&
        Number.isInteger(start) &&
        Number.isInteger(end) &&
        start >= 1 &&
        end >= start;
    const count = valid ? end - start + 1 : 0;

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        setSuccess('');

        if (!valid) {
            setError('Enter both serial numbers. The last must be the same as or higher than the first.');
            return;
        }

        setBusy(true);
        try {
            const result = await ChequeApi.addRange(start, end);
            setSuccess(`Registered ${result.count} cheques — serials #${result.from} to #${result.to}.`);
            setStartAt('');
            setEndAt('');
        } catch (err) {
            const apiErr = toApiError(err);
            setError(apiErr.errors.start_at?.[0] ?? apiErr.errors.end_at?.[0] ?? apiErr.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="mx-auto max-w-xl">
            <PageHeader
                title="Register cheque book"
                subtitle="Enter the serial range printed on your newly issued physical cheque book."
            />

            <form onSubmit={handleSubmit} className="card space-y-5 p-6">
                {error && <Alert kind="error">{error}</Alert>}
                {success && <Alert kind="success">{success}</Alert>}

                <p className="flex items-start gap-2 rounded-xs border border-line bg-well px-4 py-3 text-sm text-muted">
                    <BookOpen className="mt-0.5 h-4 w-4 shrink-0 text-brandink" />
                    <span>
                        Enter the first and last serial exactly as printed on the book. A serial that is already
                        registered will be rejected, and cheques are still issued in strict ascending order —
                        the lowest unused number is always next.
                    </span>
                </p>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label htmlFor="startAt" className="label">
                            First serial number
                        </label>
                        <input
                            id="startAt"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            className="field"
                            value={startAt}
                            onChange={(e) => setStartAt(e.target.value)}
                            placeholder="e.g. 10001"
                            autoFocus
                            required
                        />
                    </div>
                    <div>
                        <label htmlFor="endAt" className="label">
                            Last serial number
                        </label>
                        <input
                            id="endAt"
                            type="number"
                            inputMode="numeric"
                            min={start >= 1 ? start : 1}
                            className="field"
                            value={endAt}
                            onChange={(e) => setEndAt(e.target.value)}
                            placeholder="e.g. 10200"
                            required
                        />
                    </div>
                </div>

                <p className="text-sm text-muted" aria-live="polite">
                    {count > 0 ? (
                        <>
                            This will register{' '}
                            <span className="font-display font-bold text-brandink">
                                {count.toLocaleString()}
                            </span>{' '}
                            cheque{count === 1 ? '' : 's'}, #{start.toLocaleString()} to #{end.toLocaleString()}.
                        </>
                    ) : (
                        <span className="text-subtle">Enter both serials to see how many cheques that covers.</span>
                    )}
                </p>

                <button type="submit" className="btn btn-primary w-full" disabled={busy || !valid}>
                    <PlusSquare className="h-4 w-4" />
                    {busy ? 'Registering…' : 'Register cheque book'}
                </button>
            </form>
        </div>
    );
}
