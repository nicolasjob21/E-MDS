import type { ReactNode } from 'react';
import { AlertTriangle, Moon, Sun } from 'lucide-react';
import type { AcicStatus, Cheque, ChequeStatus, LddapStatus } from '../lib/types';
import { chequeStatusLabel, formatDate } from '../lib/format';
import { useTheme } from '../theme/ThemeContext';

export function ThemeToggle() {
    const { theme, toggle } = useTheme();
    const isDark = theme === 'dark';
    return (
        <button
            className="btn btn-ghost !px-2.5"
            onClick={toggle}
            aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
            title={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
        >
            {isDark ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
        </button>
    );
}

export function Spinner({ label }: { label?: string }) {
    return (
        <div className="flex items-center gap-3 text-sm text-muted">
            <span className="h-4 w-4 animate-spin rounded-full border-2 border-brand-500 border-t-transparent" />
            {label ?? 'Loading…'}
        </div>
    );
}

export function Eyebrow({ children }: { children: ReactNode }) {
    return <span className="eyebrow">{children}</span>;
}

export function PageHeader({ title, subtitle, action }: { title: string; subtitle?: string; action?: ReactNode }) {
    return (
        <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 className="font-display text-3xl font-bold tracking-tight text-fg">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
            </div>
            {action}
        </div>
    );
}

/**
 * Once the staff member has complied with a Returned remark, the stored status is still
 * `complies` — the admin's approval of the correction is what moves the cheque on. Leaving the
 * row reading "Returned" would suggest they still owe work they have already done, so the
 * badge reads from the viewer's side instead: the staff member who acted sees **On Hold**,
 * everyone else sees **Complied**, and the coral "act on me" styling gives way to cyan. Only the
 * label changes — the status value and the Returned tab it is filtered under stay put.
 * {@see LddapStatusBadge}, which does the same for the LDDAP table.
 */
export function StatusBadge({ status }: { status: ChequeStatus }) {
    const config: Record<ChequeStatus, { styles: string; dot: string }> = {
        available: { styles: 'border-brand-400/40 text-brandink bg-brand-500/10', dot: 'bg-brand-300' },
        registered: { styles: 'border-line text-muted bg-well', dot: 'bg-slate-400' },
        out_for_signature: { styles: 'border-accent-400/50 text-accent-400 bg-accent-400/10', dot: 'bg-accent-400' },
        received: { styles: 'border-brand-400/40 text-brandink bg-brand-500/10', dot: 'bg-brand-300' },
        for_acic: { styles: 'border-brand-400/40 text-brandink bg-brand-500/10', dot: 'bg-brand-300' },
        approved: { styles: 'border-success/40 text-success-fg bg-success/10', dot: 'bg-success' },
        released_to_payee: { styles: 'border-teal-400/50 text-teal-300 bg-teal-400/10', dot: 'bg-teal-400' },
        forwarded_to_teller: { styles: 'border-purple-400/50 text-purple-300 bg-purple-400/10', dot: 'bg-purple-400' },
        accepted_by_teller: { styles: 'border-indigo-400/50 text-indigo-300 bg-indigo-400/10', dot: 'bg-indigo-400' },
        forwarded_to_land_bank: { styles: 'border-blue-400/50 text-blue-300 bg-blue-400/10', dot: 'bg-blue-400' },
        returned_by_bank: { styles: 'border-amber-400/50 text-amber-400 bg-amber-400/10', dot: 'bg-amber-400' },
        completed: { styles: 'border-success/40 text-success-fg bg-success/10', dot: 'bg-success' },
        cancelled: { styles: 'border-danger/40 text-danger-fg bg-danger/10', dot: 'bg-danger' },
        voided: { styles: 'border-danger/40 text-danger-fg bg-danger/10', dot: 'bg-danger' },
        stale: { styles: 'border-danger/60 text-danger-fg bg-danger/15', dot: 'bg-danger' },
        replaced: { styles: 'border-line text-subtle bg-well', dot: 'bg-slate-500' },
    };
    const { styles, dot } = config[status] ?? config.registered;

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-xs border px-2 py-0.5 text-xs font-medium whitespace-nowrap ${styles}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${dot}`} />
            {chequeStatusLabel(status)}
        </span>
    );
}


/**
 * How long a cheque has left: green while there is room, amber inside the ten-day window
 * (with a tag saying who is holding it up), red once it has gone stale.
 */
export function ValidityBadge({ cheque }: { cheque: Cheque }) {
    const settled: ChequeStatus[] = ['available', 'cancelled', 'voided', 'replaced', 'completed'];
    const status = cheque.effective_status;

    if (!status || settled.includes(status)) {
        return <span className="text-subtle">—</span>;
    }

    // Once the bank has it, the clock is the bank's business, not the cheque's.
    if (status === 'completed' || status === 'forwarded_to_land_bank') {
        return (
            <span className="text-xs text-blue-300">
                {status === 'completed' ? 'Credited' : 'With Land Bank'}
                {cheque.acic_teller?.deposit_date ? ` ${formatDate(cheque.acic_teller.deposit_date)}` : ''}
            </span>
        );
    }

    const stale = status === 'stale';
    const soon = cheque.is_expiring_soon;
    const tone = stale
        ? 'border-danger/60 bg-danger/15 text-danger-fg'
        : soon
          ? 'border-amber-400/50 bg-amber-400/10 text-amber-400'
          : 'border-success/40 bg-success/10 text-success-fg';

    return (
        <span className="inline-flex flex-col items-start gap-1">
            <span className={`inline-flex items-center gap-1.5 rounded-xs border px-2 py-0.5 text-xs font-medium ${tone}`}>
                {stale ? <AlertTriangle className="h-3 w-3" /> : null}
                {cheque.countdown ?? '—'}
            </span>
            <span className="inline-flex flex-wrap items-center gap-1 text-[11px] text-subtle">
                {cheque.validity_until ? `Valid to ${formatDate(cheque.validity_until)}` : null}
                {soon && cheque.expiring_tag && (
                    <span className="rounded-xs border border-amber-400/40 px-1 py-px text-[10px] uppercase tracking-wider text-amber-400">
                        {cheque.expiring_tag}
                    </span>
                )}
            </span>
        </span>
    );
}

export function AcicStatusBadge({ status }: { status: AcicStatus }) {
    const config = {
        open: { styles: 'border-brand-400/40 text-brandink bg-brand-500/10', dot: 'bg-brand-300', label: 'Open' },
        used: { styles: 'border-slate-600/50 text-muted bg-slate-500/10', dot: 'bg-slate-400', label: 'Used' },
        approved: { styles: 'border-success/40 text-success-fg bg-success/10', dot: 'bg-success', label: 'Approved' },
        // Forwarded = sitting with the teller, i.e. action needed.
        forwarded: { styles: 'border-accent-400/50 text-accent-400 bg-accent-400/10', dot: 'bg-accent-400', label: 'Forwarded' },
        completed: { styles: 'border-success/40 text-success-fg bg-success/10', dot: 'bg-success', label: 'Completed' },
    }[status];
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-xs border px-2 py-0.5 text-xs font-medium ${config.styles}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${config.dot}`} />
            {config.label}
        </span>
    );
}

/**
 * The LDDAP's place in its routing: Registered → For Out → Returned for ACIC → Approved | RTS |
 * Canceled. Coral marks the one state that is waiting on the admin's action; amber, a record
 * sent back to be corrected.
 */
export function LddapStatusBadge({ status }: { status: LddapStatus }) {
    const config = {
        registered: { styles: 'border-line text-muted bg-well', dot: 'bg-slate-500', label: 'Registered' },
        for_out: { styles: 'border-brand-400/40 text-brandink bg-brand-500/10', dot: 'bg-brand-300', label: 'For Out' },
        returned_for_acic: { styles: 'border-accent-400/50 text-accent-400 bg-accent-400/10', dot: 'bg-accent-400', label: 'Returned for ACIC' },
        // Sent back to be corrected — amber, so it reads as neither the coral "act on me" nor a verdict.
        rts: { styles: 'border-amber-400/50 text-amber-400 bg-amber-400/10', dot: 'bg-amber-400', label: 'RTS' },
        approved: { styles: 'border-success/40 text-success-fg bg-success/10', dot: 'bg-success', label: 'Approved' },
        canceled: { styles: 'border-danger/40 text-danger-fg bg-danger/10', dot: 'bg-danger', label: 'Canceled' },
    }[status];

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-xs border px-2 py-0.5 text-xs font-medium ${config.styles}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${config.dot}`} />
            {config.label}
        </span>
    );
}

export function Alert({ kind, children }: { kind: 'error' | 'success'; children: ReactNode }) {
    return <div className={`alert ${kind === 'error' ? 'alert-error' : 'alert-success'}`}>{children}</div>;
}

export function EmptyState({ children }: { children: ReactNode }) {
    return (
        <div className="card flex items-center justify-center px-6 py-16 text-center text-sm text-subtle">
            {children}
        </div>
    );
}
