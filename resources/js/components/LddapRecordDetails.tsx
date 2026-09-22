import type { ReactNode } from 'react';
import type { Lddap } from '../lib/types';
import { formatDate, formatDateTime, formatMoney } from '../lib/format';
import { VAT_RATES, WTAX_RATES, sum } from '../lib/tax';

/** One label · value line of a record, as the LDDAP dialogs lay them out. */
export function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 border-b border-line/60 py-2.5 last:border-0">
            <span className="text-xs font-semibold uppercase tracking-wider text-subtle">{label}</span>
            <span className="text-right text-sm text-fg">{value}</span>
        </div>
    );
}

/** A titled block, laid out as the register form's sections are. */
function Section({ title, aside, children }: { title: string; aside?: ReactNode; children: ReactNode }) {
    return (
        <section className="rounded-xs border border-line bg-well p-4">
            <div className="flex items-baseline justify-between gap-4">
                <h3 className="font-display text-xs font-semibold uppercase tracking-widest text-brandink">{title}</h3>
                {aside}
            </div>
            <div className="mt-2">{children}</div>
        </section>
    );
}

/**
 * Every rate of a withholding, each with its amount — the register form's rate grid, read-only.
 * A rate with nothing withheld shows a dash so the ones that apply stand out.
 */
function RateGrid({ rates, amounts, label }: { rates: readonly string[]; amounts: Record<string, string>; label: string }) {
    return (
        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
            {rates.map((rate) => {
                const amount = amounts[rate] ?? '0';
                const applies = Number(amount) > 0;
                return (
                    <div
                        key={rate}
                        className={`rounded-xs border px-2.5 py-2 ${
                            applies ? 'border-brand-400/50 bg-brand-500/10' : 'border-line/60'
                        }`}
                    >
                        <dt className="text-[11px] font-semibold uppercase tracking-wider text-subtle">
                            {label} {Math.round(Number(rate) * 100)}%
                        </dt>
                        <dd className={`mt-0.5 text-right font-mono text-sm ${applies ? 'text-fg' : 'text-subtle'}`}>
                            {applies ? formatMoney(amount) : '—'}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

interface Props {
    lddap: Lddap;
    /**
     * Only what a sign-off turns on: the record's number and voucher, the payee, and the money —
     * gross, the withholdings and deductions that actually apply, net. The full record is a
     * toggle away in the dialog that asks for this.
     */
    compact?: boolean;
}

/** The (rate, amount) pairs of a withholding that are actually withheld. */
function applied(amounts: Record<string, string>): [string, string][] {
    return Object.entries(amounts).filter(([, v]) => Number(v) > 0);
}

/**
 * Everything an LDDAP was registered with, in the register form's own sections — **Details**,
 * **Payee**, **W/TAX**, **VAT**, **Deductions**, then **Net payable** — and any notes. Shared by
 * the record's detail dialog and the admin's Action dialog, so what is being signed off is the
 * whole record, not a summary of it. `compact` trims it to what the sign-off needs.
 */
export default function LddapRecordDetails({ lddap, compact = false }: Props) {
    const wtax = lddap.wtax ?? {};
    const vat = lddap.vat ?? {};
    const gross = lddap.gross_amount ?? lddap.amount;
    const wtaxTotal = sum(Object.values(wtax));
    const vatTotal = sum(Object.values(vat));
    const deductions = sum([lddap.retention ?? 0, lddap.liquidated_damages ?? 0, lddap.advance_payment ?? 0]);
    const withheld = sum([wtaxTotal, vatTotal, deductions]);
    const netNegative = Number(lddap.amount) < 0;

    const net = (
        <div
            className={`flex items-baseline justify-between gap-4 rounded-xs border px-4 py-3 ${
                netNegative ? 'border-danger/40 bg-danger/10' : 'border-brand-400/50 bg-brand-500/10'
            }`}
        >
            <span className="text-xs font-semibold uppercase tracking-wider text-subtle">
                Net payable
                <span className="ml-2 block normal-case tracking-normal text-subtle sm:inline">
                    gross {formatMoney(gross)} − withheld {formatMoney(withheld)}
                </span>
            </span>
            <span className={`font-mono text-lg font-bold ${netNegative ? 'text-danger-fg' : 'text-fg'}`}>
                {formatMoney(lddap.amount)}
            </span>
        </div>
    );

    if (compact) {
        const money = (v: string | number) => <span className="font-mono">{formatMoney(v)}</span>;
        const less = (v: string | number) => <span className="font-mono">− {formatMoney(v)}</span>;
        const lines: ReactNode[] = [
            ...applied(wtax).map(([rate, v]) => <Row key={`w-${rate}`} label={`W/Tax ${Math.round(Number(rate) * 100)}%`} value={less(v)} />),
            ...applied(vat).map(([rate, v]) => <Row key={`v-${rate}`} label={`VAT ${Math.round(Number(rate) * 100)}%`} value={less(v)} />),
            ...(Number(lddap.retention) > 0 ? [<Row key="ret" label="Retention" value={less(lddap.retention ?? 0)} />] : []),
            ...(Number(lddap.liquidated_damages) > 0 ? [<Row key="ld" label="Liquidated damages" value={less(lddap.liquidated_damages ?? 0)} />] : []),
            ...(Number(lddap.advance_payment) > 0 ? [<Row key="adv" label="Advance payment" value={less(lddap.advance_payment ?? 0)} />] : []),
        ];

        return (
            <div className="space-y-3">
                <Section title="LDDAP">
                    <Row label="LDDAP No." value={<span className="font-display font-bold">{lddap.lddap_no}</span>} />
                    <Row label="DV No." value={lddap.dv_no ?? '—'} />
                    <Row label="Nature of payment" value={lddap.nature_of_payment_label ?? '—'} />
                    <Row label="Unit" value={lddap.unit_name ?? '—'} />
                    <Row label="Date issued" value={formatDate(lddap.check_date)} />
                </Section>

                <Section title="Payee">
                    <Row
                        label="Payee"
                        value={
                            <>
                                {lddap.payee_name ?? '—'}
                                {lddap.payee_account_no && (
                                    <span className="mt-0.5 block font-mono text-xs text-muted">
                                        {lddap.payee_account_no}
                                        {lddap.payee_bank ? ` – ${lddap.payee_bank}` : ''}
                                    </span>
                                )}
                            </>
                        }
                    />
                </Section>

                <Section title="Amount">
                    <Row label="Gross amount" value={money(gross)} />
                    {lines.length > 0 ? (
                        lines
                    ) : (
                        <p className="py-2 text-xs text-subtle">No withholding or deductions — the net is the gross.</p>
                    )}
                </Section>

                {net}

                {lddap.note && (
                    <Section title="Note">
                        <p className="whitespace-pre-wrap text-sm text-fg">{lddap.note}</p>
                    </Section>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {/* ---- Details ------------------------------------------------------- */}
            <Section title="LDDAP Details">
                <Row label="LDDAP No." value={<span className="font-display font-bold">{lddap.lddap_no}</span>} />
                <Row label="NCA No." value={lddap.nca_no ?? '—'} />
                <Row label="ORB No." value={lddap.orb_no ?? '—'} />
                <Row label="DV No." value={lddap.dv_no ?? '—'} />
                <Row label="Nature of payment" value={lddap.nature_of_payment_label ?? '—'} />
                <Row label="UACS object code" value={<span className="font-mono">{lddap.obj_no ?? '—'}</span>} />
                <Row label="Unit" value={lddap.unit_name ?? '—'} />
                {lddap.acic_ref && <Row label="ACIC # (on form)" value={<span className="font-mono">{lddap.acic_ref}</span>} />}
                <Row label="Date issued" value={formatDate(lddap.check_date)} />
                {lddap.fwd_to_lbp_at && <Row label="FWD to LBP" value={formatDate(lddap.fwd_to_lbp_at)} />}
                {lddap.date_loaded && <Row label="Date loaded" value={formatDate(lddap.date_loaded)} />}
                <Row
                    label="Registered by"
                    value={`${lddap.used_by?.name ?? '—'} · ${formatDateTime(lddap.used_at)}`}
                />
            </Section>

            {/* ---- Payee --------------------------------------------------------- */}
            <Section title="Payee">
                <Row label="Payee name" value={lddap.payee_name ?? '—'} />
                <Row label="Account No." value={<span className="font-mono">{lddap.payee_account_no ?? '—'}</span>} />
                <Row label="Bank" value={lddap.payee_bank ?? '—'} />
                <Row label="Gross amount" value={<span className="font-mono">{formatMoney(gross)}</span>} />
            </Section>

            {/* ---- W/TAX --------------------------------------------------------- */}
            <Section
                title="W/TAX"
                aside={
                    <span className="text-xs text-muted">
                        total <span className="font-mono text-fg">{formatMoney(wtaxTotal)}</span>
                    </span>
                }
            >
                <RateGrid rates={WTAX_RATES} amounts={wtax} label="W/Tax" />
            </Section>

            {/* ---- VAT ----------------------------------------------------------- */}
            <Section
                title="VAT"
                aside={
                    <span className="text-xs text-muted">
                        total <span className="font-mono text-fg">{formatMoney(vatTotal)}</span>
                    </span>
                }
            >
                <RateGrid rates={VAT_RATES} amounts={vat} label="VAT" />
            </Section>

            {/* ---- Deductions ---------------------------------------------------- */}
            <Section
                title="Deductions"
                aside={
                    <span className="text-xs text-muted">
                        total <span className="font-mono text-fg">{formatMoney(deductions)}</span>
                    </span>
                }
            >
                <Row label="Retention" value={<span className="font-mono">{formatMoney(lddap.retention ?? 0)}</span>} />
                <Row
                    label="Liquidated damages"
                    value={<span className="font-mono">{formatMoney(lddap.liquidated_damages ?? 0)}</span>}
                />
                <Row
                    label="Advance payment"
                    value={<span className="font-mono">{formatMoney(lddap.advance_payment ?? 0)}</span>}
                />
            </Section>

            {/* ---- Net ----------------------------------------------------------- */}
            {net}

            {(lddap.note || lddap.remarks) && (
                <Section title="Notes">
                    {lddap.note && <p className="whitespace-pre-wrap text-sm text-fg">{lddap.note}</p>}
                    {lddap.remarks && (
                        <p className="mt-2 text-xs text-muted">
                            <span className="uppercase tracking-wider text-subtle">Remarks · </span>
                            {lddap.remarks}
                        </p>
                    )}
                </Section>
            )}
        </div>
    );
}
