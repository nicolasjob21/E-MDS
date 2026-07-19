import { useEffect, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import {
    LayoutDashboard,
    ListChecks,
    PlusSquare,
    Users,
    ScrollText,
    ClipboardCheck,
    LogOut,
    Menu,
    X,
    ShieldCheck,
    PanelLeftClose,
    PanelLeftOpen,
} from 'lucide-react';
import { useAuth } from '../auth/AuthContext';
import { ThemeToggle } from './ui';
import NotificationsBell from './NotificationsBell';

interface NavItem {
    to: string;
    label: string;
    icon: typeof LayoutDashboard;
    adminOnly?: boolean;
}

const NAV: NavItem[] = [
    { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { to: '/cheques', label: 'Cheques', icon: ListChecks },
    { to: '/admin/update-requests', label: 'Update Requests', icon: ClipboardCheck, adminOnly: true },
    { to: '/admin/add-range', label: 'Add Range', icon: PlusSquare, adminOnly: true },
    { to: '/admin/users', label: 'Users', icon: Users, adminOnly: true },
    { to: '/admin/logs', label: 'Audit Log', icon: ScrollText, adminOnly: true },
];

const COLLAPSE_KEY = 'cw-sidebar-collapsed';

export default function Layout() {
    const { user, isAdmin, logout } = useAuth();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false); // mobile drawer
    const [collapsed, setCollapsed] = useState(() => localStorage.getItem(COLLAPSE_KEY) === '1');

    useEffect(() => {
        localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
    }, [collapsed]);

    const items = NAV.filter((item) => !item.adminOnly || isAdmin);

    // When collapsed, these utilities hide labels / centre icons on desktop only (mobile drawer stays full).
    const hideOnCollapse = collapsed ? 'md:hidden' : '';
    const centreOnCollapse = collapsed ? 'md:justify-center md:px-0' : '';

    async function handleLogout() {
        await logout();
        navigate('/login');
    }

    return (
        <div className="min-h-screen md:flex">
            {/* Sidebar */}
            <aside
                className={`fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col border-r border-line bg-well transition-all md:sticky md:top-0 md:h-screen md:translate-x-0 ${
                    collapsed ? 'md:w-16' : 'md:w-64'
                } ${open ? 'translate-x-0' : '-translate-x-full'}`}
            >
                <div className={`flex h-16 items-center gap-2 border-b border-line px-6 ${centreOnCollapse}`}>
                    <ShieldCheck className="h-6 w-6 shrink-0 text-brandink" />
                    <span className={`font-display text-lg font-bold tracking-tight text-fg ${hideOnCollapse}`}>
                        ChequeWatch
                    </span>
                </div>

                <nav className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto p-3">
                    {items.map(({ to, label, icon: Icon }) => (
                        <NavLink
                            key={to}
                            to={to}
                            title={label}
                            onClick={() => setOpen(false)}
                            className={({ isActive }) =>
                                `flex items-center gap-3 rounded-xs px-3 py-2.5 text-sm transition-colors ${centreOnCollapse} ${
                                    isActive
                                        ? 'bg-brand-500/15 text-brandink'
                                        : 'text-muted hover:bg-card hover:text-fg'
                                }`
                            }
                        >
                            <Icon className="h-4.5 w-4.5 shrink-0" />
                            <span className={hideOnCollapse}>{label}</span>
                        </NavLink>
                    ))}
                </nav>

                {/* Collapse toggle — desktop only */}
                <button
                    className={`hidden items-center gap-3 border-t border-line px-4 py-3 text-sm text-muted transition-colors hover:bg-card hover:text-fg md:flex ${centreOnCollapse}`}
                    onClick={() => setCollapsed((v) => !v)}
                    aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                    title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                >
                    {collapsed ? (
                        <PanelLeftOpen className="h-4.5 w-4.5 shrink-0" />
                    ) : (
                        <PanelLeftClose className="h-4.5 w-4.5 shrink-0" />
                    )}
                    <span className={hideOnCollapse}>Collapse</span>
                </button>
            </aside>

            {open && <div className="fixed inset-0 z-30 bg-black/60 md:hidden" onClick={() => setOpen(false)} />}

            {/* Main column */}
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-line bg-page/80 px-4 backdrop-blur md:px-8">
                    <button
                        className="btn btn-ghost md:hidden"
                        onClick={() => setOpen((v) => !v)}
                        aria-label="Toggle navigation"
                    >
                        {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
                    </button>
                    <div className="ml-auto flex items-center gap-3">
                        <NotificationsBell />
                        <ThemeToggle />
                        <div className="hidden text-right sm:block">
                            <div className="text-sm font-medium text-fg">{user?.name}</div>
                            <div className="text-xs uppercase tracking-wider text-brandink">{user?.role}</div>
                        </div>
                        <button className="btn btn-outline" onClick={handleLogout}>
                            <LogOut className="h-4 w-4" />
                            <span className="hidden sm:inline">Sign out</span>
                        </button>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8 md:px-8">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
