import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { PlusSquare, Hash } from 'lucide-react';
import { AcicApi, toApiError } from '../lib/api';
import type { AcicSeries } from '../lib/types';
import { PageHeader, Alert, Spinner } from '../components/ui';

/**
 * Admin registration of the ACIC number series.
 *
 * ACIC numbers are issued to the office in blocks rather than invented by the system, so they
 * are registered here the way cheque books and the LDDAP check series are. This series is
 * independent of both and may overlap either without conflict.
 */
export default function AcicSeriesPage() {
    const [startAt, setStartAt] = useState('');
    const [endAt, setEndAt] = useState('');
    const [series, setSeries] = useState<AcicSeries | null>(null);
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

    const load = useCallback(async () => {
        try {
            setSeries(await AcicApi.series());
        } catch (err) {
            setError(toApiError(err).message);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        setSuccess('');

        if (!valid) {
            setError('Enter both numbers. The last must be the same as or higher than the first.');
            return;
        }

        setBusy(true);
        try {
            const result = await AcicApi.addRange(start, end);
            setSuccess(`Registered ${result.count} ACIC number(s) — #${result.from} to #${result.to}.`);
            setStartAt('');
            setEndAt('');
            await load();
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
                title="ACIC number series"
                subtitle="Register the ACIC numbers this office has been issued."
            />

            {series === null ? (
                <Spinner />
            ) : (
                <div className="mb-6 grid grid-cols-3 gap-px overflow-hidden rounded-xs border border-line bg-line">
                    {[
                        { label: 'Registered', value: series.registered },
                        { label: 'Unused', value: series.available },
                        { label: 'Used', value: series.used },
                    ].map(({ label, value }) => (
                        <div key={label} className="bg-card p-4 text-center">
                            <div className="font-display text-2xl font-extrabold text-brandink">
                                {value.toLocaleString()}
                            </div>
                            <div className="mt-1 text-xs uppercase tracking-[0.18em] text-muted">{label}</div>
                        </div>
                    ))}
                </div>
            )}

            <form onSubmit={handleSubmit} className="card space-y-5 p-6">
                {error && <Alert kind="error">{error}</Alert>}
                {success && <Alert kind="success">{success}</Alert>}

                <p className="flex items-start gap-2 rounded-xs border border-line bg-well px-4 py-3 text-sm text-muted">
                    <Hash className="mt-0.5 h-4 w-4 shrink-0 text-brandink" />
                    <span>
                        A number already registered here is{' '}
                        <span className="font-semibold text-fg">rejected</span>, and an ACIC always takes the
                        lowest unused number next — so registering a block <em>below</em> numbers already in
                        use puts those lower numbers first. Blocks that adjoin simply continue the run; nothing
                        separate is created. This series is independent of cheque and LDDAP check numbers and
                        may overlap either without conflict.
                    </span>
                </p>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label htmlFor="startAt" className="label">
                            First ACIC number
                        </label>
                        <input
                            id="startAt"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            className="field"
                            value={startAt}
                            onChange={(e) => setStartAt(e.target.value)}
                            placeholder="e.g. 1"
                            autoFocus
                            required
                        />
                    </div>
                    <div>
                        <label htmlFor="endAt" className="label">
                            Last ACIC number
                        </label>
                        <input
                            id="endAt"
                            type="number"
                            inputMode="numeric"
                            min={start >= 1 ? start : 1}
                            className="field"
                            value={endAt}
                            onChange={(e) => setEndAt(e.target.value)}
                            placeholder="e.g. 300"
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
                            ACIC number{count === 1 ? '' : 's'}, #{start.toLocaleString()} to #
                            {end.toLocaleString()}.
                        </>
                    ) : (
                        <span className="text-subtle">
                            Enter both numbers to see how many that covers.
                        </span>
                    )}
                </p>

                <button type="submit" className="btn btn-primary w-full" disabled={busy || !valid}>
                    <PlusSquare className="h-4 w-4" />
                    {busy ? 'Registering…' : 'Register ACIC numbers'}
                </button>
            </form>
        </div>
    );
}
