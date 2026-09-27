import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Plus, Upload, X } from 'lucide-react';
import { toApiError, type AccountHolderApi } from '../lib/api';
import type { AccountHolder, AccountHolderDraft, Paginated } from '../lib/types';
import { formatDateTime } from '../lib/format';
import { PageHeader, Spinner, Alert, EmptyState } from './ui';
import UnitSelect from './UnitSelect';

interface Props {
    title: string;
    subtitle: string;
    /** The name column's heading and the Add form's first label: "Creditor Name". */
    nameLabel: string;
    /** "Add Creditor" */
    addLabel: string;
    /** Used in messages: "creditor(s)" */
    noun: string;
    api: AccountHolderApi;
}

type Panel = 'add' | 'upload' | null;

/** One row of the Add form; `key` keeps React's inputs attached to the right row on remove. */
interface Entry extends AccountHolderDraft {
    key: number;
}

/** The server takes at most this many entries per save; Batch Upload is for more. */
const MAX_ENTRIES = 100;

let nextKey = 1;
const blankEntry = (): Entry => ({ key: nextKey++, name: '', account_no: '', unit: '' });

type EntryField = keyof AccountHolderDraft;

/**
 * The Creditors and PCG Personnel pages: the list (all five columns, newest first, searchable),
 * an Add form taking one or more entries at once (name, account number, unit each — saved all
 * or none; the server stamps Date Created and Added By), and
 * Batch Upload of an .xlsx or .csv file, which saves every row or none.
 */
export default function AccountHolderPage({ title, subtitle, nameLabel, addLabel, noun, api }: Props) {
    const [data, setData] = useState<Paginated<AccountHolder> | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [unitFilter, setUnitFilter] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [panel, setPanel] = useState<Panel>(null);

    const [entries, setEntries] = useState<Entry[]>(() => [blankEntry()]);
    const [saving, setSaving] = useState(false);
    /** Field errors by row index, from the server's `records.N.field` keys. */
    const [formErrors, setFormErrors] = useState<Record<number, Partial<Record<EntryField, string>>>>({});
    const [formError, setFormError] = useState('');

    const [file, setFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadErrors, setUploadErrors] = useState<string[]>([]);
    const fileInput = useRef<HTMLInputElement>(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            setData(await api.list({ page, ...(query ? { search: query } : {}), ...(unitFilter ? { unit: unitFilter } : {}) }));
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, [api, page, query, unitFilter]);

    useEffect(() => {
        void load();
    }, [load]);

    // Search as you type, once typing pauses.
    useEffect(() => {
        const timer = window.setTimeout(() => {
            setPage(1);
            setQuery(search.trim());
        }, 300);
        return () => window.clearTimeout(timer);
    }, [search]);

    function toggle(next: Panel) {
        setNotice('');
        setPanel((current) => (current === next ? null : next));
    }

    function updateEntry(index: number, field: EntryField, value: string) {
        setEntries((prev) => prev.map((entry, i) => (i === index ? { ...entry, [field]: value } : entry)));
    }

    function removeEntry(index: number) {
        setEntries((prev) => prev.filter((_, i) => i !== index));
        // Errors are by position, so they no longer line up with the rows after a removal.
        setFormErrors({});
    }

    async function handleAdd(e: FormEvent) {
        e.preventDefault();
        setFormError('');
        setFormErrors({});
        setSaving(true);
        try {
            const created = await api.create(entries);
            setEntries([blankEntry()]);
            setPanel(null);
            setNotice(created.length === 1 ? `Added “${created[0]?.name}”.` : `Added ${created.length} ${noun}.`);
            setPage(1);
            await load();
        } catch (err) {
            const apiErr = toApiError(err);
            const byRow: Record<number, Partial<Record<EntryField, string>>> = {};
            const other: string[] = [];
            for (const [key, messages] of Object.entries(apiErr.errors)) {
                const match = /^records\.(\d+)\.(name|account_no|unit)$/.exec(key);
                if (match) {
                    const row = Number(match[1]);
                    byRow[row] = { ...byRow[row], [match[2] as EntryField]: messages[0] ?? '' };
                } else {
                    other.push(...messages);
                }
            }
            setFormErrors(byRow);
            const failed = Object.keys(byRow).length;
            setFormError(
                other.length > 0
                    ? other.join(' ')
                    : failed > 0
                      ? `Nothing was saved. Fix the ${failed === 1 ? 'entry' : `${failed} entries`} marked below.`
                      : apiErr.message,
            );
        } finally {
            setSaving(false);
        }
    }

    async function handleUpload(e: FormEvent) {
        e.preventDefault();
        setUploadErrors([]);
        if (!file) {
            setUploadErrors(['Choose an Excel (.xlsx) or CSV (.csv) file.']);
            return;
        }
        setUploading(true);
        try {
            const { count } = await api.upload(file);
            setFile(null);
            if (fileInput.current) fileInput.current.value = '';
            setPanel(null);
            setNotice(`Uploaded ${count} ${noun}.`);
            setPage(1);
            await load();
        } catch (err) {
            const apiErr = toApiError(err);
            const messages = Object.values(apiErr.errors).flat();
            setUploadErrors(messages.length > 0 ? messages : [apiErr.message]);
        } finally {
            setUploading(false);
        }
    }

    const rowErrors = uploadErrors.filter((m) => m.startsWith('Row '));

    return (
        <div>
            <PageHeader
                title={title}
                subtitle={subtitle}
                action={
                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                        <button className="btn btn-primary" onClick={() => toggle('add')} aria-expanded={panel === 'add'}>
                            <Plus className="h-4 w-4" />
                            {addLabel}
                        </button>
                        <button className="btn btn-outline" onClick={() => toggle('upload')} aria-expanded={panel === 'upload'}>
                            <Upload className="h-4 w-4" />
                            Batch Upload
                        </button>
                    </div>
                }
            />

            {error && (
                <div className="mb-4">
                    <Alert kind="error">{error}</Alert>
                </div>
            )}
            {notice && (
                <div className="mb-4">
                    <Alert kind="success">{notice}</Alert>
                </div>
            )}

            {panel === 'add' && (
                <form onSubmit={handleAdd} className="card mb-6 space-y-4 p-6" noValidate>
                    {formError && <Alert kind="error">{formError}</Alert>}

                    {/* Column headings once, on wider screens; each input keeps its own label. */}
                    <div className="hidden grid-cols-[2rem_1fr_1fr_1fr_2.5rem] gap-3 sm:grid" aria-hidden="true">
                        <span />
                        <span className="label !mb-0">{nameLabel}</span>
                        <span className="label !mb-0">Account No.</span>
                        <span className="label !mb-0">
                            Unit <span className="normal-case tracking-normal text-subtle">(optional)</span>
                        </span>
                        <span />
                    </div>

                    <ol className="space-y-4 sm:space-y-3">
                        {entries.map((entry, index) => {
                            const errors = formErrors[index] ?? {};
                            const n = index + 1;
                            const input = (field: EntryField, label: string, extra = '') => (
                                <div className="min-w-0">
                                    <label htmlFor={`ah-${field}-${entry.key}`} className="label sm:sr-only">
                                        {label}
                                        {entries.length > 1 && <span className="sr-only">, entry {n}</span>}
                                    </label>
                                    {field === 'unit' ? (
                                        <UnitSelect
                                            id={`ah-${field}-${entry.key}`}
                                            className={`field ${errors[field] ? '!border-red-400/60' : ''}`}
                                            value={entry.unit}
                                            onChange={(value) => updateEntry(index, 'unit', value)}
                                            aria-invalid={errors[field] ? true : undefined}
                                            aria-describedby={errors[field] ? `ah-${field}-${entry.key}-error` : undefined}
                                        />
                                    ) : (
                                        <input
                                            id={`ah-${field}-${entry.key}`}
                                            className={`field ${extra} ${errors[field] ? '!border-red-400/60' : ''}`}
                                            value={entry[field]}
                                            onChange={(e) => updateEntry(index, field, e.target.value)}
                                            maxLength={255}
                                            autoComplete="off"
                                            autoFocus={field === 'name'}
                                            required
                                            aria-invalid={errors[field] ? true : undefined}
                                            aria-describedby={errors[field] ? `ah-${field}-${entry.key}-error` : undefined}
                                        />
                                    )}
                                    {errors[field] && (
                                        <p id={`ah-${field}-${entry.key}-error`} className="mt-1 text-xs text-red-400">
                                            {errors[field]}
                                        </p>
                                    )}
                                </div>
                            );
                            return (
                                <li
                                    key={entry.key}
                                    className="grid gap-3 border-b border-line/60 pb-4 last:border-0 last:pb-0 sm:grid-cols-[2rem_1fr_1fr_1fr_2.5rem] sm:items-start sm:border-0 sm:pb-0"
                                >
                                    <div className="flex items-center justify-between sm:h-[2.625rem] sm:justify-center">
                                        <span className="font-display text-xs font-semibold uppercase tracking-widest text-subtle">
                                            <span className="sm:hidden">Entry </span>
                                            {n}
                                        </span>
                                        {entries.length > 1 && (
                                            <button
                                                type="button"
                                                className="btn btn-ghost !px-2 text-muted sm:hidden"
                                                onClick={() => removeEntry(index)}
                                                aria-label={`Remove entry ${n}`}
                                            >
                                                <X className="h-4 w-4" />
                                            </button>
                                        )}
                                    </div>
                                    {input('name', nameLabel)}
                                    {input('account_no', 'Account No.', 'font-mono')}
                                    {input('unit', 'Unit (optional)')}
                                    <div className="hidden sm:block">
                                        {entries.length > 1 && (
                                            <button
                                                type="button"
                                                className="btn btn-ghost !px-2 text-muted"
                                                onClick={() => removeEntry(index)}
                                                aria-label={`Remove entry ${n}`}
                                            >
                                                <X className="h-4 w-4" />
                                            </button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>

                    <button
                        type="button"
                        className="btn btn-outline w-full sm:w-auto"
                        onClick={() => setEntries((prev) => [...prev, blankEntry()])}
                        disabled={entries.length >= MAX_ENTRIES}
                    >
                        <Plus className="h-4 w-4" />
                        Add another
                    </button>

                    <p className="text-xs text-subtle">
                        Date Created and Added By are filled in automatically on save. Every entry is saved, or none
                        are{entries.length >= MAX_ENTRIES ? ` — at most ${MAX_ENTRIES} at a time; use Batch Upload for more` : ''}.
                    </p>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={() => setPanel(null)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={saving}>
                            {saving ? 'Saving…' : entries.length === 1 ? 'Save' : `Save ${entries.length} entries`}
                        </button>
                    </div>
                </form>
            )}

            {panel === 'upload' && (
                <form onSubmit={handleUpload} className="card mb-6 space-y-4 p-6">
                    {uploadErrors.length > 0 && (
                        <Alert kind="error">
                            {rowErrors.length > 0 ? (
                                <>
                                    <p className="font-semibold">
                                        Nothing was saved. Fix {rowErrors.length === 1 ? 'this row' : `these ${rowErrors.length} problems`} and
                                        upload the file again:
                                    </p>
                                    <ul className="mt-2 max-h-64 list-disc space-y-0.5 overflow-y-auto pl-5">
                                        {uploadErrors.map((m, i) => (
                                            <li key={i}>{m}</li>
                                        ))}
                                    </ul>
                                </>
                            ) : (
                                uploadErrors.join(' ')
                            )}
                        </Alert>
                    )}
                    <div>
                        <label htmlFor="ah-file" className="label">
                            Excel (.xlsx) or CSV file
                        </label>
                        <input
                            id="ah-file"
                            ref={fileInput}
                            type="file"
                            accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                            className="field cursor-pointer file:mr-4 file:cursor-pointer file:border-0 file:bg-brand-500/15 file:px-3 file:py-1 file:font-display file:text-xs file:font-semibold file:uppercase file:tracking-widest file:text-brandink"
                            onChange={(e) => {
                                setUploadErrors([]);
                                setFile(e.target.files?.[0] ?? null);
                            }}
                        />
                    </div>
                    <ul className="list-disc space-y-1 pl-5 text-xs leading-relaxed text-muted">
                        <li>
                            The first row is the header, with the columns <strong className="text-fg">Name</strong>,{' '}
                            <strong className="text-fg">Account No.</strong> and <strong className="text-fg">Unit</strong>.
                            Unit may be left blank; if given, it must be a unit from the PCG unit list (capitalization and
                            extra spaces don't matter — the list's spelling is saved).
                        </li>
                        <li>Each row below it becomes one record. Account numbers are read as text, so leading zeros are kept.</li>
                        <li>If any row has a problem, nothing is saved and every problem is listed by row number.</li>
                    </ul>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={() => setPanel(null)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={uploading}>
                            <Upload className="h-4 w-4" />
                            {uploading ? 'Uploading…' : 'Upload'}
                        </button>
                    </div>
                </form>
            )}

            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:max-w-4xl">
                <div>
                    <label htmlFor="ah-search" className="sr-only">
                        Search
                    </label>
                    <input
                        id="ah-search"
                        type="search"
                        className="field"
                        placeholder="Search name, account no. or unit"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="min-w-0">
                    <label htmlFor="ah-unit-filter" className="sr-only">
                        Filter by unit
                    </label>
                    <UnitSelect
                        id="ah-unit-filter"
                        placeholder="All units"
                        value={unitFilter}
                        onChange={(value) => {
                            setPage(1);
                            setUnitFilter(value);
                        }}
                    />
                </div>
            </div>

            {loading && !data ? (
                <Spinner />
            ) : data && data.data.length === 0 ? (
                <EmptyState>{query || unitFilter ? `No ${noun} match these filters.` : `No ${noun} yet.`}</EmptyState>
            ) : (
                data && (
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                        <th className="px-4 py-3 font-semibold">{nameLabel}</th>
                                        <th className="px-4 py-3 font-semibold">Account No.</th>
                                        <th className="px-4 py-3 font-semibold">Unit</th>
                                        <th className="px-4 py-3 font-semibold">Date Created</th>
                                        <th className="px-4 py-3 font-semibold">Added By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.data.map((r) => (
                                        <tr key={r.id} className="border-b border-line/60 last:border-0">
                                            <td className="px-4 py-3 text-fg">{r.name}</td>
                                            <td className="whitespace-nowrap px-4 py-3 font-mono text-muted">{r.account_no}</td>
                                            <td className="px-4 py-3 text-muted">{r.unit ?? '—'}</td>
                                            <td className="whitespace-nowrap px-4 py-3 text-muted">{formatDateTime(r.created_at)}</td>
                                            <td className="px-4 py-3 text-muted">{r.added_by?.name ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {data.meta.last_page > 1 && (
                            <div className="flex items-center justify-between gap-2 border-t border-line px-4 py-3 text-sm text-muted">
                                <span>
                                    Page {data.meta.current_page} of {data.meta.last_page} · {data.meta.total} records
                                </span>
                                <div className="flex gap-2">
                                    <button
                                        className="btn btn-ghost"
                                        disabled={data.meta.current_page <= 1}
                                        onClick={() => setPage((p) => p - 1)}
                                    >
                                        Prev
                                    </button>
                                    <button
                                        className="btn btn-ghost"
                                        disabled={data.meta.current_page >= data.meta.last_page}
                                        onClick={() => setPage((p) => p + 1)}
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                )
            )}
        </div>
    );
}
