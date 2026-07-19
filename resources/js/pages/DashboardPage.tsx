import { useCallback, useEffect, useState } from 'react';
import { Layers, CircleDot, CheckCheck, BadgeCheck } from 'lucide-react';
import { ChequeApi, toApiError } from '../lib/api';
import type { Summary } from '../lib/types';
import { useAuth } from '../auth/AuthContext';
import { PageHeader, Spinner, Alert } from '../components/ui';
import NextChequePanel from '../components/NextChequePanel';

const CARDS = [
    { key: 'total', label: 'Total cheques', icon: Layers, color: 'text-fg' },
    { key: 'available', label: 'Available', icon: CircleDot, color: 'text-brandink' },
    { key: 'used', label: 'Used', icon: CheckCheck, color: 'text-accent-400' },
    { key: 'received', label: 'Received', icon: BadgeCheck, color: 'text-success-fg' },
] as const;

export default function DashboardPage() {
    const { user } = useAuth();
    const [summary, setSummary] = useState<Summary | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            setSummary(await ChequeApi.summary());
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    return (
        <div>
            <PageHeader title={`Welcome, ${user?.name?.split(' ')[0] ?? ''}`} subtitle="Cheque sequence at a glance." />

            {loading && <Spinner />}
            {error && <Alert kind="error">{error}</Alert>}

            {summary && (
                <div className="space-y-8">
                    <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-4">
                        {CARDS.map(({ key, label, icon: Icon, color }) => (
                            <div key={key} className="bg-card p-6">
                                <div className="flex items-center justify-between">
                                    <span className="text-xs font-semibold uppercase tracking-[0.15em] text-subtle">
                                        {label}
                                    </span>
                                    <Icon className={`h-5 w-5 ${color}`} />
                                </div>
                                <div className={`mt-4 font-display text-4xl font-extrabold ${color}`}>
                                    {summary.counts[key].toLocaleString()}
                                </div>
                            </div>
                        ))}
                    </div>

                    <NextChequePanel next={summary.next} onUsed={() => void load()} />
                </div>
            )}
        </div>
    );
}
