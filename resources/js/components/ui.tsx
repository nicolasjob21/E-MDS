import type { ReactNode } from 'react';
import { Moon, Sun } from 'lucide-react';
import type { ChequeStatus } from '../lib/types';
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

export function StatusBadge({ status }: { status: ChequeStatus }) {
    const config = {
        available: { styles: 'border-brand-400/40 text-brandink bg-brand-500/10', dot: 'bg-brand-300', label: 'Available' },
        used: { styles: 'border-slate-600/50 text-muted bg-slate-500/10', dot: 'bg-slate-400', label: 'Used' },
        received: { styles: 'border-success/40 text-success-fg bg-success/10', dot: 'bg-success', label: 'Received' },
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
