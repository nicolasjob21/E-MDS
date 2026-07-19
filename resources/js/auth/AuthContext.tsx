import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { AuthApi, ensureCsrf } from '../lib/api';
import type { User } from '../lib/types';

interface AuthContextValue {
    user: User | null;
    loading: boolean;
    isAdmin: boolean;
    login: (username: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);

    // Hydrate the session on first load.
    useEffect(() => {
        let active = true;
        (async () => {
            try {
                await ensureCsrf();
                const me = await AuthApi.me();
                if (active) setUser(me);
            } catch {
                if (active) setUser(null);
            } finally {
                if (active) setLoading(false);
            }
        })();
        return () => {
            active = false;
        };
    }, []);

    const login = useCallback(async (username: string, password: string) => {
        const me = await AuthApi.login(username, password);
        setUser(me);
    }, []);

    const logout = useCallback(async () => {
        try {
            await AuthApi.logout();
        } finally {
            setUser(null);
        }
    }, []);

    const value = useMemo<AuthContextValue>(
        () => ({
            user,
            loading,
            isAdmin: user?.role === 'admin',
            login,
            logout,
        }),
        [user, loading, login, logout],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthContextValue {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth must be used within an AuthProvider');
    return ctx;
}
