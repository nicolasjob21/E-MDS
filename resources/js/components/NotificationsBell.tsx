import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, CheckCheck } from 'lucide-react';
import { NotificationApi } from '../lib/api';
import { formatRelative } from '../lib/format';
import type { AppNotification, NotificationKind } from '../lib/types';

const POLL_MS = 30_000;

/** Coloured dot per notification kind — mirrors the BT-Attendance feed. */
const DOT: Record<NotificationKind, string> = {
    request: 'bg-brand-400',
    approved: 'bg-success',
    rejected: 'bg-danger',
    used: 'bg-accent-400',
    expiring: 'bg-amber-400',
    stale: 'bg-danger',
};

/**
 * Header bell with an unread badge and a dropdown feed of the current user's notifications.
 * Polls in the background; opening an item marks it read and navigates to the linked page.
 */
export default function NotificationsBell() {
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<AppNotification[]>([]);
    const [unread, setUnread] = useState(0);
    const wrapRef = useRef<HTMLDivElement>(null);

    const load = useCallback(async () => {
        try {
            const feed = await NotificationApi.list();
            setItems(feed.data);
            setUnread(feed.unread_count);
        } catch {
            // Silent — a transient poll failure shouldn't disrupt the app.
        }
    }, []);

    useEffect(() => {
        void load();
        const timer = setInterval(() => void load(), POLL_MS);
        return () => clearInterval(timer);
    }, [load]);

    // Close on outside click / Escape.
    useEffect(() => {
        if (!open) return;
        function onClick(e: MouseEvent) {
            if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
        }
        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') setOpen(false);
        }
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    async function openItem(item: AppNotification) {
        setOpen(false);
        if (!item.read) {
            setItems((list) => list.map((n) => (n.id === item.id ? { ...n, read: true } : n)));
            setUnread((c) => Math.max(0, c - 1));
            try {
                await NotificationApi.markRead(item.id);
            } catch {
                void load();
            }
        }
        if (item.url) navigate(item.url);
    }

    async function markAll() {
        setItems((list) => list.map((n) => ({ ...n, read: true })));
        setUnread(0);
        try {
            await NotificationApi.markAllRead();
        } catch {
            void load();
        }
    }

    return (
        <div className="relative" ref={wrapRef}>
            <button
                className="btn btn-ghost relative !px-2.5"
                onClick={() => setOpen((v) => !v)}
                aria-label={unread ? `Notifications (${unread} unread)` : 'Notifications'}
                aria-haspopup="true"
                aria-expanded={open}
            >
                <Bell className="h-5 w-5" />
                {unread > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-accent-500 px-1 text-[10px] font-bold leading-none text-white">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div
                    role="menu"
                    className="fixed inset-x-2 top-16 z-40 overflow-hidden rounded-xs border border-line bg-well shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-80"
                >
                    <div className="flex items-center justify-between border-b border-line px-4 py-2.5">
                        <span className="font-display text-sm font-semibold text-fg">Notifications</span>
                        {unread > 0 && (
                            <button
                                className="inline-flex items-center gap-1 text-xs font-medium text-brandink hover:underline"
                                onClick={markAll}
                            >
                                <CheckCheck className="h-3.5 w-3.5" />
                                Mark all read
                            </button>
                        )}
                    </div>

                    <div className="max-h-96 overflow-y-auto">
                        {items.length === 0 ? (
                            <p className="px-4 py-10 text-center text-sm text-subtle">No notifications yet.</p>
                        ) : (
                            items.map((n) => (
                                <button
                                    key={n.id}
                                    onClick={() => void openItem(n)}
                                    className={`flex w-full gap-3 border-b border-line/60 px-4 py-3 text-left transition-colors hover:bg-card ${
                                        n.read ? '' : 'bg-brand-500/10'
                                    }`}
                                >
                                    <span
                                        className={`mt-1.5 h-2 w-2 flex-none rounded-full ${DOT[n.kind]} ${
                                            n.read ? 'opacity-30' : ''
                                        }`}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-medium text-fg">{n.title}</span>
                                        <span className="mt-0.5 block text-xs text-muted">{n.message}</span>
                                        <span className="mt-1 block text-[11px] text-subtle">
                                            {formatRelative(n.created_at)}
                                        </span>
                                    </span>
                                </button>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
