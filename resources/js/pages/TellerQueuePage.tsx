import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Landmark, HandCoins, Undo2, Inbox, CheckCircle2, RotateCcw, Filter, X } from 'lucide-react';
import { AcicTellerApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import type { Acic, AcicTellerStatus, TellerQueue } from '../lib/types';
import { PageHeader, Spinner, Alert, EmptyState } from '../components/ui';
import { formatDateTime, formatMoney } from '../lib/format';

/** The dashboard's five lists, in the order a teller works through them. */
const TABS: { key: keyof Omit<TellerQueue, 'bank_name'>; label: string; status: AcicTellerStatus }[] = [
    { key: 'pending', label: 'Pending', status: 'pending' },
    { key: 'accepted', label: 'My Accepted', status: 'accepted_by_teller' },
    { key: 'forwarded', label: 'Forwarded to Land Bank', status: 'forwarded_to_land_bank' },
    { key: 'returned', label: 'Returned by Bank', status: 'returned_by_bank' },
    { key: 'completed', label: 'Completed', status: 'completed' },
];

/** Which step the teller is taking on an ACIC. */
type Step = 'forward' | 'returned' | 'complete' | 'return-admin';

const EMPTY: TellerQueue = { pending: [], accepted: [], forwarded: [], returned: [], completed: [], bank_name: 'Land Bank of the Philippines' };

function nowLocal(): string {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

function Field({ label, htmlFor, optional = false, children }: { label: string; htmlFor: string; optional?: boolean; children: ReactNode }) {
    return (
        <div>
            <label htmlFor={htmlFor} className="label !mb-1">
                {label}
                {optional && <span className="ml-1 normal-case tracking-normal text-subtle">(optional)</span>}
            </label>
            {children}
        </div>
    );
}

/** The type badge, shown wherever ACICs are listed. */
function TypeBadge({ type }: { type?: string | null }) {
    if (!type) return <span className="text-subtle">—</span>;
    const cheque = type === 'cheque';
    return (
        <span className={`inline-flex items-center rounded-xs border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider ${
            cheque ? 'border-brand-400/50 bg-brand-500/10 text-brandink' : 'border-purple-400/50 bg-purple-400/10 text-purple-300'
        }`}>
            {cheque ? 'Cheque' : 'LDDAP'}
        </span>
    );
}

/**
 * The teller's dashboard for the whole bank journey — the same for cheque and LDDAP ACICs.
 *
 * **Pending** is everyone's: the first teller to accept an ACIC claims it, and it leaves the
 * other tellers' lists. The rest are the viewer's own work (an admin sees every teller's).
 */
export default function TellerQueuePage() {
    const { user, isAdmin } = useAuth();
    const [queue, setQueue] = useState<TellerQueue>(EMPTY);
    const [tab, setTab] = useState<(typeof TABS)[number]['key']>('pending');
    const [type, setType] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState<number | null>(null);

    const [acting, setActing] = useState<{ acic: Acic; step: Step } | null>(null);
    const [at, setAt] = useState(nowLocal());
    const [reference, setReference] = useState('');
    // When the ACIC went over the counter, for a teller closing it in one visit.
    const [handed, setHanded] = useState(nowLocal());
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');

    const load = useCallback(async () => {
        try {
            setQueue(await AcicTellerApi.queue({ type: type || undefined, from: from || undefined, to: to || undefined }));
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [type, from, to]);

    useEffect(() => {
        void load();
    }, [load]);

    function open(acic: Acic, step: Step) {
        setActing({ acic, step });
        setAt(nowLocal());
        setHanded(nowLocal());
        setReference('');
        setReason('');
        setNote('');
        setError('');
    }

    async function accept(acic: Acic) {
        setBusy(acic.id);
        setError('');
        setNotice('');
        try {
            await AcicTellerApi.accept(acic.id, acic.teller_status ?? undefined);
            setNotice(`You accepted ACIC #${acic.acic_number}.`);
        } catch (err) {
            const apiErr = toApiError(err);
            // Someone else got there first; the list is already out of date.
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setBusy(null);
            await load();
        }
    }

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!acting) return;
        const { acic, step } = acting;
        const expected = acic.teller_status ?? undefined;
        setBusy(acic.id);
        setError('');
        try {
            if (step === 'forward') {
                await AcicTellerApi.forwardToLandBank(acic.id, {
                    forwarded_at: at,
                    transmittal_no: reference.trim() || undefined,
                    note: note.trim() || undefined,
                    expected_status: expected,
                });
                setNotice(`ACIC #${acic.acic_number} forwarded to ${queue.bank_name}.`);
            } else if (step === 'returned') {
                await AcicTellerApi.returnedByBank(acic.id, {
                    returned_at: at,
                    reason: reason.trim(),
                    expected_status: expected,
                });
                setNotice(`ACIC #${acic.acic_number} marked as returned by the bank.`);
            } else if (step === 'complete') {
                await AcicTellerApi.complete(acic.id, {
                    credited_at: at,
                    // Only needed when the ACIC was not lodged through its own step.
                    handed_to_bank_at: acic.forwarded_to_land_bank_at ? undefined : handed,
                    bank_confirmation_no: reference.trim(),
                    note: note.trim() || undefined,
                    expected_status: expected,
                });
                setNotice(`ACIC #${acic.acic_number} completed.`);
            } else {
                await AcicTellerApi.returnToAdmin(acic.id, reason.trim(), expected);
                setNotice(`ACIC #${acic.acic_number} returned to the admin.`);
            }
            setActing(null);
            await load();
        } catch (err) {
            const apiErr = toApiError(err);
            setError(Object.values(apiErr.errors)[0]?.[0] ?? apiErr.message);
        } finally {
            setBusy(null);
        }
    }

    const rows = queue[tab];
    const mine = (acic: Acic) => isAdmin || acic.accepted_by?.id === user?.id;

    const TITLES: Record<Step, string> = {
        forward: 'Forward to Land Bank',
        returned: 'Returned by Bank',
        complete: 'Mark as Completed',
        'return-admin': 'Return to Admin',
    };

    return (
        <div>
            <PageHeader
                title="Deposit queue"
                subtitle={
                    isAdmin
                        ? 'Every ACIC with the tellers, and where each one stands with the bank.'
                        : 'ACICs forwarded for deposit. The first teller to accept one takes it.'
                }
            />

            {notice && (
                <div className="mb-4">
                    <Alert kind="success">{notice}</Alert>
                </div>
            )}
            {error && (
                <div className="mb-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}

            {/* Filters — type and the date it was forwarded. */}
            <div className="card mb-4 grid grid-cols-1 gap-3 p-4 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                <Field label="Type" htmlFor="q-type">
                    <select id="q-type" className="field !py-1.5" value={type} onChange={(e) => setType(e.target.value)}>
                        <option value="">All</option>
                        <option value="cheque">Cheque</option>
                        <option value="lddap">LDDAP</option>
                    </select>
                </Field>
                <Field label="Forwarded from" htmlFor="q-from" optional>
                    <input id="q-from" type="date" className="field !py-1.5" value={from} onChange={(e) => setFrom(e.target.value)} />
                </Field>
                <Field label="Forwarded to" htmlFor="q-to" optional>
                    <input id="q-to" type="date" className="field !py-1.5" value={to} onChange={(e) => setTo(e.target.value)} />
                </Field>
                <button
                    type="button"
                    className="btn btn-ghost !py-1.5"
                    onClick={() => {
                        setType('');
                        setFrom('');
                        setTo('');
                    }}
                >
                    <X className="h-4 w-4" />
                    Clear
                </button>
            </div>

            {/* The five lists. */}
            <div className="mb-4 flex flex-wrap gap-1 border-b border-line">
                {TABS.map(({ key, label }) => (
                    <button
                        key={key}
                        onClick={() => setTab(key)}
                        className={`-mb-px border-b-2 px-4 py-2.5 font-display text-xs font-semibold uppercase tracking-widest transition-colors ${
                            tab === key ? 'border-accent-400 text-fg' : 'border-transparent text-subtle hover:text-muted'
                        }`}
                    >
                        {label}
                        {queue[key].length > 0 && <span className="ml-1.5 text-brandink">{queue[key].length}</span>}
                    </button>
                ))}
            </div>

            {loading ? (
                <Spinner />
            ) : rows.length === 0 ? (
                <EmptyState>
                    {tab === 'pending' ? 'No ACICs are waiting to be accepted.' : 'Nothing in this list.'}
                </EmptyState>
            ) : (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[62rem] text-left text-sm">
                            <thead>
                                <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                    <th className="px-4 py-3 font-semibold">ACIC #</th>
                                    <th className="px-4 py-3 font-semibold">Type</th>
                                    <th className="px-4 py-3 text-right font-semibold">Records</th>
                                    <th className="px-4 py-3 text-right font-semibold">Total</th>
                                    <th className="px-4 py-3 font-semibold">Forwarded by</th>
                                    <th className="px-4 py-3 font-semibold">Forwarded</th>
                                    <th className="px-4 py-3 font-semibold">Last note</th>
                                    <th className="px-4 py-3 text-right font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((acic) => {
                                    const total =
                                        (acic.cheques ?? []).reduce((s, c) => s + Number(c.amount ?? 0), 0) +
                                        (acic.lddaps ?? []).reduce((s, l) => s + Number(l.amount ?? 0), 0);
                                    const lastNote =
                                        acic.bank_return_reason ?? acic.land_bank_note ?? acic.forward_note ?? acic.completion_note ?? null;

                                    return (
                                        <tr key={acic.id} className="border-b border-line/60 last:border-0">
                                            <td className="px-4 py-3 font-display font-bold text-fg">#{acic.acic_number}</td>
                                            <td className="px-4 py-3">
                                                <TypeBadge type={acic.type} />
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted">{acic.total_records ?? 0}</td>
                                            <td className="px-4 py-3 text-right font-mono text-muted">{formatMoney(total)}</td>
                                            <td className="px-4 py-3 text-muted">{acic.forwarded_to_teller_by?.name ?? '—'}</td>
                                            <td className="px-4 py-3 whitespace-nowrap text-xs text-muted">
                                                {formatDateTime(acic.forwarded_to_teller_at)}
                                                {acic.accepted_by && (
                                                    <span className="mt-0.5 block text-subtle">
                                                        accepted by {acic.accepted_by.name}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 max-w-[16rem] truncate text-xs text-muted" title={lastNote ?? ''}>
                                                {lastNote ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    {acic.teller_status === 'pending' && (
                                                        <button
                                                            className="btn btn-primary !px-3 !py-1.5"
                                                            onClick={() => void accept(acic)}
                                                            disabled={busy === acic.id}
                                                        >
                                                            <HandCoins className="h-3.5 w-3.5" />
                                                            {busy === acic.id ? 'Accepting…' : 'Accept'}
                                                        </button>
                                                    )}

                                                    {acic.teller_status === 'accepted_by_teller' && mine(acic) && (
                                                        <>
                                                            <button className="btn btn-primary !px-3 !py-1.5" onClick={() => open(acic, 'complete')}>
                                                                <CheckCircle2 className="h-3.5 w-3.5" />
                                                                Mark as Completed
                                                            </button>
                                                            <button className="btn btn-outline !px-3 !py-1.5" onClick={() => open(acic, 'forward')}>
                                                                <Landmark className="h-3.5 w-3.5" />
                                                                Forward to Land Bank
                                                            </button>
                                                            <button className="btn btn-ghost !px-3 !py-1.5" onClick={() => open(acic, 'return-admin')}>
                                                                <Undo2 className="h-3.5 w-3.5" />
                                                                Return to Admin
                                                            </button>
                                                        </>
                                                    )}

                                                    {acic.teller_status === 'forwarded_to_land_bank' && mine(acic) && (
                                                        <>
                                                            <button className="btn btn-primary !px-3 !py-1.5" onClick={() => open(acic, 'complete')}>
                                                                <CheckCircle2 className="h-3.5 w-3.5" />
                                                                Mark as Completed
                                                            </button>
                                                            <button className="btn btn-outline !px-3 !py-1.5" onClick={() => open(acic, 'returned')}>
                                                                <RotateCcw className="h-3.5 w-3.5" />
                                                                Returned by Bank
                                                            </button>
                                                        </>
                                                    )}

                                                    {acic.teller_status === 'returned_by_bank' && mine(acic) && (
                                                        <>
                                                            <button className="btn btn-primary !px-3 !py-1.5" onClick={() => open(acic, 'forward')}>
                                                                <Landmark className="h-3.5 w-3.5" />
                                                                Forward again
                                                            </button>
                                                            <button className="btn btn-ghost !px-3 !py-1.5" onClick={() => open(acic, 'return-admin')}>
                                                                <Undo2 className="h-3.5 w-3.5" />
                                                                Return to Admin
                                                            </button>
                                                        </>
                                                    )}

                                                    {acic.teller_status === 'completed' && (
                                                        <span className="text-xs text-subtle">
                                                            Credited {formatDateTime(acic.credited_at)}
                                                            {acic.bank_confirmation_no ? ` · ${acic.bank_confirmation_no}` : ''}
                                                        </span>
                                                    )}

                                                    {acic.teller_status !== 'completed' && !mine(acic) && acic.teller_status !== 'pending' && (
                                                        <span className="text-xs text-subtle">
                                                            With {acic.accepted_by?.name ?? 'another teller'}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {acting && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                    onClick={() => setActing(null)}
                    role="dialog"
                    aria-modal="true"
                >
                    <form className="card w-full max-w-md space-y-3 p-6" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
                        <h2 className="font-display text-xl font-extrabold text-fg">{TITLES[acting.step]}</h2>
                        <p className="text-sm text-muted">
                            ACIC #{acting.acic.acic_number} · {acting.acic.type_label ?? '—'} ·{' '}
                            {acting.acic.total_records ?? 0} record(s)
                            {acting.step === 'returned' && ' — every record on it is flagged unless the bank named fewer.'}
                            {acting.step === 'return-admin' && ' — it goes back to the admin to fix.'}
                        </p>

                        {error && <Alert kind="error">{error}</Alert>}

                        {(acting.step === 'forward' || acting.step === 'returned' || acting.step === 'complete') && (
                            <Field
                                label={
                                    acting.step === 'forward'
                                        ? 'Date and time forwarded'
                                        : acting.step === 'returned'
                                          ? 'Date and time returned'
                                          : 'Date and time credited by the bank'
                                }
                                htmlFor="t-at"
                            >
                                <input
                                    id="t-at"
                                    type="datetime-local"
                                    className="field !py-1.5"
                                    value={at}
                                    onChange={(e) => setAt(e.target.value)}
                                    max={nowLocal()}
                                    required
                                />
                            </Field>
                        )}

                        {acting.step === 'forward' && (
                            <>
                                <Field label="Bank" htmlFor="t-bank">
                                    <input id="t-bank" className="field !py-1.5" value={queue.bank_name} disabled readOnly />
                                </Field>
                                <Field label="Transmittal / reference no." htmlFor="t-ref" optional>
                                    <input
                                        id="t-ref"
                                        className="field !py-1.5 font-mono"
                                        value={reference}
                                        onChange={(e) => setReference(e.target.value)}
                                        maxLength={255}
                                    />
                                </Field>
                            </>
                        )}

                        {acting.step === 'complete' && !acting.acic.forwarded_to_land_bank_at && (
                            <Field label="Date and time handed to bank" htmlFor="t-handed">
                                <input
                                    id="t-handed"
                                    type="datetime-local"
                                    className="field !py-1.5"
                                    value={handed}
                                    onChange={(e) => setHanded(e.target.value)}
                                    max={nowLocal()}
                                    required
                                    aria-describedby="t-handed-hint"
                                />
                                <p id="t-handed-hint" className="mt-1 text-xs text-subtle">
                                    When the ACIC went over the counter at {queue.bank_name}.
                                </p>
                            </Field>
                        )}

                        {acting.step === 'complete' && acting.acic.forwarded_to_land_bank_at && (
                            <Field label="Handed to bank" htmlFor="t-handed-ro">
                                <input
                                    id="t-handed-ro"
                                    className="field !py-1.5"
                                    value={formatDateTime(acting.acic.forwarded_to_land_bank_at)}
                                    disabled
                                    readOnly
                                />
                            </Field>
                        )}

                        {acting.step === 'complete' && (
                            <Field label="Bank confirmation / reference no." htmlFor="t-ref">
                                <input
                                    id="t-ref"
                                    className="field !py-1.5 font-mono"
                                    value={reference}
                                    onChange={(e) => setReference(e.target.value)}
                                    maxLength={255}
                                    required
                                    autoFocus
                                />
                            </Field>
                        )}

                        {(acting.step === 'returned' || acting.step === 'return-admin') && (
                            <Field label={acting.step === 'returned' ? 'Return reason / status notes' : 'Reason'} htmlFor="t-reason">
                                <textarea
                                    id="t-reason"
                                    className="field min-h-24 !py-1.5"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    minLength={3}
                                    maxLength={2000}
                                    required
                                    autoFocus
                                />
                            </Field>
                        )}

                        {(acting.step === 'forward' || acting.step === 'complete') && (
                            <Field label="Note" htmlFor="t-note" optional>
                                <textarea
                                    id="t-note"
                                    className="field min-h-20 !py-1.5"
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                    maxLength={2000}
                                />
                            </Field>
                        )}

                        <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={() => setActing(null)}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={
                                    busy !== null ||
                                    ((acting.step === 'returned' || acting.step === 'return-admin') && reason.trim().length < 3) ||
                                    (acting.step === 'complete' && reference.trim() === '')
                                }
                            >
                                <Filter className="hidden" />
                                {busy !== null ? 'Saving…' : TITLES[acting.step]}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            <p className="mt-6 flex items-center gap-2 text-xs text-subtle">
                <Inbox className="h-3.5 w-3.5" />
                Deposits go to <strong>{queue.bank_name}</strong>. Accepting an ACIC claims it — the first teller to
                accept takes it, and it leaves every other teller&rsquo;s Pending list.
            </p>
        </div>
    );
}
