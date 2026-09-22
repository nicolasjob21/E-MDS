import { useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import {
    LayoutDashboard,
    ListChecks,
    FileText,
    PlusSquare,
    Users,
    ScrollText,
    ClipboardCheck,
    Receipt,
    Hash,
    BookOpen,
    ChevronDown,
    Menu,
    X,
    ShieldCheck,
    PanelLeftClose,
    PanelLeftOpen,
} from 'lucide-react';
import { useAuth } from '../auth/AuthContext';
import { ThemeToggle } from './ui';
import NotificationsBell from './NotificationsBell';
import UserMenu from './UserMenu';

interface NavItem {
    to: string;
    label: string;
    icon: typeof LayoutDashboard;
    adminOnly?: boolean;
}

/** A collapsible set of related links, shown as an accordion in the sidebar. */
interface NavGroup {
    id: string;
    label: string;
    icon: typeof LayoutDashboard;
    adminOnly?: boolean;
    children: NavItem[];
}

type NavEntry = NavItem | NavGroup;

function isGroup(entry: NavEntry): entry is NavGroup {
    return 'children' in entry;
}

const NAV: NavEntry[] = [
    { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { to: '/cheques', label: 'Cheques', icon: ListChecks },
    { to: '/lddaps', label: 'LDDAP', icon: Receipt },
    { to: '/acics', label: 'ACIC', icon: FileText },
    { to: '/admin/update-requests', label: 'Update Requests', icon: ClipboardCheck, adminOnly: true },
    {
        // The three number registers share one job — issuing the numbers everything else draws
        // on — so they sit together rather than as three loose admin links.
        id: 'series',
        label: 'Generate Series Number',
        icon: PlusSquare,
        adminOnly: true,
        children: [
            { to: '/admin/add-range', label: 'Checkbook Series Number', icon: BookOpen },
            { to: '/admin/lddap-series', label: 'LDDAP Check Series Number', icon: Hash },
            { to: '/admin/acic-series', label: 'ACIC Series Number', icon: FileText },
        ],
    },
    { to: '/admin/users', label: 'Users', icon: Users, adminOnly: true },
    { to: '/admin/logs', label: 'Audit Log', icon: ScrollText, adminOnly: true },
];

const COLLAPSE_KEY = 'cw-sidebar-collapsed';
const GROUPS_KEY = 'cw-sidebar-groups';

export default function Layout() {
    const { isAdmin } = useAuth();
    const { pathname } = useLocation();
    const [open, setOpen] = useState(false); // mobile drawer
    const [collapsed, setCollapsed] = useState(() => localStorage.getItem(COLLAPSE_KEY) === '1');

    // Which accordion groups are open, remembered between visits.
    const [openGroups, setOpenGroups] = useState<string[]>(() => {
        try {
            const stored: unknown = JSON.parse(localStorage.getItem(GROUPS_KEY) ?? '[]');
            return Array.isArray(stored) ? (stored as string[]) : [];
        } catch {
            return [];
        }
    });

    useEffect(() => {
        localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
    }, [collapsed]);

    useEffect(() => {
        localStorage.setItem(GROUPS_KEY, JSON.stringify(openGroups));
    }, [openGroups]);

    const items = NAV.filter((item) => !item.adminOnly || isAdmin);

    function toggleGroup(id: string) {
        setOpenGroups((prev) => (prev.includes(id) ? prev.filter((g) => g !== id) : [...prev, id]));
    }

    // When collapsed, these utilities hide labels / centre icons on desktop only (mobile drawer stays full).
    const hideOnCollapse = collapsed ? 'md:hidden' : '';
    const centreOnCollapse = collapsed ? 'md:justify-center md:px-0' : '';

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
                        E-MDS
                    </span>
                </div>

                <nav className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto p-3">
                    {items.map((entry) => {
                        if (!isGroup(entry)) {
                            const { to, label, icon: Icon } = entry;
                            return (
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
                            );
                        }

                        const { id, label, icon: Icon, children } = entry;
                        const hasActiveChild = children.some((c) => pathname === c.to);
                        // Collapsed to icons there is no header to click, so the group stays
                        // open; otherwise it follows the toggle, and opens itself when the page
                        // you are on lives inside it.
                        const expanded = collapsed || openGroups.includes(id) || hasActiveChild;

                        return (
                            <div key={id}>
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(id)}
                                    aria-expanded={expanded}
                                    aria-controls={`nav-group-${id}`}
                                    title={label}
                                    className={`flex w-full items-center gap-3 rounded-xs px-3 py-2.5 text-sm transition-colors ${centreOnCollapse} ${
                                        hasActiveChild
                                            ? 'text-brandink'
                                            : 'text-muted hover:bg-card hover:text-fg'
                                    }`}
                                >
                                    <Icon className="h-4.5 w-4.5 shrink-0" />
                                    <span className={`flex-1 text-left ${hideOnCollapse}`}>{label}</span>
                                    <ChevronDown
                                        className={`h-4 w-4 shrink-0 transition-transform ${hideOnCollapse} ${
                                            expanded ? 'rotate-180' : ''
                                        }`}
                                    />
                                </button>

                                {expanded && (
                                    <div id={`nav-group-${id}`} className="mt-0.5 flex flex-col gap-0.5">
                                        {children.map(({ to, label: childLabel, icon: ChildIcon }) => (
                                            <NavLink
                                                key={to}
                                                to={to}
                                                title={childLabel}
                                                onClick={() => setOpen(false)}
                                                className={({ isActive }) =>
                                                    `flex items-center gap-3 rounded-xs py-2 pl-9 pr-3 text-sm transition-colors ${
                                                        collapsed ? 'md:justify-center md:px-0' : ''
                                                    } ${
                                                        isActive
                                                            ? 'bg-brand-500/15 text-brandink'
                                                            : 'text-muted hover:bg-card hover:text-fg'
                                                    }`
                                                }
                                            >
                                                <ChildIcon className="h-4 w-4 shrink-0" />
                                                <span className={hideOnCollapse}>{childLabel}</span>
                                            </NavLink>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
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
                        {/* Name → Profile · Change Password · Sign Out. */}
                        <UserMenu />
                    </div>
                </header>

                {/* No max-width, just a modest gutter: the tables run nearly the full width of
                    the viewport. Forms that want to stay narrow cap themselves. */}
                <main className="w-full flex-1 px-5 py-8 md:px-8 lg:px-12">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
