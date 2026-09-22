import { useCallback, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { X, FilePlus2, Calculator, Save } from 'lucide-react';
import { LddapApi, PayeeApi, toApiError } from '../lib/api';
import type { Lddap, LddapDraft, LddapOptions, Payee } from '../lib/types';
import { formatMoney } from '../lib/format';
import { WTAX_RATES, VAT_RATES, withheld, sum } from '../lib/tax';
import { Alert, Spinner } from './ui';
import PayeePicker from './PayeePicker';

interface Props {
    /**
     * The record to edit. Given, the dialog is **Edit LDDAP Record**: every field pre-filled
     * with the saved values and the save going to `PUT /lddaps/{id}`. Absent, it registers a
     * new one.
     */
    lddap?: Lddap | null;
    onClose: () => void;
    /** The registered or edited record. */
    onSaved: (lddap: Lddap) => void;
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

const zeroes = (rates: readonly string[]): Record<string, string> =>
    Object.fromEntries(rates.map((r) => [r, '0']));

const emptyDraft = (): LddapDraft => ({
    lddap_no: '',
    nca_no: '',
    orb_no: '',
    dv_no: '',
    nature_of_payment: '',
    obj_no: '',
    unit_id: '',
    check_date: today(),
    payee: null,
    payee_account_id: null,
    acic_ref: '',
    gross_amount: '',
    wtax: zeroes(WTAX_RATES),
    vat: zeroes(VAT_RATES),
    retention: '0',
    liquidated_damages: '0',
    advance_payment: '0',
    fwd_to_lbp_at: '',
    date_loaded: '',
    note: '',
    remarks: '',
});

/** A saved amount as the form's number inputs hold it: "0" for nothing, else as stored. */
const amountOf = (value: string | number | null | undefined): string =>
    value == null || Number(value) === 0 ? '0' : String(value);

/**
 * The saved record as a draft — every field the form has, pre-filled. The payee itself
 * (with its accounts, for the account select) is fetched separately, by id.
 */
function draftFrom(lddap: Lddap): LddapDraft {
    const rates = (saved: Record<string, string> | undefined, all: readonly string[]) =>
        Object.fromEntries(all.map((r) => [r, amountOf(saved?.[r])]));

    return {
        lddap_no: lddap.lddap_no,
        nca_no: lddap.nca_no ?? '',
        orb_no: lddap.orb_no ?? '',
        dv_no: lddap.dv_no ?? '',
        nature_of_payment: lddap.nature_of_payment ?? '',
        obj_no: lddap.obj_no ?? '',
        unit_id: lddap.unit_id != null ? String(lddap.unit_id) : '',
        check_date: lddap.check_date ?? today(),
        payee: null,
        payee_account_id: lddap.payee_account_id ?? null,
        acic_ref: lddap.acic_ref ?? '',
        gross_amount: lddap.gross_amount != null ? String(lddap.gross_amount) : String(lddap.amount ?? ''),
        wtax: rates(lddap.wtax, WTAX_RATES),
        vat: rates(lddap.vat, VAT_RATES),
        retention: amountOf(lddap.retention),
        liquidated_damages: amountOf(lddap.liquidated_damages),
        advance_payment: amountOf(lddap.advance_payment),
        fwd_to_lbp_at: lddap.fwd_to_lbp_at ?? '',
        date_loaded: lddap.date_loaded ?? '',
        note: lddap.note ?? '',
        remarks: lddap.remarks ?? '',
    };
}

/** One labelled field. The error, when the server reports one, sits beneath. */
function Field({
    label,
    htmlFor,
    error,
    optional = false,
    className = '',
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string;
    optional?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={className}>
            <label htmlFor={htmlFor} className="label !mb-1">
                {label}
                {optional && <span className="ml-1 normal-case tracking-normal text-subtle">(optional)</span>}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-danger-fg">{error}</p>}
        </div>
    );
}

/** A titled group of fields. */
function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
    return (
        <section className="rounded-xs border border-line bg-well p-4">
            <h3 className="font-display text-xs font-semibold uppercase tracking-widest text-brandink">{title}</h3>
            {hint && <p className="mt-1 text-xs text-muted">{hint}</p>}
            <div className="mt-3">{children}</div>
        </section>
    );
}

/**
 * A row of rate buttons, each with its amount beneath. Clicking a rate computes the
 * withholding for it from the gross and marks the rate active, so a later change to the gross
 * recomputes it. Typing in the amount keeps the value but drops the rate from the active set —
 * a manual figure is the user's, and a gross change must not silently overwrite it.
 */
function RateGrid({
    idPrefix,
    rates,
    amounts,
    active,
    gross,
    onCompute,
    onEdit,
}: {
    idPrefix: string;
    rates: readonly string[];
    amounts: Record<string, string>;
    active: Set<string>;
    gross: string;
    onCompute: (rate: string) => void;
    onEdit: (rate: string, value: string) => void;
}) {
    const hasGross = Number(gross) > 0;
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            {rates.map((rate) => {
                const isActive = active.has(rate);
                return (
                    <div key={rate}>
                        <button
                            type="button"
                            className={`btn w-full !px-2 !py-1.5 ${isActive ? 'btn-primary' : 'btn-outline'}`}
                            onClick={() => onCompute(rate)}
                            disabled={!hasGross}
                            title={
                                hasGross
                                    ? `Compute (gross ÷ 1.12) × ${rate}`
                                    : 'Enter the gross amount first'
                            }
                            aria-pressed={isActive}
                        >
                            <Calculator className="h-3 w-3" />
                            {rate}
                        </button>
                        <input
                            id={`${idPrefix}-${rate}`}
                            type="number"
                            step="0.01"
                            min="0"
                            className="field mt-1.5 !py-1.5 text-right font-mono text-sm"
                            value={amounts[rate] ?? '0'}
                            onChange={(e) => onEdit(rate, e.target.value)}
                            aria-label={`Amount at ${rate}`}
                        />
                    </div>
                );
            })}
        </div>
    );
}

/**
 * "Register LDDAP record" — one LDDAP-ADA document, registered without a check number.
 *
 * The record goes out for routing first; its check number is added from the LDDAP table once
 * it is back ("Return from routing", then "Add Check Number"). Nothing here touches the check
 * series, and there is exactly one record per submission. The LDDAP number is unique across
 * the register: a duplicate is refused by name.
 *
 * Money is entered as gross plus what comes off it — withholding tax, VAT, deductions — and
 * the net payable is derived and shown, then stored by the server as `amount`.
 */
export default function LddapRegisterModal({ lddap = null, onClose, onSaved }: Props) {
    const editing = lddap !== null;
    const [draft, setDraft] = useState<LddapDraft>(() => (lddap ? draftFrom(lddap) : emptyDraft()));
    const [options, setOptions] = useState<LddapOptions | null>(null);
    // Edit only: the saved payee, with its accounts, on its way in.
    const [payeeLoading, setPayeeLoading] = useState(editing && lddap?.payee_id != null);
    // Rates whose amounts were computed and should follow the gross.
    const [activeWtax, setActiveWtax] = useState<Set<string>>(new Set());
    const [activeVat, setActiveVat] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    const load = useCallback(async () => {
        try {
            setOptions(await LddapApi.options());
        } catch (err) {
            setError(toApiError(err).message);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    // Editing: show the saved payee and account, with the picker still there to change them.
    useEffect(() => {
        if (!lddap?.payee_id) return;
        const payeeId = lddap.payee_id;
        const accountId = lddap.payee_account_id ?? null;
        let active = true;
        PayeeApi.get(payeeId)
            .then((payee) => {
                if (!active) return;
                setDraft((prev) => ({
                    ...prev,
                    payee,
                    payee_account_id: payee.accounts.some((a) => a.id === accountId) ? accountId : null,
                }));
            })
            .catch((err) => {
                if (active) setError(toApiError(err).message);
            })
            .finally(() => {
                if (active) setPayeeLoading(false);
            });
        return () => {
            active = false;
        };
    }, [lddap?.payee_id, lddap?.payee_account_id]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape' && !busy) onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    function update(patch: Partial<LddapDraft>) {
        setDraft((prev) => ({ ...prev, ...patch }));
    }

    /** Picking a payee: a lone account is chosen for them; several leave the select to them. */
    function setPayee(payee: Payee | null) {
        update({
            payee,
            payee_account_id: payee && payee.accounts.length === 1 ? payee.accounts[0].id : null,
        });
    }

    /** A gross change recomputes every active rate, in both grids. */
    function setGross(value: string) {
        setDraft((prev) => {
            const wtax = { ...prev.wtax };
            const vat = { ...prev.vat };
            activeWtax.forEach((r) => (wtax[r] = withheld(value, r)));
            activeVat.forEach((r) => (vat[r] = withheld(value, r)));
            return { ...prev, gross_amount: value, wtax, vat };
        });
    }

    function computeWtax(rate: string) {
        setActiveWtax((s) => new Set(s).add(rate));
        setDraft((prev) => ({ ...prev, wtax: { ...prev.wtax, [rate]: withheld(prev.gross_amount, rate) } }));
    }

    function computeVat(rate: string) {
        setActiveVat((s) => new Set(s).add(rate));
        setDraft((prev) => ({ ...prev, vat: { ...prev.vat, [rate]: withheld(prev.gross_amount, rate) } }));
    }

    function editWtax(rate: string, value: string) {
        setActiveWtax((s) => {
            const next = new Set(s);
            next.delete(rate);
            return next;
        });
        setDraft((prev) => ({ ...prev, wtax: { ...prev.wtax, [rate]: value } }));
    }

    function editVat(rate: string, value: string) {
        setActiveVat((s) => {
            const next = new Set(s);
            next.delete(rate);
            return next;
        });
        setDraft((prev) => ({ ...prev, vat: { ...prev.vat, [rate]: value } }));
    }

    /** The error the server reported for a field, if any. */
    function fieldError(field: string): string | undefined {
        return fieldErrors[field]?.[0];
    }

    // The net payable, live: gross less everything withheld and deducted.
    const withheldTotal = useMemo(
        () =>
            sum([
                ...Object.values(draft.wtax),
                ...Object.values(draft.vat),
                draft.retention,
                draft.liquidated_damages,
                draft.advance_payment,
            ]),
        [draft.wtax, draft.vat, draft.retention, draft.liquidated_damages, draft.advance_payment],
    );
    const net = useMemo(
        () => sum([draft.gross_amount || '0', `-${withheldTotal}`]),
        [draft.gross_amount, withheldTotal],
    );
    const netNegative = Number(net) <= 0 && Number(draft.gross_amount) > 0;

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        setFieldErrors({});

        if (draft.payee === null) {
            setFieldErrors({ payee_id: ['Choose a registered payee.'] });
            setError('Choose a registered payee.');
            return;
        }
        if (draft.payee_account_id === null) {
            setFieldErrors({ payee_account_id: ['Choose the account the payment goes to.'] });
            setError('Choose the account the payment goes to.');
            return;
        }

        setBusy(true);
        try {
            onSaved(editing && lddap ? await LddapApi.edit(lddap.id, draft) : await LddapApi.register(draft));
        } catch (err) {
            const apiErr = toApiError(err);
            setFieldErrors(apiErr.errors);
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
            aria-labelledby="lddap-register-title"
        >
            <div
                className="card flex max-h-[92vh] w-full max-w-4xl flex-col overflow-y-auto p-6"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2
                            id="lddap-register-title"
                            className="font-display text-2xl font-extrabold tracking-tight text-fg"
                        >
                            {editing ? 'Edit LDDAP Record' : 'Register LDDAP Record'}
                        </h2>
                        {editing && lddap && (
                            <p className="mt-1 text-sm text-muted">
                                <span className="font-display font-bold text-fg">{lddap.lddap_no}</span>
                                {' · '}
                                {lddap.status_label ?? lddap.status}
                            </p>
                        )}
                    </div>
                    <button className="btn btn-ghost !px-2" onClick={onClose} disabled={busy} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <p className="mb-4 text-sm text-muted">
                    {editing
                        ? 'Every change is kept on the record with who made it and when. The check number is not part of this form — it is only set by Assign LDDAP to ACIC. The LDDAP number must not be on any other record.'
                        : 'One record at a time. It goes out for routing without a check number — the number is added from the LDDAP table once the record is back. The LDDAP number must not have been used before.'}
                </p>

                {options === null || payeeLoading ? (
                    <div className="py-6">
                        <Spinner />
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-4">
                        {/* ---- Details ---------------------------------------------------- */}
                        <Section title="Details">
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <Field label="LDDAP Number" htmlFor="reg-lddap-no" error={fieldError('lddap_no')}>
                                    <input
                                        id="reg-lddap-no"
                                        className={`field !py-1.5 ${fieldError('lddap_no') ? '!border-danger/60' : ''}`}
                                        value={draft.lddap_no}
                                        onChange={(e) => update({ lddap_no: e.target.value })}
                                        placeholder="LDDAP 09-03403"
                                        maxLength={100}
                                        autoFocus
                                        required
                                    />
                                </Field>
                                <Field label="NCA Number" htmlFor="reg-nca-no" error={fieldError('nca_no')}>
                                    <input
                                        id="reg-nca-no"
                                        className="field !py-1.5"
                                        value={draft.nca_no}
                                        onChange={(e) => update({ nca_no: e.target.value })}
                                        maxLength={100}
                                        required
                                    />
                                </Field>
                                <Field label="ORB Number" htmlFor="reg-orb-no" error={fieldError('orb_no')}>
                                    <input
                                        id="reg-orb-no"
                                        className="field !py-1.5"
                                        value={draft.orb_no}
                                        onChange={(e) => update({ orb_no: e.target.value })}
                                        maxLength={100}
                                        required
                                    />
                                </Field>
                                <Field label="DV Number" htmlFor="reg-dv-no" error={fieldError('dv_no')}>
                                    <input
                                        id="reg-dv-no"
                                        className="field !py-1.5"
                                        value={draft.dv_no}
                                        onChange={(e) => update({ dv_no: e.target.value })}
                                        maxLength={100}
                                        required
                                    />
                                </Field>
                                <Field label="Nature of Payment" htmlFor="reg-nature" error={fieldError('nature_of_payment')}>
                                    <select
                                        id="reg-nature"
                                        className="field !py-1.5"
                                        value={draft.nature_of_payment}
                                        onChange={(e) => update({ nature_of_payment: e.target.value })}
                                        required
                                    >
                                        <option value="">Choose…</option>
                                        {options.natures.map((n) => (
                                            <option key={n.value} value={n.value}>
                                                {n.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field label="UACS Object Code" htmlFor="reg-obj-no" error={fieldError('obj_no')} optional>
                                    <input
                                        id="reg-obj-no"
                                        className="field !py-1.5 font-mono"
                                        value={draft.obj_no}
                                        onChange={(e) => update({ obj_no: e.target.value })}
                                        placeholder="5021304001"
                                        maxLength={100}
                                    />
                                </Field>
                                <Field label="Unit Name" htmlFor="reg-unit" error={fieldError('unit_id')}>
                                    <select
                                        id="reg-unit"
                                        className="field !py-1.5"
                                        value={draft.unit_id}
                                        onChange={(e) => update({ unit_id: e.target.value })}
                                        required
                                    >
                                        <option value="">
                                            {options.units.length === 0 ? 'No units registered' : 'Choose…'}
                                        </option>
                                        {options.units.map((u) => (
                                            <option key={u.id} value={u.id}>
                                                {u.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field label="Date Issued" htmlFor="reg-date" error={fieldError('check_date')}>
                                    <input
                                        id="reg-date"
                                        type="date"
                                        className="field !py-1.5"
                                        value={draft.check_date}
                                        onChange={(e) => update({ check_date: e.target.value })}
                                        required
                                    />
                                </Field>
                            </div>
                        </Section>

                        {/* ---- Payee ------------------------------------------------------ */}
                        <Section title="Payee" hint="Search the register, select the payee, then the account the payment goes to.">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Payee" htmlFor="reg-payee" error={fieldError('payee_id')} className="sm:col-span-2">
                                    <PayeePicker
                                        id="reg-payee"
                                        label="Payee"
                                        value={draft.payee}
                                        onChange={setPayee}
                                        error={fieldError('payee_id')}
                                        disabled={busy}
                                    />
                                </Field>
                                <Field label="Account Number" htmlFor="reg-account" error={fieldError('payee_account_id')}>
                                    <select
                                        id="reg-account"
                                        className="field !py-1.5 font-mono"
                                        value={draft.payee_account_id ?? ''}
                                        onChange={(e) =>
                                            update({ payee_account_id: e.target.value ? Number(e.target.value) : null })
                                        }
                                        disabled={!draft.payee}
                                        required
                                    >
                                        <option value="">
                                            {!draft.payee
                                                ? 'Select a payee first'
                                                : draft.payee.accounts.length === 0
                                                  ? 'No account on file'
                                                  : 'Choose…'}
                                        </option>
                                        {draft.payee?.accounts.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                {/* Read-only: both arrive with "Assign LDDAP to ACIC", never from
                                    this form. */}
                                <Field label="ACIC #" htmlFor="reg-acic">
                                    <input
                                        id="reg-acic"
                                        className="field !py-1.5 font-mono"
                                        value={lddap?.acic_number != null ? `#${lddap.acic_number}` : (lddap?.acic_ref ?? '')}
                                        placeholder="Assigned when put on an ACIC"
                                        disabled
                                        readOnly
                                        aria-describedby="reg-acic-hint"
                                    />
                                    <p id="reg-acic-hint" className="mt-1 text-xs text-subtle">
                                        Filled in from the ACIC the record is assigned to.
                                    </p>
                                </Field>
                                <Field label="Check #" htmlFor="reg-check-no">
                                    <input
                                        id="reg-check-no"
                                        className="field !py-1.5 font-mono"
                                        value={lddap?.check_no != null ? `#${lddap.check_no}` : ''}
                                        placeholder="Assigned when put on an ACIC"
                                        disabled
                                        readOnly
                                        aria-describedby="reg-check-hint"
                                    />
                                    <p id="reg-check-hint" className="mt-1 text-xs text-subtle">
                                        The lowest unused number in the series, taken on assignment.
                                    </p>
                                </Field>
                                <Field label="Gross Amount" htmlFor="reg-gross" error={fieldError('gross_amount')}>
                                    <input
                                        id="reg-gross"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        className="field !py-1.5 text-right font-mono"
                                        value={draft.gross_amount}
                                        onChange={(e) => setGross(e.target.value)}
                                        placeholder="0.00"
                                        required
                                    />
                                </Field>
                            </div>
                        </Section>

                        {/* ---- W/TAX ------------------------------------------------------ */}
                        <Section title="W/TAX" hint="Click a rate to compute (gross ÷ 1.12) × rate. Amounts stay editable.">
                            <RateGrid
                                idPrefix="reg-wtax"
                                rates={WTAX_RATES}
                                amounts={draft.wtax}
                                active={activeWtax}
                                gross={draft.gross_amount}
                                onCompute={computeWtax}
                                onEdit={editWtax}
                            />
                        </Section>

                        {/* ---- VAT -------------------------------------------------------- */}
                        <Section title="VAT" hint="Same rule as W/TAX: (gross ÷ 1.12) × rate.">
                            <RateGrid
                                idPrefix="reg-vat"
                                rates={VAT_RATES}
                                amounts={draft.vat}
                                active={activeVat}
                                gross={draft.gross_amount}
                                onCompute={computeVat}
                                onEdit={editVat}
                            />
                        </Section>

                        {/* ---- Deductions ------------------------------------------------- */}
                        <Section title="Deductions">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Field label="Retention" htmlFor="reg-retention" error={fieldError('retention')}>
                                    <input
                                        id="reg-retention"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="field !py-1.5 text-right font-mono"
                                        value={draft.retention}
                                        onChange={(e) => update({ retention: e.target.value })}
                                    />
                                </Field>
                                <Field label="Liquidated Damages" htmlFor="reg-ld" error={fieldError('liquidated_damages')}>
                                    <input
                                        id="reg-ld"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="field !py-1.5 text-right font-mono"
                                        value={draft.liquidated_damages}
                                        onChange={(e) => update({ liquidated_damages: e.target.value })}
                                    />
                                </Field>
                                <Field label="Advance Payment" htmlFor="reg-advance" error={fieldError('advance_payment')}>
                                    <input
                                        id="reg-advance"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="field !py-1.5 text-right font-mono"
                                        value={draft.advance_payment}
                                        onChange={(e) => update({ advance_payment: e.target.value })}
                                    />
                                </Field>
                            </div>
                        </Section>

                        {/* ---- Net --------------------------------------------------------- */}
                        <div
                            className={`flex items-baseline justify-between gap-4 rounded-xs border px-4 py-3 ${
                                netNegative ? 'border-danger/40 bg-danger/10' : 'border-line bg-well'
                            }`}
                        >
                            <span className="text-xs font-semibold uppercase tracking-wider text-subtle">
                                Net payable
                                <span className="ml-2 normal-case tracking-normal text-subtle">
                                    gross {formatMoney(draft.gross_amount || '0')} − withheld {formatMoney(withheldTotal)}
                                </span>
                            </span>
                            <span className={`font-mono text-lg font-bold ${netNegative ? 'text-danger-fg' : 'text-fg'}`}>
                                {formatMoney(net)}
                            </span>
                        </div>

                        {/* ---- Dates ------------------------------------------------------ */}
                        <Section title="Dates">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="FWD to LBP" htmlFor="reg-fwd" error={fieldError('fwd_to_lbp_at')} optional>
                                    <input
                                        id="reg-fwd"
                                        type="date"
                                        className="field !py-1.5"
                                        value={draft.fwd_to_lbp_at}
                                        onChange={(e) => update({ fwd_to_lbp_at: e.target.value })}
                                    />
                                </Field>
                                <Field label="Date Loaded" htmlFor="reg-loaded" error={fieldError('date_loaded')} optional>
                                    <input
                                        id="reg-loaded"
                                        type="date"
                                        className="field !py-1.5"
                                        value={draft.date_loaded}
                                        onChange={(e) => update({ date_loaded: e.target.value })}
                                    />
                                </Field>
                            </div>
                        </Section>

                        {/* ---- Notes ------------------------------------------------------ */}
                        <Section title="Notes">
                            <div className="grid gap-3">
                                <Field label="Note" htmlFor="reg-note" error={fieldError('note')} optional>
                                    <textarea
                                        id="reg-note"
                                        className="field min-h-20 !py-1.5"
                                        value={draft.note}
                                        onChange={(e) => update({ note: e.target.value })}
                                        maxLength={2000}
                                    />
                                </Field>
                                <Field label="Remarks" htmlFor="reg-remarks" error={fieldError('remarks')} optional>
                                    <input
                                        id="reg-remarks"
                                        className="field !py-1.5"
                                        value={draft.remarks}
                                        onChange={(e) => update({ remarks: e.target.value })}
                                        maxLength={255}
                                    />
                                </Field>
                            </div>
                        </Section>

                        {error && <Alert kind="error">{error}</Alert>}

                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>
                                Cancel
                            </button>
                            <button type="submit" className="btn btn-primary" disabled={busy || netNegative}>
                                {editing ? <Save className="h-4 w-4" /> : <FilePlus2 className="h-4 w-4" />}
                                {busy
                                    ? editing
                                        ? 'Saving…'
                                        : 'Registering…'
                                    : editing
                                      ? 'Save Changes'
                                      : 'Register LDDAP'}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
