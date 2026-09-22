import { useEffect, useId, useRef, useState } from 'react';
import { Search, X, Check } from 'lucide-react';
import { PayeeApi } from '../lib/api';
import type { Payee } from '../lib/types';

interface Props {
    /** The chosen payee, or null while the user is still looking. */
    value: Payee | null;
    onChange: (payee: Payee | null) => void;
    /** Accessible name for the search box. */
    label: string;
    id?: string;
    error?: string;
    disabled?: boolean;
}

/**
 * A payee lookup: type a name or account number and the registered payees that match appear
 * in a results table — Payee · Account Number · Select — with a Select button on each row.
 * Once picked, the payee's name is shown in the field's place with a clear button to look
 * again; the account itself is chosen in the form's account select.
 *
 * Searches are debounced and the latest result wins, so a slow earlier response can never
 * overwrite a newer one.
 */
export default function PayeePicker({ value, onChange, label, id, error, disabled = false }: Props) {
    const generated = useId();
    const inputId = id ?? generated;

    const [term, setTerm] = useState('');
    const [results, setResults] = useState<Payee[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const latest = useRef(0);
    const wrap = useRef<HTMLDivElement>(null);

    // Debounced search; the sequence number discards any response that arrives out of order.
    useEffect(() => {
        if (!open) return;
        const seq = ++latest.current;
        setLoading(true);
        const timer = setTimeout(async () => {
            try {
                const found = await PayeeApi.search(term.trim());
                if (seq === latest.current) setResults(found);
            } catch {
                if (seq === latest.current) setResults([]);
            } finally {
                if (seq === latest.current) setLoading(false);
            }
        }, 250);
        return () => clearTimeout(timer);
    }, [term, open]);

    // Close when a click lands outside.
    useEffect(() => {
        function onDown(e: MouseEvent) {
            if (wrap.current && !wrap.current.contains(e.target as Node)) setOpen(false);
        }
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, []);

    function pick(payee: Payee) {
        onChange(payee);
        setOpen(false);
        setTerm('');
    }

    /** The accounts a results row shows: the first, plus how many more there are. */
    function accountSummary(payee: Payee): string {
        if (payee.accounts.length === 0) return '—';
        const first = payee.accounts[0].label;
        return payee.accounts.length > 1 ? `${first}  +${payee.accounts.length - 1} more` : first;
    }

    // Chosen: show the pick, not the search box.
    if (value) {
        return (
            <div
                className={`field flex items-center gap-2 !py-1.5 ${error ? '!border-danger/60' : ''}`}
                aria-live="polite"
            >
                <Check className="h-3.5 w-3.5 shrink-0 text-success" />
                <span className="min-w-0 flex-1 truncate text-sm text-fg">{value.name}</span>
                <button
                    type="button"
                    className="btn btn-ghost !p-1"
                    onClick={() => onChange(null)}
                    disabled={disabled}
                    aria-label={`Clear ${label}`}
                    title="Choose a different payee"
                >
                    <X className="h-3.5 w-3.5" />
                </button>
            </div>
        );
    }

    return (
        <div ref={wrap} className="relative">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-subtle" />
            <input
                id={inputId}
                className={`field !py-1.5 !pl-8 ${error ? '!border-danger/60' : ''}`}
                value={term}
                onChange={(e) => {
                    setTerm(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        e.stopPropagation();
                        setOpen(false);
                    }
                }}
                placeholder="Search payee name or account number…"
                aria-label={label}
                autoComplete="off"
                disabled={disabled}
            />

            {open && (
                <div className="absolute left-0 right-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-xs border border-line bg-card">
                    {loading && results.length === 0 ? (
                        <p className="px-3 py-2 text-xs text-subtle">Searching…</p>
                    ) : results.length === 0 ? (
                        <p className="px-3 py-2 text-xs text-subtle">
                            {term.trim() ? 'No registered payee matches.' : 'No payees registered yet.'}
                        </p>
                    ) : (
                        <table className="w-full text-left text-sm">
                            <thead className="sticky top-0 bg-well">
                                <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                    <th className="px-3 py-2 font-semibold">Payee</th>
                                    <th className="px-3 py-2 font-semibold">Account Number</th>
                                    <th className="px-3 py-2 text-right font-semibold">Select</th>
                                </tr>
                            </thead>
                            <tbody>
                                {results.map((p) => (
                                    <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-well">
                                        <td className="px-3 py-2 text-fg">{p.name}</td>
                                        <td className="px-3 py-2 font-mono text-xs text-muted">{accountSummary(p)}</td>
                                        <td className="px-3 py-2 text-right">
                                            <button
                                                type="button"
                                                className="btn btn-outline !px-2.5 !py-1"
                                                onMouseDown={(e) => e.preventDefault()}
                                                onClick={() => pick(p)}
                                            >
                                                Select
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            )}
        </div>
    );
}
