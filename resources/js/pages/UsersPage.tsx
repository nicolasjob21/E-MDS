import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { UserPlus, Trash2 } from 'lucide-react';
import { UserApi, toApiError } from '../lib/api';
import type { Role, User } from '../lib/types';
import { useAuth } from '../auth/AuthContext';
import { PageHeader, Spinner, Alert, EmptyState } from '../components/ui';

export default function UsersPage() {
    const { user: currentUser } = useAuth();
    const [users, setUsers] = useState<User[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const [showForm, setShowForm] = useState(false);
    const [name, setName] = useState('');
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [role, setRole] = useState<Role>('staff');
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            setUsers(await UserApi.list());
            setError('');
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    async function handleCreate(e: FormEvent) {
        e.preventDefault();
        setFormError('');
        setSaving(true);
        try {
            await UserApi.create({ name, username, password, role, is_active: true });
            setName('');
            setUsername('');
            setPassword('');
            setRole('staff');
            setShowForm(false);
            setNotice(`User “${username}” created.`);
            await load();
        } catch (err) {
            const apiErr = toApiError(err);
            const first = Object.values(apiErr.errors)[0]?.[0];
            setFormError(first ?? apiErr.message);
        } finally {
            setSaving(false);
        }
    }

    async function patch(u: User, payload: Record<string, unknown>) {
        setNotice('');
        setError('');
        try {
            await UserApi.update(u.id, payload);
            await load();
        } catch (err) {
            setError(toApiError(err).message);
        }
    }

    async function remove(u: User) {
        if (!confirm(`Delete user “${u.username}”? This cannot be undone.`)) return;
        setError('');
        try {
            await UserApi.remove(u.id);
            setNotice(`User “${u.username}” deleted.`);
            await load();
        } catch (err) {
            setError(toApiError(err).message);
        }
    }

    return (
        <div>
            <PageHeader
                title="Users"
                subtitle="Create and manage staff and administrator accounts."
                action={
                    <button className="btn btn-primary" onClick={() => setShowForm((v) => !v)}>
                        <UserPlus className="h-4 w-4" />
                        New user
                    </button>
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

            {showForm && (
                <form onSubmit={handleCreate} className="card mb-6 space-y-4 p-6">
                    {formError && <Alert kind="error">{formError}</Alert>}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="name" className="label">
                                Full name
                            </label>
                            <input id="name" className="field" value={name} onChange={(e) => setName(e.target.value)} required />
                        </div>
                        <div>
                            <label htmlFor="new-username" className="label">
                                Username
                            </label>
                            <input
                                id="new-username"
                                className="field"
                                value={username}
                                onChange={(e) => setUsername(e.target.value)}
                                autoComplete="off"
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="new-password" className="label">
                                Password
                            </label>
                            <input
                                id="new-password"
                                type="password"
                                className="field"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                autoComplete="new-password"
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="new-role" className="label">
                                Role
                            </label>
                            <select
                                id="new-role"
                                className="field"
                                value={role}
                                onChange={(e) => setRole(e.target.value as Role)}
                            >
                                <option value="staff">Staff</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn btn-ghost" onClick={() => setShowForm(false)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={saving}>
                            {saving ? 'Creating…' : 'Create user'}
                        </button>
                    </div>
                </form>
            )}

            {loading ? (
                <Spinner />
            ) : users.length === 0 ? (
                <EmptyState>No users yet.</EmptyState>
            ) : (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-line text-xs uppercase tracking-wider text-subtle">
                                    <th className="px-4 py-3 font-semibold">Name</th>
                                    <th className="px-4 py-3 font-semibold">Username</th>
                                    <th className="px-4 py-3 font-semibold">Role</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-4 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((u) => {
                                    const isSelf = u.id === currentUser?.id;
                                    return (
                                        <tr key={u.id} className="border-b border-line/60 last:border-0">
                                            <td className="px-4 py-3 text-fg">
                                                {u.name}
                                                {isSelf && <span className="ml-2 text-xs text-subtle">(you)</span>}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-muted">{u.username}</td>
                                            <td className="px-4 py-3">
                                                <select
                                                    className="field !py-1 !text-xs"
                                                    value={u.role}
                                                    disabled={isSelf}
                                                    onChange={(e) => void patch(u, { role: e.target.value })}
                                                >
                                                    <option value="staff">Staff</option>
                                                    <option value="admin">Administrator</option>
                                                </select>
                                            </td>
                                            <td className="px-4 py-3">
                                                <button
                                                    className={`rounded-xs border px-2 py-0.5 text-xs font-medium ${
                                                        u.is_active
                                                            ? 'border-brand-400/40 bg-brand-500/10 text-brandink'
                                                            : 'border-slate-600/50 bg-slate-500/10 text-muted'
                                                    }`}
                                                    disabled={isSelf}
                                                    onClick={() => void patch(u, { is_active: !u.is_active })}
                                                >
                                                    {u.is_active ? 'Active' : 'Inactive'}
                                                </button>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <button
                                                    className="btn btn-ghost !px-2 text-red-300"
                                                    disabled={isSelf}
                                                    onClick={() => void remove(u)}
                                                    aria-label={`Delete ${u.username}`}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}
