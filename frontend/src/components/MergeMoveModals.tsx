import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ItemChips } from './ItemChips';
import { Button, Chip, Empty, ErrorBanner, Loading, Modal, useBusy } from './ui';
import { api } from '../lib/api';
import { dateTime, itemLabel, qty, rupees } from '../lib/format';
import { CategoryTag } from './CategoryTag';
import type { Order } from '../lib/types';
import { uuid } from '../lib/uuid';

interface MoveResult { source: Order; target: Order }

/** The other open orders, with their items, to pick where items should go. */
function OrderPicker({ exclude, value, onChange }: { exclude: number; value: number | null; onChange: (o: Order) => void }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['orders', 'unbilled', 'picker'],
    queryFn: () => api.page<Order>('/orders', { status: 'unbilled', per_page: 100, include_items: 1 }),
  });
  const orders = data?.data.filter((o) => o.id !== exclude) ?? [];
  if (isLoading) return <Loading />;
  return (
    <div className="flex flex-col gap-2" role="radiogroup" aria-label="Choose an open order">
      <ErrorBanner error={error} />
      {!orders.length && <Empty>No other open orders.</Empty>}
      {orders.map((o) => (
        <button key={o.id} type="button" role="radio" aria-checked={value === o.id} onClick={() => onChange(o)}
          className={`rounded-2xl bg-white p-3 text-left ${value === o.id ? 'border-[3px] border-berry' : 'border-2 border-line-soft'}`}>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <div className="text-[19px] font-black">{o.order_number}</div>
              <div className="text-[15px] font-bold text-muted">{o.customer_name ?? 'No name'} · {dateTime(o.created_at)}</div>
            </div>
            <div className="shrink-0 text-right text-[19px] font-black">{rupees(o.subtotal)}</div>
          </div>
          {o.items && <ItemChips items={o.items} />}
        </button>
      ))}
    </div>
  );
}

/** Joins an order made by mistake into the customer's first order. */
export function MergeModal({ order, onClose, onDone }: { order: Order; onClose: () => void; onDone: (r: MoveResult) => void }) {
  const [target, setTarget] = useState<Order | null>(null);
  const [saveKey] = useState(() => uuid());
  const { busy, error, run } = useBusy();
  const count = (order.items ?? []).reduce((s, i) => s + Number(i.quantity), 0);
  const merge = () => run(async () => {
    if (!target) return;
    onDone(await api.post<MoveResult>(`/orders/${order.id}/merge`, { target_order_id: target.id, expected_version: order.version }, saveKey));
  });

  return (
    <Modal title={`Merge ${order.order_number}`} onClose={onClose} footer={
      <Button big className="w-full" disabled={busy || !target} onClick={merge}>{target ? `MERGE INTO ${target.order_number}` : 'CHOOSE AN ORDER'}</Button>
    }>
      <ErrorBanner error={error} />
      <div className="text-[17px] font-bold text-muted">
        {count > 0 ? `All ${itemLabel(count)} move to the order you choose` : 'This order is empty. Choose the order to keep'}, and {order.order_number} closes as merged. Stock does not change.
      </div>
      <OrderPicker exclude={order.id} value={target?.id ?? null} onChange={setTarget} />
    </Modal>
  );
}

/** Splits an order: some items go to a new order or to another open one. */
export function MoveItemsModal({ order, onClose, onDone }: { order: Order; onClose: () => void; onDone: (r: MoveResult) => void }) {
  const items = order.items ?? [];
  const [picked, setPicked] = useState<Record<number, number>>({});
  const [toNew, setToNew] = useState(true);
  const [target, setTarget] = useState<Order | null>(null);
  const [saveKey] = useState(() => uuid());
  const { busy, error, run } = useBusy();

  const moving = items.filter((i) => (picked[i.id] ?? 0) > 0);
  const count = moving.reduce((s, i) => s + picked[i.id], 0);
  const everything = moving.length === items.length && items.every((i) => picked[i.id] >= Number(i.quantity));
  const blockedAll = toNew && everything;
  const change = (id: number, max: number, by: number) => setPicked((p) => ({ ...p, [id]: Math.min(max, Math.max(0, (p[id] ?? 0) + by)) }));
  const move = () => run(async () => {
    onDone(await api.post<MoveResult>(`/orders/${order.id}/move`, {
      target_order_id: toNew ? undefined : target?.id,
      items: moving.map((i) => ({ id: i.id, quantity: picked[i.id] })),
      expected_version: order.version,
    }, saveKey));
  });
  const label = count === 0 ? 'CHOOSE ITEMS TO MOVE'
    : toNew ? `MOVE ${itemLabel(count).toUpperCase()} TO A NEW ORDER`
      : target ? `MOVE ${itemLabel(count).toUpperCase()} TO ${target.order_number}` : 'CHOOSE AN ORDER';

  return (
    <Modal title={`Move items from ${order.order_number}`} onClose={onClose} footer={
      <Button big className="w-full" disabled={busy || count === 0 || blockedAll || (!toNew && !target)} onClick={move}>{label}</Button>
    }>
      <ErrorBanner error={error} />
      <div className="text-[17px] font-bold text-muted">Choose how many of each item to move. Served items stay served and stock does not change.</div>
      {items.map((i) => {
        const max = Number(i.quantity);
        const n = picked[i.id] ?? 0;
        return (
          <div key={i.id} className={`flex items-center justify-between gap-2 rounded-2xl bg-white px-3 py-2 ${n > 0 ? 'border-[3px] border-berry' : 'border-2 border-transparent'}`}>
            <div className="min-w-0">
              <div className="text-[18px] font-black">{i.name}<CategoryTag name={i.category_name} /></div>
              <div className="text-base font-bold text-muted">Move <span className="text-berry">{qty(n)}</span> of {qty(max)} · {rupees(i.unit_price)} each</div>
            </div>
            <div className="flex shrink-0 gap-2">
              <button type="button" aria-label={`Move one less ${i.name}`} disabled={n === 0} onClick={() => change(i.id, max, -1)} className="h-[52px] w-[52px] rounded-2xl bg-sand text-[30px] font-black disabled:opacity-40">−</button>
              <button type="button" aria-label={`Move one more ${i.name}`} disabled={n >= max} onClick={() => change(i.id, max, 1)} className="h-[52px] w-[52px] rounded-2xl bg-berry text-[30px] font-black text-white disabled:opacity-40">+</button>
            </div>
          </div>
        );
      })}
      <div className="text-[18px] font-black">Move them to</div>
      <div className="flex flex-wrap gap-2">
        <Chip active={toNew} onClick={() => setToNew(true)}>A new order</Chip>
        <Chip active={!toNew} onClick={() => setToNew(false)}>Another open order</Chip>
      </div>
      {blockedAll && <div role="alert" className="text-[17px] font-extrabold text-rust">Leave at least one item here. To move everything to another order, use Merge.</div>}
      {!toNew && <OrderPicker exclude={order.id} value={target?.id ?? null} onChange={setTarget} />}
    </Modal>
  );
}
