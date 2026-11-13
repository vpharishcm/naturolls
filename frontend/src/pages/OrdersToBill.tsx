import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Badge, Card, Empty, ErrorBanner, inputClass, Loading, PageHeader } from '../components/ui';
import { api } from '../lib/api';
import { dateTime, itemLabel, qty, rupees } from '../lib/format';
import { localStore, localTotal, type LocalOrder, type SyncNote } from '../lib/offline';
import type { Order } from '../lib/types';
import { useApp } from '../stores/app';

/** What is in an order, so staff can tell apart orders with the same total. Same items at different prices are shown once. */
function ItemChips({ items }: { items: { name: string; quantity: string | number }[] }) {
  const merged = new Map<string, number>();
  for (const i of items) merged.set(i.name, (merged.get(i.name) ?? 0) + Number(i.quantity));
  const lines = [...merged].filter(([, n]) => n > 0);
  if (!lines.length) return null;
  return (
    <ul aria-label="Items in this order" className="mt-2 flex flex-wrap gap-1.5">
      {lines.map(([name, n]) => (
        <li key={name} className="rounded-xl border border-line-soft bg-cream px-2.5 py-1 text-[16px] font-extrabold leading-tight">
          <span className="text-berry">{qty(n)} ×</span> {name}
        </li>
      ))}
    </ul>
  );
}

export default function OrdersToBill() {
  const online = useApp((s) => s.online);
  const waiting = useApp((s) => s.waitingOrders);
  const [search, setSearch] = useState('');
  const [local, setLocal] = useState<LocalOrder[]>([]);
  const [notes, setNotes] = useState<SyncNote[]>([]);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['orders', 'unbilled', search],
    queryFn: () => api.page<Order>('/orders', { status: 'unbilled', search, per_page: 100, include_items: 1 }),
    enabled: online,
    refetchInterval: 5000,
  });

  useEffect(() => {
    localStore.allOrders().then(setLocal);
    localStore.notes().then(setNotes);
    refetch();
  }, [waiting, refetch]);

  const shownLocal = local.filter((o) => !search || o.order_number.includes(search) || o.customer_name?.toLowerCase().includes(search.toLowerCase()));

  return (
    <div className="flex flex-col pb-6">
      <PageHeader title="Orders to Be Billed" action={<Link to="/order/new" className="flex min-h-[52px] items-center rounded-2xl bg-berry px-4 text-lg font-black text-white">+ New</Link>} />
      <div className="flex flex-col gap-2.5 px-4">
        <input aria-label="Search by order number or name" placeholder="Order number or name" className={inputClass} value={search} onChange={(e) => setSearch(e.target.value)} />
        <ErrorBanner error={online ? error : null} />
        {notes.length > 0 && (
          <div className="rounded-2xl bg-amber-soft px-4 py-3 text-[16px] font-extrabold text-amber-ink">
            <div className="mb-1 flex items-center justify-between gap-2">
              <span>Check after syncing</span>
              <button type="button" className="min-h-[44px] px-2 underline" onClick={() => localStore.clearNotes().then(() => setNotes([]))}>Dismiss</button>
            </div>
            {notes.map((n) => <div key={n.id}>{n.order_number}: {n.message}</div>)}
          </div>
        )}

        {shownLocal.map((o) => (
          <Link key={o.client_uuid} to={`/order/local-${o.client_uuid}`}>
            <Card>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-[20px] font-black">{o.order_number}</div>
                  <div className="text-base font-bold text-muted">{o.customer_name ?? 'No name'} · {dateTime(o.created_at)}</div>
                  <Badge tone="low">Saved on this device</Badge>
                  {o.last_error && <div className="mt-1 text-[15px] font-extrabold text-rust">{o.last_error}</div>}
                </div>
                <div className="shrink-0 text-right">
                  <div className="text-2xl font-black">{rupees(localTotal(o))}</div>
                  <div className="text-base font-bold text-muted">{itemLabel(o.items.reduce((s, i) => s + i.quantity, 0))}</div>
                </div>
              </div>
              <ItemChips items={o.items} />
            </Card>
          </Link>
        ))}

        {isLoading && online && <Loading />}
        {data?.data.map((o) => (
          <Card key={o.id}>
            <Link to={`/orders/${o.id}`} className="block">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-[20px] font-black">{o.order_number}</div>
                  <div className="text-base font-bold text-muted">{o.customer_name ?? 'No name'} · {dateTime(o.created_at)}</div>
                  {o.created_offline && <Badge tone="grey">Taken offline</Badge>}
                </div>
                <div className="shrink-0 text-right">
                  <div className="text-2xl font-black">{rupees(o.subtotal)}</div>
                  <div className="text-base font-bold text-muted">{itemLabel(Number(o.item_count))}</div>
                </div>
              </div>
              {o.items && <ItemChips items={o.items} />}
            </Link>
            <div className="mt-2.5 grid grid-cols-2 gap-2">
              <Link to={`/order/${o.id}`} className="flex min-h-[52px] items-center justify-center rounded-2xl border-2 border-line text-lg font-black">Add items</Link>
              <Link to={`/order/${o.id}?view=bill`} className="flex min-h-[52px] items-center justify-center rounded-2xl bg-berry text-lg font-black text-white">BILL IT</Link>
            </div>
          </Card>
        ))}
        {!isLoading && online && !data?.data.length && !shownLocal.length && <Empty>No orders waiting to be billed.</Empty>}
      </div>
    </div>
  );
}
