import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import {
    Layers,
    CircleDot,
    BadgeCheck,
    ScrollText,
    Send,
    HandCoins,
    FilePlus2,
    ListChecks,
    Landmark,
    Receipt,
    FileText,
    Hash,
    BookOpen,
    AlertTriangle,
    ArrowRight,
    Inbox,
    Sparkles,
} from 'lucide-react';
import { DashboardApi, toApiError } from '../lib/api';
import type { AcicStatus, AttentionItem, Dashboard, LddapStatus, SeriesGlance } from '../lib/types';
import { useAuth } from '../auth/AuthContext';
import { PageHeader, Spinner, Alert } from '../components/ui';
import NextChequePanel from '../components/NextChequePanel';
import { actionLabel, formatRelative } from '../lib/format';

// ---- the tiles ---------------------------------------------------------------------------

const CHEQUE_TILES = [
    { key: 'total', label: 'Total cheques', icon: Layers, color: 'text-fg', to: '/cheques' },
    { key: 'available', label: 'Available', icon: CircleDot, color: 'text-brandink', to: '/cheques?tab=available' },
    { key: 'registered', label: 'Registered', icon: FilePlus2, color: 'text-fg', to: '/cheques?tab=registered' },
    { key: 'out_for_signature', label: 'Out for Signature', icon: Send, color: 'text-accent-400', to: '/cheques?tab=out_for_signature' },
    { key: 'for_acic', label: 'For ACIC', icon: ListChecks, color: 'text-brandink', to: '/cheques?tab=for_acic' },
    { key: 'approved', label: 'Approved', icon: BadgeCheck, color: 'text-success-fg', to: '/cheques?tab=approved' },
    { key: 'released_to_payee', label: 'Released', icon: HandCoins, color: 'text-teal-300', to: '/cheques?tab=released_to_payee' },
    { key: 'completed', label: 'Completed', icon: Landmark, color: 'text-blue-300', to: '/cheques?tab=completed' },
] as const;

const LDDAP_TILES: { key: LddapStatus; label: string; color: string }[] = [
    { key: 'registered', label: 'Registered', color: 'text-fg' },
    { key: 'for_out', label: 'For Out', color: 'text-brandink' },
    { key: 'returned_for_acic', label: 'Returned for ACIC', color: 'text-accent-400' },
    { key: 'rts', label: 'RTS', color: 'text-amber-400' },
    { key: 'approved', label: 'Approved', color: 'text-success-fg' },
    { key: 'canceled', label: 'Canceled', color: 'text-danger-fg' },
];

const ACIC_TILES: { key: AcicStatus; label: string; color: string; tab: string }[] = [
    { key: 'open', label: 'Open', color: 'text-brandink', tab: 'all' },
    { key: 'used', label: 'Used', color: 'text-fg', tab: 'all' },
    { key: 'approved', label: 'Approved', color: 'text-success-fg', tab: 'all' },
    { key: 'forwarded', label: 'Forwarded', color: 'text-accent-400', tab: 'forwarded' },
    { key: 'completed', label: 'Completed', color: 'text-success-fg', tab: 'completed' },
];

const TONE: Record<AttentionItem['tone'], string> = {
    accent: 'border-accent-400/50 bg-accent-400/10 text-accent-400',
    warn: 'border-amber-400/50 bg-amber-400/10 text-amber-400',
    brand: 'border-brand-400/40 bg-brand-500/10 text-brandink',
};

// ---- building blocks ---------------------------------------------------------------------

function SectionTitle({ icon: Icon, children, action }: { icon: typeof Layers; children: ReactNode; action?: ReactNode }) {
    return (
        <div className="mb-3 flex items-end justify-between gap-4">
            <h2 className="eyebrow">
                <Icon className="mr-1.5 inline h-3.5 w-3.5" />
                {children}
            </h2>
            {action}
        </div>
    );
}

/** A count tile in a hairline-seamed grid; the whole tile links to the filtered list. */
function Tile({ label, value, color, to, icon: Icon }: { label: string; value: number; color: string; to: string; icon?: typeof Layers }) {
    return (
        <Link to={to} className="group block bg-card p-5 transition-colors hover:bg-well">
            <div className="flex items-center justify-between gap-2">
                <span className="text-[11px] font-semibold uppercase tracking-[0.15em] text-subtle">{label}</span>
                {Icon && <Icon className={`h-4 w-4 ${color}`} />}
            </div>
            <div className={`mt-3 font-display text-3xl font-extrabold ${color}`}>{value.toLocaleString()}</div>
        </Link>
    );
}

function SeriesCard({
    title,
    icon: Icon,
    series,
    unit,
    to,
    registerTo,
    isAdmin,
}: {
    title: string;
    icon: typeof Hash;
    series: SeriesGlance;
    unit: string;
    to: string;
    registerTo: string;
    isAdmin: boolean;
}) {
    return (
        <div className={`card p-5 ${series.low ? 'border-amber-400/50' : ''}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span className="grid h-9 w-9 place-items-center rounded-xs border border-line bg-brand-500/10 text-brandink">
                        <Icon className="h-4 w-4" />
                    </span>
                    <div>
                        <div className="font-display text-sm font-semibold text-fg">{title}</div>
                        <div className="text-xs text-subtle">
                            next <span className="font-mono text-fg">{series.next != null ? `#${series.next}` : '—'}</span>
                        </div>
                    </div>
                </div>
                {series.low && (
                    <span className="inline-flex items-center gap-1 rounded-xs border border-amber-400/50 bg-amber-400/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-400">
                        <AlertTriangle className="h-3 w-3" />
                        Low
                    </span>
                )}
            </div>
            <div className="mt-4 flex items-baseline gap-2">
                <span className={`font-display text-3xl font-extrabold ${series.low ? 'text-amber-400' : 'text-brandink'}`}>
                    {series.available.toLocaleString()}
                </span>
                <span className="text-xs uppercase tracking-wider text-subtle">{unit} available</span>
            </div>
            {series.registered != null && (
                <div className="mt-1 text-xs text-muted">
                    {series.used?.toLocaleString() ?? 0} used of {series.registered.toLocaleString()} registered
                </div>
            )}
            <div className="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <Link to={to} className="inline-flex items-center gap-1 text-brandink hover:underline">
                    Open register <ArrowRight className="h-3 w-3" />
                </Link>
                {isAdmin && (
                    <Link to={registerTo} className="inline-flex items-center gap-1 text-accent-400 hover:underline">
                        Register more <ArrowRight className="h-3 w-3" />
                    </Link>
                )}
            </div>
        </div>
    );
}

// ---- the page ----------------------------------------------------------------------------

/**
 * The dashboard: what is waiting on the signed-in user first, then every register at a glance
 * — cheques (with the next-in-line number, usable from here), LDDAPs by routing status, ACICs,
 * the three number series with a low-stock flag, and, for admins, the latest audit activity.
 * Every tile is a link into the matching filtered list.
 */
export default function DashboardPage() {
    const { user, isAdmin, isTeller } = useAuth();
    const [data, setData] = useState<Dashboard | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            setData(await DashboardApi.show());
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    const firstName = user?.name?.split(' ')[0] ?? '';

    return (
        <div>
            <PageHeader
                title={`Welcome, ${firstName}`}
                subtitle="What is waiting on you, and where every register stands."
            />

            {loading && !data && <Spinner />}
            {error && <Alert kind="error">{error}</Alert>}

            {data && (
                <div className="space-y-10">
                    {/* ---- Needs your attention ------------------------------------------ */}
                    <section>
                        <SectionTitle icon={Inbox}>Needs your attention</SectionTitle>
                        {data.attention.length === 0 ? (
                            <div className="card flex items-center gap-3 p-5 text-sm text-muted">
                                <Sparkles className="h-4 w-4 shrink-0 text-success" />
                                Nothing is waiting on you right now.
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                {data.attention.map((item) => (
                                    <Link
                                        key={item.key}
                                        to={item.to}
                                        className={`group flex items-start justify-between gap-3 rounded-xs border p-4 transition-transform hover:-translate-y-0.5 ${TONE[item.tone]}`}
                                    >
                                        <div className="min-w-0">
                                            <div className="font-display text-sm font-semibold text-fg">{item.label}</div>
                                            <div className="mt-1 text-xs text-muted">{item.hint}</div>
                                            <div className="mt-2 inline-flex items-center gap-1 text-xs font-medium">
                                                Open <ArrowRight className="h-3 w-3 transition-transform group-hover:translate-x-0.5" />
                                            </div>
                                        </div>
                                        <span className="font-display text-3xl font-extrabold leading-none">
                                            {item.count.toLocaleString()}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>

                    {/* ---- Cheques -------------------------------------------------------- */}
                    <section>
                        <SectionTitle
                            icon={Layers}
                            action={
                                <Link to="/cheques" className="inline-flex items-center gap-1 text-xs text-brandink hover:underline">
                                    All cheques <ArrowRight className="h-3 w-3" />
                                </Link>
                            }
                        >
                            Cheques
                        </SectionTitle>
                        <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-4">
                            {CHEQUE_TILES.map(({ key, label, icon, color, to }) => (
                                <Tile key={key} label={label} value={data.cheques.counts[key]} color={color} to={to} icon={icon} />
                            ))}
                        </div>
                        {!isTeller && (
                            <div className="mt-4">
                                <NextChequePanel next={data.cheques.next} onUsed={() => void load()} />
                            </div>
                        )}
                    </section>

                    {/* ---- LDDAP ---------------------------------------------------------- */}
                    <section>
                        <SectionTitle
                            icon={Receipt}
                            action={
                                <Link to="/lddaps" className="inline-flex items-center gap-1 text-xs text-brandink hover:underline">
                                    All LDDAPs ({data.lddaps.counts.total.toLocaleString()}) <ArrowRight className="h-3 w-3" />
                                </Link>
                            }
                        >
                            LDDAP-ADA
                        </SectionTitle>
                        <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-3 xl:grid-cols-6">
                            {LDDAP_TILES.map(({ key, label, color }) => (
                                <Tile key={key} label={label} value={data.lddaps.counts[key]} color={color} to={`/lddaps?status=${key}`} />
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-muted">
                            Of the approved,{' '}
                            <Link to="/lddaps?status=approved" className="font-medium text-brandink hover:underline">
                                {data.lddaps.awaiting_acic.toLocaleString()} awaiting an ACIC
                            </Link>{' '}
                            · {data.lddaps.on_acic.toLocaleString()} on one (with a check number).
                        </p>
                    </section>

                    {/* ---- ACIC ----------------------------------------------------------- */}
                    <section>
                        <SectionTitle
                            icon={FileText}
                            action={
                                <Link to="/acics" className="inline-flex items-center gap-1 text-xs text-brandink hover:underline">
                                    All ACICs ({data.acics.counts.total.toLocaleString()}) <ArrowRight className="h-3 w-3" />
                                </Link>
                            }
                        >
                            ACIC
                        </SectionTitle>
                        <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xs border border-line bg-line sm:grid-cols-5">
                            {ACIC_TILES.map(({ key, label, color, tab }) => (
                                <Tile key={key} label={label} value={data.acics.counts[key]} color={color} to={`/acics?tab=${tab}`} />
                            ))}
                            <div className="bg-card sm:hidden" />
                        </div>
                    </section>

                    {/* ---- Number series -------------------------------------------------- */}
                    <section>
                        <SectionTitle icon={Hash}>Number series</SectionTitle>
                        <div className="grid gap-3 md:grid-cols-3">
                            <SeriesCard
                                title="Cheque book"
                                icon={BookOpen}
                                series={data.series.cheques}
                                unit="cheques"
                                to="/cheques?tab=available"
                                registerTo="/admin/add-range"
                                isAdmin={isAdmin}
                            />
                            <SeriesCard
                                title="LDDAP check numbers"
                                icon={Hash}
                                series={data.series.lddap_checks}
                                unit="numbers"
                                to="/lddaps"
                                registerTo="/admin/lddap-series"
                                isAdmin={isAdmin}
                            />
                            <SeriesCard
                                title="ACIC numbers"
                                icon={FileText}
                                series={data.series.acic_numbers}
                                unit="numbers"
                                to="/acics"
                                registerTo="/admin/acic-series"
                                isAdmin={isAdmin}
                            />
                        </div>
                    </section>

                    {/* ---- Recent activity (admin) ---------------------------------------- */}
                    {isAdmin && (
                        <section>
                            <SectionTitle
                                icon={ScrollText}
                                action={
                                    <Link to="/admin/logs" className="inline-flex items-center gap-1 text-xs text-brandink hover:underline">
                                        Full audit log <ArrowRight className="h-3 w-3" />
                                    </Link>
                                }
                            >
                                Recent activity
                            </SectionTitle>
                            {data.recent.length === 0 ? (
                                <div className="card p-5 text-sm text-subtle">No activity yet.</div>
                            ) : (
                                <ul className="card divide-y divide-line/60">
                                    {data.recent.map((row) => (
                                        <li key={row.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-4 py-2.5 text-sm">
                                            <span className="w-24 shrink-0 text-xs text-subtle" title={row.created_at}>
                                                {formatRelative(row.created_at)}
                                            </span>
                                            <span className="font-medium text-fg">{row.username}</span>
                                            <span className="text-xs uppercase tracking-wider text-brandink">{actionLabel(row.action)}</span>
                                            {row.cheque_number != null && (
                                                <span className="font-mono text-xs text-muted">#{row.cheque_number}</span>
                                            )}
                                            {row.description && <span className="min-w-0 flex-1 truncate text-muted">{row.description}</span>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}
                </div>
            )}
        </div>
    );
}
