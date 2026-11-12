import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { api } from '../lib/api';
import { localStore } from '../lib/offline';
import { flushOrders } from '../lib/sync';
import { useApp } from '../stores/app';

const NAV: { to: string; label: string; permission?: string; end?: boolean }[] = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/order/new', label: 'New Order' },
  { to: '/to-bill', label: 'Orders to Be Billed' },
  { to: '/orders', label: 'All Orders', end: true },
  { to: '/products', label: 'Products' },
  { to: '/inventory', label: 'Inventory' },
  { to: '/production', label: 'Daily Production' },
  { to: '/payments', label: 'Payments' },
  { to: '/reports', label: 'Reports', permission: 'reports.view' },
  { to: '/customers', label: 'Customers' },
  { to: '/settings', label: 'Settings' },
];
const PHONE_TABS = [
  { to: '/', label: 'Home', end: true },
  { to: '/order/new', label: 'New Order' },
  { to: '/to-bill', label: 'To Bill' },
  { to: '/orders', label: 'All Orders', end: true },
];

function NetworkBar() {
  const online = useApp((s) => s.online);
  const waiting = useApp((s) => s.waitingOrders);
  if (online && waiting === 0) return null;
  return (
    <div role="status" className={`no-print px-4 py-1.5 text-center text-[15px] font-extrabold ${online ? 'bg-amber-soft text-amber-ink' : 'bg-ink text-white'}`}>
      {online ? `Sending ${waiting} saved ${waiting === 1 ? 'order' : 'orders'}…` : `Offline · orders are saved on this device${waiting ? ` (${waiting} waiting)` : ''}`}
    </div>
  );
}

function Toast() {
  const toast = useApp((s) => s.toast);
  if (!toast) return null;
  const tone = toast.tone === 'error' ? 'bg-rust text-white' : toast.tone === 'ok' ? 'bg-mint text-white' : 'bg-ink text-white';
  return (
    <div role="alert" className={`no-print fixed left-1/2 top-3 z-[60] w-[calc(100%-32px)] max-w-md -translate-x-1/2 rounded-2xl px-4 py-3 text-center text-[17px] font-extrabold ${tone}`}>
      {toast.text}
    </div>
  );
}

/** Watches the connection and pushes offline orders to the server when it returns. */
function useConnection() {
  const qc = useQueryClient();
  const { setOnline, setWaitingOrders, notify } = useApp.getState();

  useEffect(() => {
    let stopped = false;
    let flushing = false;

    async function tick() {
      let reachable = navigator.onLine;
      if (reachable) {
        try {
          await api.get('/settings');
        } catch (e) {
          if ((e as { isNetwork?: boolean }).isNetwork) reachable = false;
        }
      }
      if (stopped) return;
      setOnline(reachable);
      if (reachable && !flushing) {
        flushing = true;
        const res = await flushOrders().finally(() => { flushing = false; });
        if (res.sent > 0) {
          qc.invalidateQueries({ queryKey: ['orders'] });
          qc.invalidateQueries({ queryKey: ['stock'] });
          if (res.conflicts > 0) notify(`${res.sent} orders sent. ${res.conflicts} need checking in Orders to Be Billed.`, 'error');
          else notify(`${res.sent} saved ${res.sent === 1 ? 'order' : 'orders'} sent.`, 'ok');
        }
      }
      setWaitingOrders((await localStore.allOrders()).length);
    }

    tick();
    const timer = setInterval(tick, 15000);
    window.addEventListener('online', tick);
    const off = () => setOnline(false);
    window.addEventListener('offline', off);
    return () => {
      stopped = true;
      clearInterval(timer);
      window.removeEventListener('online', tick);
      window.removeEventListener('offline', off);
    };
  }, [qc, setOnline, setWaitingOrders, notify]);
}

export function Layout() {
  useConnection();
  const { pathname } = useLocation();
  const can = useApp((s) => s.can);
  const user = useApp((s) => s.user);
  const [moreOpen, setMoreOpen] = useState(false);
  const items = NAV.filter((n) => !n.permission || can(n.permission));
  // The order screen has its own footer, so the phone tab bar steps aside as in the design.
  const onOrderScreen = pathname.startsWith('/order/');

  useEffect(() => setMoreOpen(false), [pathname]);

  return (
    <div className="flex h-full bg-cream">
      <nav aria-label="Main" className="no-print hidden w-[208px] shrink-0 flex-col gap-1 overflow-y-auto border-r-2 border-line-soft bg-white p-3 side:flex">
        <Link to="/" className="px-3 py-2 text-[24px] font-black text-berry">Naturolls</Link>
        {items.map((n) => (
          <NavLink key={n.to} to={n.to} end={n.end} className={({ isActive }) => `flex min-h-[48px] items-center rounded-2xl px-3 text-[17px] font-extrabold ${isActive ? 'bg-berry-soft text-berry' : ''}`}>
            {n.label}
          </NavLink>
        ))}
        <div className="mt-auto px-3 pt-3 text-[15px] font-extrabold text-muted">{user?.name}</div>
      </nav>

      <div className="flex min-w-0 flex-1 flex-col">
        <NetworkBar />
        <main className="flex min-h-0 flex-1 flex-col overflow-y-auto">
          <Outlet />
        </main>
        {!onOrderScreen && (
          <nav aria-label="Main" className="no-print grid grid-cols-5 border-t-2 border-line-soft bg-white side:hidden">
            {PHONE_TABS.map((n) => (
              <NavLink key={n.to} to={n.to} end={n.end} className={({ isActive }) => `flex min-h-[60px] items-center justify-center px-1 text-center text-[15px] font-black leading-tight ${isActive ? 'text-berry' : ''}`}>
                {n.label}
              </NavLink>
            ))}
            <button type="button" onClick={() => setMoreOpen((v) => !v)} aria-expanded={moreOpen} className="min-h-[60px] text-[15px] font-black">More</button>
          </nav>
        )}
      </div>

      {moreOpen && (
        <div className="fixed inset-x-0 bottom-[62px] z-40 border-t-2 border-line-soft bg-white p-3 shadow-lg side:hidden">
          <div className="grid grid-cols-2 gap-2">
            {items.filter((n) => !PHONE_TABS.some((t) => t.to === n.to)).map((n) => (
              <NavLink key={n.to} to={n.to} className="flex min-h-[56px] items-center justify-center rounded-2xl border-2 border-line px-2 text-center text-[17px] font-black">
                {n.label}
              </NavLink>
            ))}
          </div>
        </div>
      )}
      <Toast />
    </div>
  );
}
