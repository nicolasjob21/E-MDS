import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { Spinner } from './ui';

function FullScreenLoader() {
    return (
        <div className="flex min-h-screen items-center justify-center">
            <Spinner label="Loading E-MDS…" />
        </div>
    );
}

/** Requires an authenticated user. */
export function RequireAuth({ children }: { children: ReactNode }) {
    const { user, loading } = useAuth();
    const location = useLocation();

    if (loading) return <FullScreenLoader />;
    if (!user) return <Navigate to="/login" replace state={{ from: location }} />;
    return <>{children}</>;
}

/** Requires an authenticated admin; staff are bounced to the dashboard. */
export function RequireAdmin({ children }: { children: ReactNode }) {
    const { user, loading, isAdmin } = useAuth();

    if (loading) return <FullScreenLoader />;
    if (!user) return <Navigate to="/login" replace />;
    if (!isAdmin) return <Navigate to="/dashboard" replace />;
    return <>{children}</>;
}
