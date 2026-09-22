import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, KeyRound } from 'lucide-react';
import { AuthApi, toApiError } from '../lib/api';
import { PageHeader, Alert } from '../components/ui';

interface Draft {
    current_password: string;
    password: string;
    password_confirmation: string;
}

const EMPTY: Draft = { current_password: '', password: '', password_confirmation: '' };

/**
 * Change Password. The current password is checked server-side, the new one must be confirmed
 * and pass the app's password rules — every rule that fails is listed under the field. The
 * session stays signed in afterwards.
 */
export default function ChangePasswordPage() {
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [done, setDone] = useState('');

    function update(patch: Partial<Draft>) {
        setDraft((d) => ({ ...d, ...patch }));
    }

    const mismatch =
        draft.password_confirmation !== '' && draft.password !== draft.password_confirmation
            ? 'The new password and its confirmation do not match.'
            : null;
    const complete =
        draft.current_password !== '' && draft.password !== '' && draft.password_confirmation !== '';

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        setFieldErrors({});
        setBusy(true);
        try {
            const message = await AuthApi.changePassword(draft);
            setDraft(EMPTY);
            setDone(message);
        } catch (err) {
            const apiErr = toApiError(err);
            setFieldErrors(apiErr.errors);
            if (Object.keys(apiErr.errors).length === 0) setError(apiErr.message);
        } finally {
            setBusy(false);
        }
    }

    function errorsFor(field: keyof Draft) {
        const list = fieldErrors[field];
        if (!list?.length) return null;
        return (
            <ul id={`pw-${field}-error`} className="mt-1 space-y-0.5 text-xs text-danger-fg">
                {list.map((msg) => (
                    <li key={msg}>{msg}</li>
                ))}
            </ul>
        );
    }

    return (
        <div className="mx-auto max-w-xl">
            <PageHeader title="Change Password" subtitle="Choose a new password for your account. You stay signed in." />

            {done ? (
                /* The done view: what changed, and where to go next. */
                <div className="card space-y-5 p-6" role="status">
                    <Alert kind="success">{done}</Alert>
                    <p className="flex items-start gap-2 text-sm text-muted">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-success" />
                        <span>You are still signed in on this device. Use the new password the next time you sign in.</span>
                    </p>
                    <div className="flex flex-col gap-2 border-t border-line pt-5 sm:flex-row sm:justify-end">
                        <button type="button" className="btn btn-ghost" onClick={() => setDone('')}>
                            Change it again
                        </button>
                        <Link to="/dashboard" className="btn btn-primary">
                            Back to dashboard
                        </Link>
                    </div>
                </div>
            ) : (
                <form onSubmit={handleSubmit} className="card space-y-5 p-6" noValidate>
                    {error && <Alert kind="error">{error}</Alert>}

                    <p className="flex items-start gap-2 rounded-xs border border-line bg-well px-4 py-3 text-sm text-muted">
                        <KeyRound className="mt-0.5 h-4 w-4 shrink-0 text-brandink" />
                        <span>
                            Enter your current password, then the new one twice. The new password must be at
                            least 8 characters and different from the current one.
                        </span>
                    </p>

                    <div>
                        <label htmlFor="pw-current_password" className="label">
                            Current password
                        </label>
                        <input
                            id="pw-current_password"
                            type="password"
                            className="field"
                            value={draft.current_password}
                            onChange={(e) => update({ current_password: e.target.value })}
                            autoComplete="current-password"
                            autoFocus
                            required
                            aria-invalid={fieldErrors.current_password ? true : undefined}
                            aria-describedby={fieldErrors.current_password ? 'pw-current_password-error' : undefined}
                        />
                        {errorsFor('current_password')}
                    </div>

                    <div>
                        <label htmlFor="pw-password" className="label">
                            New password
                        </label>
                        <input
                            id="pw-password"
                            type="password"
                            className="field"
                            value={draft.password}
                            onChange={(e) => update({ password: e.target.value })}
                            autoComplete="new-password"
                            minLength={8}
                            required
                            aria-invalid={fieldErrors.password ? true : undefined}
                            aria-describedby={fieldErrors.password ? 'pw-password-error' : undefined}
                        />
                        {errorsFor('password')}
                    </div>

                    <div>
                        <label htmlFor="pw-password_confirmation" className="label">
                            Confirm new password
                        </label>
                        <input
                            id="pw-password_confirmation"
                            type="password"
                            className="field"
                            value={draft.password_confirmation}
                            onChange={(e) => update({ password_confirmation: e.target.value })}
                            autoComplete="new-password"
                            required
                            aria-invalid={mismatch ? true : undefined}
                            aria-describedby={mismatch ? 'pw-password_confirmation-error' : undefined}
                        />
                        {mismatch && (
                            <p id="pw-password_confirmation-error" className="mt-1 text-xs text-danger-fg">
                                {mismatch}
                            </p>
                        )}
                    </div>

                    <div className="flex justify-end border-t border-line pt-5">
                        <button
                            type="submit"
                            className="btn btn-primary"
                            disabled={busy || !complete || mismatch !== null}
                        >
                            <KeyRound className="h-4 w-4" />
                            {busy ? 'Changing…' : 'Change password'}
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}
