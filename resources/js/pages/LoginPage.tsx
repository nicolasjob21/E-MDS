import { useState, type FormEvent } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { ShieldCheck } from 'lucide-react';
import { useAuth } from '../auth/AuthContext';
import { toApiError } from '../lib/api';
import { Alert, ThemeToggle } from '../components/ui';

export default function LoginPage() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const from = (location.state as { from?: { pathname: string } } | null)?.from?.pathname ?? '/dashboard';

    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError('');
        setSubmitting(true);
        try {
            await login(username.trim(), password);
            navigate(from, { replace: true });
        } catch (err) {
            const apiErr = toApiError(err);
            setError(apiErr.errors.username?.[0] ?? apiErr.message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="relative flex min-h-screen items-center justify-center px-4">
            <div className="absolute right-4 top-4">
                <ThemeToggle />
            </div>
            <div className="w-full max-w-sm">
                <div className="mb-8 flex flex-col items-center text-center">
                    <div className="mb-4 grid h-14 w-14 place-items-center rounded-xs border border-line bg-brand-500/10">
                        <ShieldCheck className="h-7 w-7 text-brandink" />
                    </div>
                    <h1 className="font-display text-2xl font-bold tracking-tight text-fg">ChequeWatch</h1>
                    <p className="mt-1 text-sm text-muted">Cheque Number Monitoring &amp; Tracking</p>
                </div>

                <form onSubmit={handleSubmit} className="card space-y-5 p-6">
                    {error && <Alert kind="error">{error}</Alert>}

                    <div>
                        <label htmlFor="username" className="label">
                            Username
                        </label>
                        <input
                            id="username"
                            className="field"
                            value={username}
                            onChange={(e) => setUsername(e.target.value)}
                            autoComplete="username"
                            autoFocus
                            required
                        />
                    </div>

                    <div>
                        <label htmlFor="password" className="label">
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            className="field"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            autoComplete="current-password"
                            required
                        />
                    </div>

                    <button type="submit" className="btn btn-primary w-full" disabled={submitting}>
                        {submitting ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
            </div>
        </div>
    );
}
