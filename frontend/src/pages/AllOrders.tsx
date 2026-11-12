import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Badge, Card, Chip, Empty, ErrorBanner, Field, inputClass, Loading, PageHeader, Pager } from '../components/ui';
import { api } from '../lib/api';
import { dateTime, itemLabel, rupees } from '../lib/format';
import type { Order } from '../lib/types';

const STATUSES = [{ id: '', label: 'All' }, { id: 'unbilled', label: 'Unbilled' }, { id: 'billed', label: 'Billed' }, { id: 'cancelled', label: 'Cancelled' }];
export const statusTone = { unbilled: 'low', billed: 'ok', cancelled: 'out' } as const;

export default function AllOrders() {
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useQuery({
    queryKey: ['orders', 'all', status, search, from, to, page],
    queryFn: () => api.page<Order>('/orders', { status: status || undefined, search: search || undefined, from: from || undefined, to: to || undefined, page }),
    placeholderData: keepPreviousData,
  });

  return (
    <div className="flex flex-col pb-6">
      <PageHeader title="All Orders" />
      <div className="flex flex-col gap-2.5 px-4">
        <input aria-label="Search orders" placeholder="Order number, name or mobile" className={inputClass} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
        <div className="scroll-x flex gap-2">
          {STATUSES.map((s) => <Chip key={s.id} active={status === s.id} onClick={() => { setStatus(s.id); setPage(1); }}>{s.label}</Chip>)}
        </div>
        <div className="grid grid-cols-2 gap-2">
          <Field label="From"><input type="date" className={inputClass} value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} /></Field>
          <Field label="To"><input type="date" className={inputClass} value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} /></Field>
        </div>
        <ErrorBanner error={error} />
        {isLoading && <Loading />}
        {data?.data.map((o) => (
          <Link key={o.id} to={`/orders/${o.id}`}>
            <Card>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-[20px] font-black">{o.order_number}</div>
                  <div className="text-base font-bold text-muted">{o.customer_name ?? 'No name'} · {dateTime(o.created_at)}</div>
                  <Badge tone={statusTone[o.status]}>{o.status[0].toUpperCase() + o.status.slice(1)}</Badge>
                  {o.invoice_number && <span className="ml-2 text-[15px] font-bold text-muted">{o.invoice_number}</span>}
                </div>
                <div className="shrink-0 text-right">
                  <div className="text-2xl font-black">{rupees(o.invoice_total ?? o.subtotal)}</div>
                  <div className="text-base font-bold text-muted">{itemLabel(Number(o.item_count))}</div>
                </div>
              </div>
            </Card>
          </Link>
        ))}
        {!isLoading && !data?.data.length && <Empty>No orders match.</Empty>}
        <Pager meta={data?.meta} onPage={setPage} />
      </div>
    </div>
  );
}
