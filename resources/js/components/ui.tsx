import type { ReactNode } from 'react';
import { Moon, Sun } from 'lucide-react';
import type { AcicStatus, ChequeStatus, LddapStatus, Role } from '../lib/types';
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
export function StatusBadge({
    status,
    complied = false,
    viewer,
}: {
    status: ChequeStatus;
    /** A correction is pending, i.e. the compliance note has been acted on. */
    complied?: boolean;
    /** Whose reading of the status this is. */
    viewer?: Role;
}) {
    const config = {
        available: { styles: 'border-brand-400/40 text-brandink bg-brand-500/10', dot: 'bg-brand-300', label: 'Available' },
        used: { styles: 'border-slate-600/50 text-muted bg-slate-500/10', dot: 'bg-slate-400', label: 'Used' },
        received: { styles: 'border-success/40 text-success-fg bg-success/10', dot: 'bg-success', label: 'Received' },
        approved: { styles: 'border-success/40 text-success-fg bg-success/10', dot: 'bg-success', label: 'Approved' },
        complies: { styles: 'border-accent-400/50 text-accent-400 bg-accent-400/10', dot: 'bg-accent-400', label: 'Returned' },
        disapproved: { styles: 'border-danger/40 text-danger-fg bg-danger/10', dot: 'bg-danger', label: 'Disapproved' },
    }[status];

    const isComplied = complied && status === 'complies';
    const label = isComplied ? (viewer === 'staff' ? 'On Hold' : 'Complied') : config.label;
    const styles = isComplied ? 'border-brand-400/40 text-brandink bg-brand-500/10' : config.styles;
    const dot = isComplied ? 'bg-brand-300' : config.dot;

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-xs border px-2 py-0.5 text-xs font-medium ${styles}`}
            title={isComplied ? 'Complied with — the cheque stays Returned until an admin approves the correction.' : undefined}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${dot}`} />
            {label}
        </span>
    );
}

/** The ACIC equivalent of StatusBadge — same shape, same colour language. */
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
