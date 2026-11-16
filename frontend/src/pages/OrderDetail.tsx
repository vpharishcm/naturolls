import { useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { MergeModal, MoveItemsModal } from '../components/MergeMoveModals';
import { CustomerModal } from './OrderScreen';
import { usePrint } from '../components/Receipt';
import { BackLink, Badge, Button, Card, Chip, ErrorBanner, Field, inputClass, Loading, Modal, PinField, useBusy } from '../components/ui';
import { EditPaymentModal } from '../components/EditPaymentModal';
import { useCatalog } from '../hooks/useCatalog';
import { api } from '../lib/api';
import { dateTime, qty, rupees, withCategory } from '../lib/format';
import { CategoryTag } from '../components/CategoryTag';
import type { Invoice, Order, OrderItem } from '../lib/types';
import { uuid } from '../lib/uuid';
import { useApp } from '../stores/app';

const tone = { unbilled: 'low', billed: 'ok', cancelled: 'out', merged: 'grey' } as const;

export default function OrderDetail() {
  const { id } = useParams();
  const qc = useQueryClient();
  const notify = useApp((s) => s.notify);
  const key = ['orders', 'detail', id];
  const { data: order, isLoading, error } = useQuery({ queryKey: key, queryFn: () => api.get<Order>(`/orders/${id}`) });
  const [cancelling, setCancelling] = useState(false);
  const [editing, setEditing] = useState(false);
  const [removing, setRemoving] = useState<OrderItem | null>(null);
  const [merging, setMerging] = useState(false);
  const [moving, setMoving] = useState(false);
  const navigate = useNavigate();
  const { busy, error: actionError, run } = useBusy();

  if (isLoading) return <Loading />;
  if (!order) return <div className="p-4"><ErrorBanner error={error} /></div>;

  const set = (o: Order) => qc.setQueryData(key, o);
  const open = order.status === 'unbilled';
  const act = (fn: () => Promise<Order>) => run(async () => set(await fn()));

  const changeQty = (item: OrderItem, quantity: number) => {
    if (quantity < Number(item.served_qty)) {
      setRemoving({ ...item, quantity: String(quantity) });
      return;
    }
    act(() => api.patch<Order>(`/orders/${order.id}/items/${item.id}`, { quantity }));
  };

  return (
    <div className="flex flex-col gap-3 px-4 pb-6 pt-[18px]">
      <BackLink to={open ? '/to-bill' : '/orders'} label={open ? 'Orders to Be Billed' : 'All Orders'} />
      <div>
        <h1 className="text-[28px] font-black leading-tight">{order.order_number}</h1>
        <div className="text-base font-bold text-muted">{dateTime(order.created_at)}{order.created_by_name ? ` · ${order.created_by_name}` : ''}</div>
        <Badge tone={tone[order.status]}>{order.status[0].toUpperCase() + order.status.slice(1)}</Badge>
        {order.cancel_reason && <div className="mt-1 text-base font-bold text-muted">Reason: {order.cancel_reason}</div>}
        {order.merged_into_id && <div className="mt-1 text-base font-bold text-muted">Items moved to <Link to={`/orders/${order.merged_into_id}`} className="font-black text-berry underline">{order.merged_into_number ?? 'another order'}</Link></div>}
      </div>

      <Card className="flex items-center justify-between gap-3">
        <div className="min-w-0 text-[18px] font-black">{order.customer_name ?? 'No customer name'}{order.customer_mobile ? <span className="block text-base font-bold text-muted">{order.customer_mobile}</span> : null}</div>
        {open && <Button tone="plain" onClick={() => setEditing(true)}>Edit</Button>}
      </Card>

      <ErrorBanner error={actionError} />

      {order.items?.map((item) => {
        const unserved = Number(item.quantity) - Number(item.served_qty) - Number(item.stock_shortfall);
        return (
          <Card key={item.id} className="flex flex-col gap-2">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="text-[20px] font-black">{item.name}<CategoryTag name={item.category_name} /></div>
                <div className="text-base font-bold text-muted">{qty(item.quantity)} × {rupees(item.unit_price)}{Number(item.served_qty) > 0 ? ` · ${qty(item.served_qty)} served` : ''}</div>
              </div>
              <div className="text-2xl font-black">{rupees(item.line_total)}</div>
            </div>
            {item.conflict_note && <div className="rounded-xl bg-rust-soft px-3 py-2 text-[16px] font-extrabold text-rust">{item.conflict_note}</div>}
            {open && (
              <div className="flex flex-wrap items-center gap-2">
                <button type="button" disabled={busy} aria-label={`Remove one ${item.name}`} onClick={() => changeQty(item, Number(item.quantity) - 1)} className="h-[52px] w-[52px] rounded-2xl bg-sand text-[30px] font-black">−</button>
                <button type="button" disabled={busy} aria-label={`Add one ${item.name}`} onClick={() => changeQty(item, Number(item.quantity) + 1)} className="h-[52px] w-[52px] rounded-2xl bg-berry text-[30px] font-black text-white">+</button>
                {unserved > 0 && <Button tone="mint" disabled={busy} onClick={() => act(() => api.post<Order>(`/orders/${order.id}/items/${item.id}/serve`))}>Served</Button>}
                {Number(item.stock_shortfall) > 0 && <Button tone="plain" disabled={busy} onClick={() => act(() => api.post<Order>(`/orders/${order.id}/items/${item.id}/resolve`))}>Try stock again</Button>}
                <Button tone="danger" disabled={busy} onClick={() => changeQty(item, 0)}>Remove</Button>
              </div>
            )}
          </Card>
        );
      })}

      <Card className="flex items-center justify-between text-[20px] font-black"><span>Total</span><span className="text-[28px]">{rupees(order.invoice_total ?? order.subtotal)}</span></Card>

      {open && (
        <div className="grid grid-cols-2 gap-2">
          <Link to={`/order/${order.id}`} className="flex min-h-[68px] items-center justify-center rounded-[20px] border-2 border-ink bg-white text-[20px] font-black">Add items</Link>
          <Link to={`/order/${order.id}?view=bill`} className="flex min-h-[68px] items-center justify-center rounded-[20px] bg-berry text-[20px] font-black text-white">BILL IT</Link>
          <Button tone="plain" disabled={!order.items?.length} onClick={() => setMoving(true)}>Move items</Button>
          <Button tone="plain" onClick={() => setMerging(true)}>Merge into another order</Button>
          <Button tone="danger" className="col-span-2" onClick={() => setCancelling(true)}>Cancel this order</Button>
        </div>
      )}

      {order.invoice_id && <InvoicePanel key={order.version} invoiceId={order.invoice_id} items={order.items ?? []} orderKey={key} />}

      {editing && <CustomerModal order={order} onClose={() => setEditing(false)} onSaved={set} />}
      {cancelling && <CancelModal order={order} onClose={() => setCancelling(false)} onDone={(o) => { set(o); setCancelling(false); qc.invalidateQueries({ queryKey: ['orders'] }); notify('Order cancelled.', 'ok'); }} />}
      {merging && <MergeModal order={order} onClose={() => setMerging(false)} onDone={({ target }) => {
        setMerging(false);
        qc.invalidateQueries({ queryKey: ['orders'] });
        notify(`Merged into ${target.order_number}.`, 'ok');
        navigate(`/orders/${target.id}`);
      }} />}
      {moving && <MoveItemsModal order={order} onClose={() => setMoving(false)} onDone={({ source, target }) => {
        setMoving(false);
        qc.invalidateQueries({ queryKey: ['orders'] });
        notify(`Moved to ${target.order_number}.`, 'ok');
        if (source.status === 'merged') navigate(`/orders/${target.id}`);
        else set(source);
      }} />}
      {removing && <ServedModal order={order} item={removing} onClose={() => setRemoving(null)} onDone={(o) => { set(o); setRemoving(null); }} />}
    </div>
  );
}

function CancelModal({ order, onClose, onDone }: { order: Order; onClose: () => void; onDone: (o: Order) => void }) {
  const [reason, setReason] = useState('');
  const [pin, setPin] = useState('');
  const { busy, error, run } = useBusy();
  return (
    <Modal title={`Cancel ${order.order_number}?`} onClose={onClose} footer={
      <button type="button" disabled={!reason.trim() || busy} onClick={() => run(async () => onDone(await api.post<Order>(`/orders/${order.id}/cancel`, { reason, manager_pin: pin || undefined })))} className="h-[68px] w-full rounded-[20px] bg-rust text-[20px] font-black text-white disabled:bg-line-strong">YES, CANCEL ORDER</button>
    }>
      <ErrorBanner error={error} />
      <div className="text-[17px] font-bold text-muted">Items not yet served go back to stock. Items already served stay deducted.</div>
      <Field label="Reason"><input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
      <PinField value={pin} onChange={setPin} />
    </Modal>
  );
}

/** The item has left the counter, so stock stays deducted unless someone authorised says otherwise. */
function ServedModal({ order, item, onClose, onDone }: { order: Order; item: OrderItem; onClose: () => void; onDone: (o: Order) => void }) {
  const [pin, setPin] = useState('');
  const { busy, error, run } = useBusy();
  const send = (returnToStock: boolean) => run(async () => onDone(await api.patch<Order>(`/orders/${order.id}/items/${item.id}`, { quantity: Number(item.quantity), return_to_stock: returnToStock, manager_pin: pin || undefined })));
  return (
    <Modal title="This was already served" onClose={onClose}>
      <ErrorBanner error={error} />
      <div className="text-[17px] font-bold text-muted">Removing it takes it off the bill. Did the item come back in sellable condition?</div>
      <button type="button" disabled={busy} onClick={() => send(false)} className="min-h-[60px] rounded-[18px] bg-ink text-lg font-black text-white">No. Remove, keep stock deducted</button>
      <PinField value={pin} onChange={setPin} />
      <button type="button" disabled={busy} onClick={() => send(true)} className="min-h-[60px] rounded-[18px] border-2 border-ink bg-white text-lg font-black">Yes. Return it to stock</button>
    </Modal>
  );
}

interface EditLine { key: string; id?: number; product_id: number; product_variant_id?: number; name: string; category?: string | null; price: string; quantity: number }
interface EditPreview { total: string; previous_total: string; difference: string; changed: boolean }

/** Corrects a finished bill. The server works out the new total, so discounts and points stay exact. */
function EditBillModal({ invoice, items, onClose, onDone }: { invoice: Invoice; items: OrderItem[]; onClose: () => void; onDone: () => void }) {
  const { products } = useCatalog();
  const [lines, setLines] = useState<EditLine[]>(() => items.map((i) => ({ key: `i${i.id}`, id: i.id, product_id: i.product_id, name: i.name, category: i.category_name, price: i.unit_price, quantity: Number(i.quantity) })));
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState('cash');
  const [restock, setRestock] = useState(true);
  const [saveKey] = useState(() => uuid());
  const { busy, error, run } = useBusy();

  const body = { expected_edit_count: invoice.edit_count, items: lines.filter((l) => l.quantity > 0).map(({ id, product_id, product_variant_id, quantity }) => (id ? { id, quantity } : { product_id, product_variant_id, quantity })) };
  const preview = useQuery({
    queryKey: ['bill-edit', invoice.id, body],
    queryFn: () => api.post<EditPreview>(`/invoices/${invoice.id}/edit/preview`, body),
    retry: false,
    placeholderData: keepPreviousData,
  });
  const reduced = lines.some((l) => l.id && l.quantity < Number(items.find((i) => i.id === l.id)?.quantity ?? 0));
  const difference = Number(preview.data?.difference ?? 0);

  const change = (key: string, by: number) => setLines((v) => v.map((l) => (l.key === key ? { ...l, quantity: Math.max(0, l.quantity + by) } : l)));
  const add = (value: string) => {
    if (!value) return;
    const [pid, vid] = value.split(':').map(Number);
    const product = products.find((p) => p.id === pid);
    if (!product) return;
    const variant = product.variants?.find((x) => x.id === vid);
    const key = `n${pid}-${vid ?? 0}`;
    setLines((v) => (v.some((l) => l.key === key)
      ? v.map((l) => (l.key === key ? { ...l, quantity: l.quantity + 1 } : l))
      : [...v, { key, product_id: pid, product_variant_id: variant?.id, name: variant ? `${product.name} · ${variant.name}` : product.name, category: product.category_name, price: variant?.price ?? product.price, quantity: 1 }]));
  };
  const save = () => run(async () => {
    await api.post(`/invoices/${invoice.id}/edit`, { ...body, reason, return_to_stock: restock, settlement_method: difference !== 0 ? method : undefined }, saveKey);
    onDone();
  });

  return (
    <Modal title={`Edit ${invoice.invoice_number}`} onClose={onClose} footer={
      <Button big className="w-full" disabled={busy || !reason.trim() || !preview.data?.changed || preview.isError || preview.isFetching} onClick={save}>SAVE EDITED BILL</Button>
    }>
      <ErrorBanner error={error ?? preview.error} />
      {invoice.edit_until && <div className="text-[17px] font-bold text-muted">You can edit this bill until {dateTime(invoice.edit_until)}.</div>}
      {lines.map((l) => (
        <div key={l.key} className="flex items-center justify-between gap-2 rounded-2xl bg-white px-3 py-2">
          <div className="min-w-0">
            <div className={`text-[18px] font-black ${l.quantity === 0 ? 'text-muted line-through' : ''}`}>{l.name}<CategoryTag name={l.category} /></div>
            <div className="text-base font-bold text-muted">{rupees(l.price)} × {l.quantity}</div>
          </div>
          <div className="flex shrink-0 gap-2">
            <button type="button" aria-label={`Remove one ${l.name}`} disabled={l.quantity === 0} onClick={() => change(l.key, -1)} className="h-[52px] w-[52px] rounded-2xl bg-sand text-[30px] font-black disabled:opacity-40">−</button>
            <button type="button" aria-label={`Add one ${l.name}`} onClick={() => change(l.key, 1)} className="h-[52px] w-[52px] rounded-2xl bg-berry text-[30px] font-black text-white">+</button>
          </div>
        </div>
      ))}
      <Field label="Add an item">
        <select className={inputClass} value="" onChange={(e) => add(e.target.value)}>
          <option value="">Choose an item</option>
          {products.filter((p) => p.is_active).flatMap((p) => (p.variants?.length
            ? p.variants.filter((x) => x.is_active).map((x) => <option key={`${p.id}:${x.id}`} value={`${p.id}:${x.id}`}>{withCategory(`${p.name} · ${x.name}`, p.category_name)} · {rupees(x.price)}</option>)
            : [<option key={p.id} value={String(p.id)}>{withCategory(p.name, p.category_name)} · {rupees(p.price)}</option>]))}
        </select>
      </Field>
      {preview.data && (
        <div className="rounded-2xl bg-white px-3 py-2 text-[18px] font-black">
          <div className="flex justify-between"><span>New total</span><span>{rupees(preview.data.total)}</span></div>
          {difference > 0 && <div className="flex justify-between text-berry"><span>Collect from customer</span><span>{rupees(difference)}</span></div>}
          {difference < 0 && <div className="flex justify-between text-rust"><span>Give back to customer</span><span>{rupees(-difference)}</span></div>}
        </div>
      )}
      {difference !== 0 && (
        <div className="flex gap-2">
          {[['cash', 'Cash'], ['upi', 'UPI'], ['card', 'Card']].map(([id, label]) => <Chip key={id} active={method === id} onClick={() => setMethod(id)}>{label}</Chip>)}
        </div>
      )}
      {reduced && <label className="flex min-h-[52px] items-center gap-3 text-lg font-black"><input type="checkbox" className="h-7 w-7" checked={restock} onChange={(e) => setRestock(e.target.checked)} /> Put removed items back in stock</label>}
      <Field label="Reason"><input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
    </Modal>
  );
}

function InvoicePanel({ invoiceId, items, orderKey }: { invoiceId: number; items: OrderItem[]; orderKey: unknown[] }) {
  const qc = useQueryClient();
  const notify = useApp((s) => s.notify);
  const key = ['invoice', invoiceId];
  const { data: invoice } = useQuery({ queryKey: key, queryFn: () => api.get<Invoice>(`/invoices/${invoiceId}`) });
  const { print, state, outcome, portal } = usePrint();
  const [refunding, setRefunding] = useState(false);
  const [editingBill, setEditingBill] = useState(false);
  const [editingPayment, setEditingPayment] = useState(false);
  if (!invoice) return null;
  const discount = Number(invoice.promotion_discount) + Number(invoice.manual_discount) + Number(invoice.loyalty_discount) + Number(invoice.dice_discount ?? 0);
  return (
    <Card className="flex flex-col gap-2">
      {portal}
      <h2 className="text-[20px] font-black">{invoice.invoice_number}</h2>
      {invoice.edited_at && <div><Badge tone="low">Edited {dateTime(invoice.edited_at)}</Badge></div>}
      <div className="text-[17px] font-extrabold">
        <div className="flex justify-between"><span>Subtotal</span><span>{rupees(invoice.subtotal)}</span></div>
        {discount > 0 && <div className="flex justify-between text-mint"><span>Discount</span><span>−{rupees(discount)}</span></div>}
        {Number(invoice.tax_amount) > 0 && <div className="flex justify-between text-muted"><span>GST</span><span>{rupees(invoice.tax_amount)}</span></div>}
        <div className="flex justify-between"><span>Paid</span><span>{rupees(invoice.total)}</span></div>
        {Number(invoice.refunded_amount) > 0 && <div className="flex justify-between text-rust"><span>Refunded</span><span>{rupees(invoice.refunded_amount)}</span></div>}
      </div>
      {invoice.dice_prize && <div className="text-[16px] font-extrabold text-berry">🎲 {invoice.dice_prize}{(invoice.dice_rolls?.length ?? 0) > 1 ? ` (rolled ${invoice.dice_rolls!.join(', ')})` : ''}</div>}
      {invoice.payments?.map((p) => (
        <div key={p.id} className="flex justify-between text-base font-bold text-muted">
          <span>{p.type === 'refund' ? 'Refund · ' : Number(p.amount) < 0 ? 'Given back · ' : ''}{p.method.toUpperCase()}{p.reference ? ` · ${p.reference}` : ''}</span>
          <span>{rupees(Math.abs(Number(p.amount)))}</span>
        </div>
      ))}
      {state === 'failed' && <div role="alert" className="rounded-xl bg-rust-soft px-3 py-2 text-[16px] font-extrabold text-rust">Did not print. {outcome?.error}</div>}
      {state === 'done' && <div role="status" className="text-[16px] font-extrabold text-mint">Printed.</div>}
      <div className="grid grid-cols-2 gap-2">
        <Button tone="plain" disabled={state === 'printing'} onClick={() => print(invoice.id).then(() => qc.invalidateQueries({ queryKey: key }))}>{invoice.print_count > 0 ? 'Reprint' : 'Print'}</Button>
        <Button tone="danger" onClick={() => setRefunding(true)}>Refund</Button>
        {invoice.can_edit && <Button tone="plain" className="col-span-2" onClick={() => setEditingBill(true)}>Edit bill</Button>}
        {invoice.can_edit_payment && <Button tone="plain" className="col-span-2" onClick={() => setEditingPayment(true)}>Change payment mode</Button>}
      </div>
      {editingPayment && <EditPaymentModal invoice={invoice} onClose={() => setEditingPayment(false)} onDone={(updated) => { setEditingPayment(false); qc.setQueryData(key, updated); qc.invalidateQueries({ queryKey: ['payments'] }); notify('Payment mode updated.', 'ok'); }} />}
      {editingBill && <EditBillModal invoice={invoice} items={items} onClose={() => setEditingBill(false)} onDone={() => { setEditingBill(false); qc.invalidateQueries({ queryKey: key }); qc.invalidateQueries({ queryKey: orderKey }); notify('Bill updated. Print the new bill.', 'ok'); }} />}
      {refunding &&<RefundModal invoice={invoice} onClose={() => setRefunding(false)} onDone={() => { setRefunding(false); qc.invalidateQueries({ queryKey: key }); }} />}
    </Card>
  );
}

export function RefundModal({ invoice, onClose, onDone }: { invoice: Invoice; onClose: () => void; onDone: () => void }) {
  const left = Number(invoice.total) - Number(invoice.refunded_amount);
  const [amount, setAmount] = useState(String(left));
  const [method, setMethod] = useState('cash');
  const [reason, setReason] = useState('');
  const [pin, setPin] = useState('');
  const { busy, error, run } = useBusy();
  return (
    <Modal title="Refund" onClose={onClose} footer={
      <button type="button" disabled={!reason.trim() || busy} onClick={() => run(async () => { await api.post(`/invoices/${invoice.id}/refunds`, { amount: Number(amount), method, reason, manager_pin: pin || undefined }, undefined); onDone(); })} className="h-[68px] w-full rounded-[20px] bg-rust text-[20px] font-black text-white disabled:bg-line-strong">REFUND {rupees(amount)}</button>
    }>
      <ErrorBanner error={error} />
      <div className="text-[17px] font-bold text-muted">Up to {rupees(left)} can be refunded. Stock is not returned by a refund; correct it in Inventory if items came back.</div>
      <Field label="Amount"><input className={inputClass} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
      <Field label="Paid back by">
        <select className={inputClass} value={method} onChange={(e) => setMethod(e.target.value)}>
          <option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option>
        </select>
      </Field>
      <Field label="Reason"><input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
      <PinField value={pin} onChange={setPin} />
    </Modal>
  );
}
