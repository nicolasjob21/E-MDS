import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ChevronDown, KeyRound, LogOut, UserRound } from 'lucide-react';
import { useAuth } from '../auth/AuthContext';

/** "Jane A. Doe" → "JD": the avatar shown where the name doesn't fit. */
function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const first = parts[0]?.[0] ?? '';
    const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '';
    return (first + last).toUpperCase() || '?';
}

/**
 * The signed-in user's menu in the header: their name is the trigger, and the panel beneath
 * carries Profile, Change Password and Sign Out. Follows the menu-button pattern — Enter, Space
 * or an arrow key opens it, arrows move between the items, Esc closes it and hands focus back.
 *
 * Sign Out is a form submit, not a link: it posts `/logout` with the XSRF token, as before.
 */
export default function UserMenu() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const menuId = useId();
    const [open, setOpen] = useState(false);
    const [signingOut, setSigningOut] = useState(false);
    const wrapRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const itemsRef = useRef<(HTMLElement | null)[]>([]);
    // Where focus should land once the panel renders: the first item, or the last for ArrowUp.
    const focusOnOpen = useRef<'first' | 'last' | null>(null);

    function items(): HTMLElement[] {
        return itemsRef.current.filter((el): el is HTMLElement => el !== null);
    }

    function close(returnFocus = false) {
        setOpen(false);
        if (returnFocus) triggerRef.current?.focus();
    }

    function toggle(focus: 'first' | 'last' = 'first') {
        if (open) {
            close();
            return;
        }
        focusOnOpen.current = focus;
        setOpen(true);
    }

    // Focus the first (or last) item once the panel is in the DOM.
    useEffect(() => {
        if (!open || focusOnOpen.current === null) return;
        const list = items();
        (focusOnOpen.current === 'last' ? list[list.length - 1] : list[0])?.focus();
        focusOnOpen.current = null;
    }, [open]);

    // Clicking outside or pressing Esc closes the panel.
    useEffect(() => {
        if (!open) return;
        function onPointerDown(e: MouseEvent | TouchEvent) {
            if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
        }
        function onKey(e: globalThis.KeyboardEvent) {
            if (e.key === 'Escape') {
                setOpen(false);
                triggerRef.current?.focus();
            }
        }
        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('touchstart', onPointerDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('touchstart', onPointerDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    function onTriggerKeyDown(e: KeyboardEvent<HTMLButtonElement>) {
        // Enter and Space are the button's own click; the arrows open straight onto an item.
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!open) toggle('first');
            else items()[0]?.focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (!open) toggle('last');
            else items().at(-1)?.focus();
        }
    }

    function onMenuKeyDown(e: KeyboardEvent<HTMLDivElement>) {
        const list = items();
        if (list.length === 0) return;
        const current = list.indexOf(document.activeElement as HTMLElement);

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                list[(current + 1) % list.length]?.focus();
                break;
            case 'ArrowUp':
                e.preventDefault();
                list[(current - 1 + list.length) % list.length]?.focus();
                break;
            case 'Home':
                e.preventDefault();
                list[0]?.focus();
                break;
            case 'End':
                e.preventDefault();
                list[list.length - 1]?.focus();
                break;
            case 'Tab':
                // Let focus leave, but don't leave the panel hanging open behind it.
                close();
                break;
        }
    }

    async function handleSignOut(e: FormEvent) {
        e.preventDefault();
        if (signingOut) return;
        setSigningOut(true);
        try {
            await logout();
        } finally {
            setSigningOut(false);
            close();
            navigate('/login');
        }
    }

    if (!user) return null;

    const itemClass =
        'flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-fg transition-colors hover:bg-card focus:bg-card focus:outline-none';

    return (
        <div className="relative" ref={wrapRef}>
            <button
                ref={triggerRef}
                id={`${menuId}-trigger`}
                type="button"
                className="btn btn-ghost !gap-2 !px-2 !normal-case !tracking-normal"
                onClick={() => toggle()}
                onKeyDown={onTriggerKeyDown}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={menuId}
                aria-label={`Account menu for ${user.name}`}
            >
                <span
                    aria-hidden="true"
                    className="grid h-8 w-8 shrink-0 place-items-center rounded-xs border border-line bg-brand-500/15 font-display text-xs font-bold text-brandink"
                >
                    {initials(user.name)}
                </span>
                <span className="hidden text-right sm:block">
                    <span className="block text-sm font-medium text-fg">{user.name}</span>
                    <span className="block text-[10px] uppercase tracking-wider text-brandink">{user.role}</span>
                </span>
                <ChevronDown
                    aria-hidden="true"
                    className={`h-4 w-4 shrink-0 text-muted transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
                />
            </button>

            {open && (
                <div
                    id={menuId}
                    role="menu"
                    aria-labelledby={`${menuId}-trigger`}
                    onKeyDown={onMenuKeyDown}
                    className="absolute right-0 top-full z-40 mt-2 w-60 max-w-[calc(100vw-1rem)] overflow-hidden rounded-xs border border-line bg-well shadow-lg"
                >
                    {/* Who is signed in — a header, not an item. */}
                    <div role="none" className="px-4 py-3">
                        <div className="truncate text-sm font-semibold text-fg">{user.name}</div>
                        <div className="mt-0.5 text-[10px] uppercase tracking-wider text-brandink">{user.role}</div>
                    </div>
                    <div role="separator" className="border-t border-line" />

                    <Link
                        ref={(el) => {
                            itemsRef.current[0] = el;
                        }}
                        role="menuitem"
                        tabIndex={-1}
                        to="/profile"
                        className={itemClass}
                        onClick={() => close()}
                    >
                        <UserRound className="h-4 w-4 text-muted" aria-hidden="true" />
                        Profile
                    </Link>
                    <Link
                        ref={(el) => {
                            itemsRef.current[1] = el;
                        }}
                        role="menuitem"
                        tabIndex={-1}
                        to="/change-password"
                        className={itemClass}
                        onClick={() => close()}
                    >
                        <KeyRound className="h-4 w-4 text-muted" aria-hidden="true" />
                        Change Password
                    </Link>

                    <div role="separator" className="border-t border-line" />

                    <form onSubmit={handleSignOut}>
                        <button
                            ref={(el) => {
                                itemsRef.current[2] = el;
                            }}
                            type="submit"
                            role="menuitem"
                            tabIndex={-1}
                            className={`${itemClass} !text-danger`}
                            disabled={signingOut}
                        >
                            <LogOut className="h-4 w-4" aria-hidden="true" />
                            {signingOut ? 'Signing out…' : 'Sign Out'}
                        </button>
                    </form>
                </div>
            )}
        </div>
    );
}
