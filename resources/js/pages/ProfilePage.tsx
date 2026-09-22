import { useState, type FormEvent } from 'react';
import { Save, UserRound } from 'lucide-react';
import { AuthApi, toApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';
import { PageHeader, Alert } from '../components/ui';

/**
 * The signed-in user's own profile. Username and role are the admin's to set and are shown
 * read-only; the full name and email can be changed here.
 */
export default function ProfilePage() {
    const { user, updateUser } = useAuth();
    const [name, setName] = useState(user?.name ?? '');
    const [email, setEmail] = useState(user?.email ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [success, setSuccess] = useState('');

    if (!user) return null;

    const dirty = name.trim() !== user.name || (email.trim() || null) !== (user.email ?? null);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        setSuccess('');
        setFieldErrors({});
        setBusy(true);
        try {
            const saved = await AuthApi.updateProfile({ name: name.trim(), email: email.trim() || null });
            updateUser(saved);
            setName(saved.name);
            setEmail(saved.email ?? '');
            setSuccess('Your profile has been saved.');
        } catch (err) {
            const apiErr = toApiError(err);
            setFieldErrors(apiErr.errors);
            if (Object.keys(apiErr.errors).length === 0) setError(apiErr.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="mx-auto max-w-xl">
            <PageHeader title="Profile" subtitle="Your account. Username and role are set by an administrator." />

            <form onSubmit={handleSubmit} className="card space-y-5 p-6" noValidate>
                {error && <Alert kind="error">{error}</Alert>}
                {success && (
                    <div role="status">
                        <Alert kind="success">{success}</Alert>
                    </div>
                )}

                <div className="flex items-center gap-4 border-b border-line pb-5">
                    <span
                        aria-hidden="true"
                        className="grid h-12 w-12 shrink-0 place-items-center rounded-xs border border-line bg-brand-500/15 text-brandink"
                    >
                        <UserRound className="h-6 w-6" />
                    </span>
                    <div className="min-w-0">
                        <div className="truncate font-display text-lg font-bold text-fg">{user.name}</div>
                        <div className="text-xs uppercase tracking-wider text-brandink">{user.role}</div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label htmlFor="profile-username" className="label">
                            Username
                        </label>
                        <input
                            id="profile-username"
                            className="field font-mono opacity-70"
                            value={user.username}
                            readOnly
                            aria-readonly="true"
                        />
                    </div>
                    <div>
                        <label htmlFor="profile-role" className="label">
                            Role
                        </label>
                        <input
                            id="profile-role"
                            className="field capitalize opacity-70"
                            value={user.role}
                            readOnly
                            aria-readonly="true"
                        />
                    </div>
                </div>

                <div>
                    <label htmlFor="profile-name" className="label">
                        Full name
                    </label>
                    <input
                        id="profile-name"
                        className="field"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        maxLength={255}
                        autoComplete="name"
                        required
                        aria-invalid={fieldErrors.name ? true : undefined}
                        aria-describedby={fieldErrors.name ? 'profile-name-error' : undefined}
                    />
                    {fieldErrors.name && (
                        <p id="profile-name-error" className="mt-1 text-xs text-danger-fg">
                            {fieldErrors.name[0]}
                        </p>
                    )}
                </div>

                <div>
                    <label htmlFor="profile-email" className="label">
                        Email
                    </label>
                    <input
                        id="profile-email"
                        type="email"
                        className="field"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        maxLength={255}
                        autoComplete="email"
                        placeholder="you@example.com"
                        aria-invalid={fieldErrors.email ? true : undefined}
                        aria-describedby={fieldErrors.email ? 'profile-email-error' : undefined}
                    />
                    {fieldErrors.email && (
                        <p id="profile-email-error" className="mt-1 text-xs text-danger-fg">
                            {fieldErrors.email[0]}
                        </p>
                    )}
                </div>

                <div className="flex justify-end border-t border-line pt-5">
                    <button type="submit" className="btn btn-primary" disabled={busy || !dirty || !name.trim()}>
                        <Save className="h-4 w-4" />
                        {busy ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </div>
    );
}
