import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ProductArt } from '../components/ProductArt';
import { Button, ErrorBanner, Field, inputClass, Loading, Modal, PinField, useBusy } from '../components/ui';
import { usePrint } from '../components/Receipt';
import { useCatalog } from '../hooks/useCatalog';
import { useOrderDraft, type DraftLine } from '../hooks/useOrderDraft';
import { api, ApiError } from '../lib/api';
import { itemLabel, qty, rupees } from '../lib/format';
import type { BillPreview, Customer, Invoice, Order, Product, Variant } from '../lib/types';
import { uuid } from '../lib/uuid';
import { useApp } from '../stores/app';

type View = 'menu' | 'bill' | 'done';
const QUICK = [{ id: 'frequent', label: 'Frequent' }, { id: 'recent', label: 'Recent' }] as const;
const METHODS = [{ id: 'cash', label: 'Cash' }, { id: 'upi', label: 'UPI' }, { id: 'card', label: 'Card' }] as const;
const sectionClass = 'min-h-[64px] min-w-[104px] shrink-0 rounded-[18px] px-2 py-1.5 text-lg font-black leading-tight md:min-h-[50px] md:min-w-[88px] md:text-[17px]';

type ItemLayout = 'cards' | 'list';
const LAYOUT_KEY = 'naturolls.itemLayout';
function savedLayout(): ItemLayout {
  try {
    return localStorage.getItem(LAYOUT_KEY) === 'list' ? 'list' : 'cards';
  } catch {
    return 'cards';
  }
}

/** Remounts per order, so a New Order after a bill always starts empty. */
export default function OrderScreen() {
  const params = useParams();
  const [search] = useSearchParams();
  return <OrderScreenBody key={`${params.id}:${search.get('fresh') ?? ''}`} />;
}

function OrderScreenBody() {
  const params = useParams();
  const [search] = useSearchParams();
  const navigate = useNavigate();
  const orderId = params.id && params.id !== 'new' && !params.id.startsWith('local-') ? Number(params.id) : undefined;
  const localId = params.id?.startsWith('local-') ? params.id.slice(6) : undefined;
  const draft = useOrderDraft({ orderId, localId });
  const [view, setView] = useState<View>(search.get('view') === 'bill' ? 'bill' : 'menu');
  const [invoice, setInvoice] = useState<Invoice | null>(null);

  if (draft.loading) return <Loading />;
  if (draft.order && draft.order.status !== 'unbilled' && view !== 'done') {
    return (
      <div className="flex flex-col items-start gap-3 p-4">
        <h1 className="text-[28px] font-black">Order {draft.order.order_number} is {draft.order.status}.</h1>
        <Link className="font-black text-berry underline" to={`/orders/${draft.order.id}`}>See this order</Link>
      </div>
    );
  }
  if (view === 'done' && invoice) {
    return <DoneView invoice={invoice} onNew={() => navigate(`/order/new?fresh=${Date.now()}`, { replace: true })} />;
  }
  if (view === 'bill') {
    return <BillView draft={draft} onBack={() => setView('menu')} onPaid={(inv) => { setInvoice(inv); setView('done'); }} />;
  }
  return <MenuView draft={draft} onBill={() => setView('bill')} />;
}

type Draft = ReturnType<typeof useOrderDraft>;

function MenuView({ draft, onBill }: { draft: Draft; onBill: () => void }) {
  const { products, categories, available, loading } = useCatalog();
  const settings = useApp((s) => s.settings);
  const navigate = useNavigate();
  const [cat, setCat] = useState<string>('');
  const [term, setTerm] = useState('');
  const [picking, setPicking] = useState<Product | null>(null);
  const [showCustomer, setShowCustomer] = useState(false);
  const [layout, setLayoutState] = useState<ItemLayout>(savedLayout);
  const qc = useQueryClient();
  const notify = useApp((s) => s.notify);

  const quick = useQuery({ queryKey: ['quick-lists'], queryFn: () => api.get<Record<string, number[]>>('/products/quick-lists'), staleTime: 60000, retry: false });

  const sellable = useMemo(() => {
    const withProducts = new Set(products.map((p) => p.category_id));
    return categories.filter((c) => c.is_active && withProducts.has(c.id));
  }, [categories, products]);
  const activeCat = cat || (sellable[0] ? String(sellable[0].id) : '');

  const shown = useMemo(() => {
    const q = term.trim().toLowerCase();
    if (q) return products.filter((p) => p.name.toLowerCase().includes(q) || p.sku?.toLowerCase().includes(q) || p.code === q);
    if (activeCat === 'favourites') return products.filter((p) => p.is_favourite);
    const list = quick.data?.[activeCat];
    if (list) return products.filter((p) => list.includes(p.id));
    return products.filter((p) => String(p.category_id) === activeCat);
  }, [products, term, activeCat, quick.data]);
  const hasFavourites = products.some((p) => p.is_favourite);

  const toggleFavourite = async (p: Product) => {
    const set = (value: boolean) => qc.setQueryData<Product[]>(['products'], (rows) => rows?.map((x) => (x.id === p.id ? { ...x, is_favourite: value } : x)));
    set(!p.is_favourite);
    try {
      await api.post(`/products/${p.id}/favourite`, { is_favourite: !p.is_favourite });
    } catch (e) {
      set(p.is_favourite);
      notify((e as ApiError).isNetwork ? 'Favourites need a connection.' : (e as ApiError).message, 'error');
    }
  };

  const setLayout = (next: ItemLayout) => {
    setLayoutState(next);
    try { localStorage.setItem(LAYOUT_KEY, next); } catch { /* the choice just lasts for this visit */ }
  };

  const lineFor = (p: Product, v: Variant | null): DraftLine => ({
    key: v ? v.stock_key : p.stock_key,
    product: p,
    variant: v,
    unitPrice: Number(v ? v.price : p.price),
  });

  const priceOf = (key: string) => {
    for (const p of products) {
      if (p.stock_key === key) return Number(p.price);
      const v = p.variants?.find((x) => x.stock_key === key);
      if (v) return Number(v.price);
    }
    return 0;
  };
  const savedPrice = (key: string) => {
    const saved = draft.order?.items?.find((i) => i.stock_key === key) ?? draft.localOrder?.items.find((i) => i.stock_key === key);
    return saved ? Number(saved.unit_price) : priceOf(key);
  };
  const entries = Object.entries(draft.targets).filter(([, q]) => q > 0);
  const count = entries.reduce((s, [, q]) => s + q, 0);
  const total = entries.reduce((s, [k, q]) => s + q * savedPrice(k), 0);
  const number = draft.order?.order_number ?? draft.localOrder?.order_number;
  const hasOutstanding = number && count > 0;

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <header className="flex items-center justify-between gap-3 px-4 pb-2.5 pt-[18px] md:pb-2 md:pt-2.5">
        <div className="min-w-0">
          <div className="truncate text-[15px] font-extrabold text-muted-soft">{String(settings['business.name'] ?? 'Naturolls')}{number ? ` · ${number}` : ''}</div>
          <h1 className="mt-0.5 text-[28px] font-black leading-tight md:text-[24px]">Tap to add</h1>
        </div>
        <div className="flex shrink-0 gap-2">
          <Link to="/to-bill" className="flex min-h-[52px] items-center rounded-[26px] border-2 border-line bg-white px-3.5 text-lg font-extrabold">Back</Link>
          <button type="button" onClick={draft.clearAll} aria-label="Clear all items" className="min-h-[52px] rounded-[26px] border-2 border-line bg-white px-3.5 text-lg font-extrabold">Clear</button>
        </div>
      </header>

      <div className="flex gap-2 px-4 pb-2.5">
        <input aria-label="Search products" placeholder="Search item" value={term} onChange={(e) => setTerm(e.target.value)} className="min-h-[52px] min-w-0 flex-1 rounded-[18px] border-2 border-line bg-white px-4 text-lg font-extrabold" />
        {(draft.order || draft.localOrder) && !draft.localOrder && (
          <button type="button" onClick={() => setShowCustomer(true)} className="min-h-[52px] max-w-[42%] shrink-0 truncate rounded-[18px] border-2 border-line bg-white px-3 text-lg font-black">
            {draft.order?.customer_name ?? '+ Name'}
          </button>
        )}
        <div role="group" aria-label="Show items as" className="flex shrink-0 rounded-[18px] border-2 border-line bg-white p-0.5">
          {(['cards', 'list'] as const).map((m) => (
            <button key={m} type="button" onClick={() => setLayout(m)} aria-pressed={layout === m} className={`min-h-[44px] rounded-[14px] px-3 text-[17px] font-black ${layout === m ? 'bg-berry text-white' : ''}`}>
              {m === 'cards' ? 'Cards' : 'List'}
            </button>
          ))}
        </div>
      </div>

      <nav aria-label="Product sections" className="scroll-x flex gap-2 px-4 pb-3 md:pb-2">
        {(hasFavourites || activeCat === 'favourites') && (
          <button type="button" onClick={() => { setCat('favourites'); setTerm(''); }} aria-pressed={activeCat === 'favourites'}
            className={`${sectionClass} ${activeCat === 'favourites' ? 'border-[3px] border-berry bg-berry-soft' : 'border-2 border-dashed border-line bg-white'}`}>
            ♥ Favourites
          </button>
        )}
        {sellable.map((c) => (
          <button key={c.id} type="button" onClick={() => { setCat(String(c.id)); setTerm(''); }} aria-pressed={activeCat === String(c.id)}
            className={`${sectionClass} flex-auto ${activeCat === String(c.id) ? 'border-[3px] border-berry bg-berry-soft' : 'border-2 border-line bg-white'}`}>
            {c.name}
          </button>
        ))}
        {QUICK.map((c) => (quick.data?.[c.id]?.length ?? 0) > 0 && (
          <button key={c.id} type="button" onClick={() => { setCat(c.id); setTerm(''); }} aria-pressed={activeCat === c.id}
            className={`${sectionClass} ${activeCat === c.id ? 'border-[3px] border-berry bg-berry-soft' : 'border-2 border-dashed border-line bg-white'}`}>
            {c.label}
          </button>
        ))}
      </nav>

      <div className={`grid min-h-0 flex-1 content-start gap-3 overflow-y-auto px-4 pb-4 pt-1 md:gap-2.5 md:pb-2 ${layout === 'cards' ? 'grid-cols-2 sm:grid-cols-3 md:grid-cols-[repeat(auto-fill,minmax(128px,1fr))] port:grid-cols-4 port:gap-2 port:px-3' :'grid-cols-1 md:grid-cols-2 md:gap-2'}`}>
        {loading && <Loading />}
        {shown.map((p) => {
          const hasVariants = (p.variants?.filter((v) => v.is_active).length ?? 0) > 0;
          const n = hasVariants
            ? (p.variants ?? []).reduce((s, v) => s + (draft.targets[v.stock_key] ?? 0), 0)
            : draft.targets[p.stock_key] ?? 0;
          const live = available.get(p.stock_key) ?? (p.available_qty === null ? null : Number(p.available_qty));
          const out = p.track_inventory && !hasVariants && live !== null && live <= 0;
          const low = p.track_inventory && !hasVariants && live !== null && live > 0 && live <= Number(p.min_stock);
          const stock = p.track_inventory && !hasVariants && live !== null && (
            <span className={`text-[15px] font-extrabold ${layout === 'cards' ? 'md:text-[13px] port:text-[14px]' : ''} ${out ? 'text-rust' : low ? 'text-amber-ink' : 'text-muted'}`}>{out ? 'Out of stock' : `${qty(live)} left`}</span>
          );
          const heart = (
            <button type="button" onClick={() => toggleFavourite(p)} aria-pressed={p.is_favourite} aria-label={`${p.is_favourite ? 'Remove' : 'Add'} ${p.name} ${p.is_favourite ? 'from' : 'to'} favourites`} className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[26px] leading-none ${layout === 'cards' ? 'port:h-9 port:w-9 port:text-[22px]' : ''} ${p.is_favourite ? 'text-berry' : 'text-line-strong'}`}>
              {p.is_favourite ? '♥' : '♡'}
            </button>
          );
          const stepClass = `shrink-0 rounded-2xl text-[30px] font-black ${layout === 'list' ? 'h-12 w-12 md:h-[52px] md:w-[52px]' : 'h-[52px] w-[52px] md:h-11 md:w-11 md:rounded-xl md:text-[26px] port:h-12 port:w-12 port:text-[28px]'}`;
          const controls = (
            <>
              <button type="button" aria-label={`Remove one ${p.name}`} onClick={() => (hasVariants ? setPicking(p) : draft.change(lineFor(p, null), -1))} className={`${stepClass} bg-sand`}>−</button>
              <span aria-live="polite" className={`min-w-[30px] text-center text-2xl font-black ${layout === 'cards' ? 'md:min-w-[22px] md:text-xl' : ''}`}>{qty(n)}</span>
              <button type="button" aria-label={`Add one ${p.name}`} disabled={out} onClick={() => (hasVariants ? setPicking(p) : draft.change(lineFor(p, null), 1))} className={`${stepClass} bg-berry text-white disabled:bg-line-strong`}>+</button>
            </>
          );
          if (layout === 'list') {
            return (
              <div key={p.id} className="flex items-center gap-2 rounded-[18px] bg-white py-1.5 pl-2 pr-2.5 shadow-tile">
                {heart}
                <span className="hidden shrink-0 md:block"><ProductArt product={p} size={44} /></span>
                <div className="min-w-0 flex-1">
                  <div className="text-[18px] font-black leading-tight">{p.name}</div>
                  <div className="flex flex-wrap gap-x-2 text-base font-extrabold text-muted"><span>{rupees(p.price)}</span>{stock}</div>
                </div>
                {controls}
              </div>
            );
          }
          return (
            <div key={p.id} className="relative flex flex-col items-center gap-0.5 rounded-[20px] bg-white p-2 shadow-tile md:rounded-2xl md:p-1.5 port:p-2">
              <div className="absolute right-0.5 top-0.5 md:right-0 md:top-0">{heart}</div>
              <div className="flex h-20 items-center justify-center md:h-11 port:h-[60px]"><span className="md:hidden"><ProductArt product={p} /></span><span className="hidden md:block port:[&>*]:!h-[58px] port:[&>*]:!w-[58px]"><ProductArt product={p} size={42} /></span></div>
              <div className="text-center text-[19px] font-black leading-tight md:text-[15px] port:text-[17px]">{p.name}</div>
              <div className="flex flex-col items-center md:flex-row md:flex-wrap md:justify-center md:gap-x-1.5 md:text-[14px]">
                <span className="text-lg font-extrabold text-muted md:text-[15px] port:text-[16px]">{rupees(p.price)}</span>
                {stock}
              </div>
              <div className="mt-auto flex w-full items-center justify-between gap-1 pt-1">{controls}</div>
            </div>
          );
        })}
        {!loading && shown.length === 0 && <div className="col-span-full py-8 text-center text-lg font-bold text-muted">{activeCat === 'favourites' ? 'Tap the heart on an item to add it here.' : 'No items here.'}</div>}
      </div>

      <footer className="flex items-center gap-3 border-t-2 border-line-soft bg-white px-4 pb-5 pt-3.5 md:py-2">
        <div className="min-w-0 flex-1">
          <div className="text-[17px] font-extrabold text-muted">
            {itemLabel(count)}{draft.waitingForNetwork ? ' · will save when online' : draft.saving ? ' · saving' : hasOutstanding ? ' · saved' : ''}
          </div>
          <div className="text-[34px] font-black leading-tight md:text-[28px]">{rupees(total)}</div>
        </div>
        {draft.localOrder ? (
          <button type="button" onClick={() => navigate('/to-bill')} disabled={count === 0} className="h-[68px] rounded-[20px] bg-mint px-5 text-[20px] font-black text-white disabled:bg-line-strong">SAVED · DONE</button>
        ) : (
          <button type="button" onClick={onBill} disabled={count === 0 || !draft.settled} aria-label="Go to bill" className="h-[68px] rounded-[20px] bg-berry px-[26px] text-[22px] font-black text-white disabled:bg-line-strong">BILL IT</button>
        )}
      </footer>

      {picking && (
        <Modal title={picking.name} onClose={() => setPicking(null)}>
          {(picking.variants ?? []).filter((v) => v.is_active).map((v) => {
            const left = available.get(v.stock_key);
            return (
              <div key={v.id} className="flex items-center justify-between gap-2 rounded-[18px] bg-white p-3">
                <div className="min-w-0">
                  <div className="text-[19px] font-black">{v.name}</div>
                  <div className="text-[16px] font-extrabold text-muted">{rupees(v.price)}{picking.track_inventory && left !== undefined ? ` · ${qty(left)} left` : ''}</div>
                </div>
                <div className="flex items-center gap-1.5">
                  <button type="button" aria-label={`Remove one ${v.name}`} onClick={() => draft.change(lineFor(picking, v), -1)} className="h-[52px] w-[52px] rounded-2xl bg-sand text-[30px] font-black">−</button>
                  <span className="min-w-[30px] text-center text-2xl font-black">{qty(draft.targets[v.stock_key] ?? 0)}</span>
                  <button type="button" aria-label={`Add one ${v.name}`} onClick={() => draft.change(lineFor(picking, v), 1)} className="h-[52px] w-[52px] rounded-2xl bg-berry text-[30px] font-black text-white">+</button>
                </div>
              </div>
            );
          })}
        </Modal>
      )}
      {showCustomer && draft.order && <CustomerModal order={draft.order} onClose={() => setShowCustomer(false)} onSaved={draft.adoptOrder} />}
    </div>
  );
}

export function CustomerModal({ order, onClose, onSaved }: { order: Order; onClose: () => void; onSaved: (o: Order) => void }) {
  const [name, setName] = useState(order.customer_name ?? '');
  const [mobile, setMobile] = useState(order.customer_mobile ?? '');
  const { busy, error, run } = useBusy();
  const save = () => run(async () => {
    onSaved(await api.patch<Order>(`/orders/${order.id}`, { customer_name: name || null, customer_mobile: mobile || null }));
    onClose();
  });
  return (
    <Modal title="Customer (optional)" onClose={onClose} footer={<Button big tone="berry" className="w-full" disabled={busy} onClick={save}>SAVE</Button>}>
      <ErrorBanner error={error} />
      <Field label="Name"><input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} /></Field>
      <Field label="Mobile number" hint="Links the bill to the customer's history and points."><input className={inputClass} inputMode="tel" value={mobile} onChange={(e) => setMobile(e.target.value)} /></Field>
    </Modal>
  );
}

function BillView({ draft, onBack, onPaid }: { draft: Draft; onBack: () => void; onPaid: (i: Invoice) => void }) {
  const order = draft.order;
  const online = useApp((s) => s.online);
  const notify = useApp((s) => s.notify);
  const [method, setMethod] = useState<'cash' | 'upi' | 'card' | 'split'>('cash');
  const [reference, setReference] = useState('');
  const [tendered, setTendered] = useState('');
  const [split, setSplit] = useState({ cash: '', upi: '', card: '' });
  const [discount, setDiscount] = useState<{ type: 'percent' | 'fixed'; value: string } | null>(null);
  const [showDiscount, setShowDiscount] = useState(false);
  // null: every offer that helps applies automatically; a list: the cashier's own pick.
  const [promotionIds, setPromotionIds] = useState<number[] | null | 'none'>(null);
  const [points, setPoints] = useState(0);
  const [pin, setPin] = useState('');
  const [needPin, setNeedPin] = useState(false);
  const [freshOrder, setFreshOrder] = useState<Order | null>(null);
  const { busy, error, run, setError } = useBusy();
  // Stable across retries, so a lost reply can never produce a second bill.
  const [payKey] = useState(() => uuid());

  const current = freshOrder ?? order;
  const body = useMemo(() => ({
    manual_discount: discount && Number(discount.value) > 0 ? { type: discount.type, value: Number(discount.value) } : undefined,
    promotion_ids: Array.isArray(promotionIds) ? promotionIds : undefined,
    no_promotion: promotionIds === 'none' ? true : undefined,
    redeem_points: points || undefined,
  }), [discount, promotionIds, points]);

  const preview = useQuery({
    queryKey: ['bill-preview', current?.id, current?.version, body],
    queryFn: () => api.post<BillPreview>(`/orders/${current!.id}/bill/preview`, body),
    enabled: Boolean(current) && online,
    retry: false,
  });
  const customer = useQuery({
    queryKey: ['customer', current?.customer_id],
    queryFn: () => api.get<Customer>(`/customers/${current!.customer_id}`),
    enabled: Boolean(current?.customer_id) && online,
  });

  if (!current) return <Loading />;
  const p = preview.data;
  const total = Number(p?.total ?? current.subtotal);
  const conflict = current.items?.some((i) => Number(i.stock_shortfall) > 0);
  const discountTotal = p ? Number(p.promotion_discount) + Number(p.manual_discount) + Number(p.loyalty_discount) : 0;
  const appliedIds = p?.promotions.map((o) => o.id) ?? [];
  const toggleOffer = (id: number) => {
    const next = appliedIds.includes(id) ? appliedIds.filter((x) => x !== id) : [...appliedIds, id];
    setPromotionIds(next.length ? next : 'none');
  };
  const balance = customer.data?.loyalty_account?.points_balance ?? 0;
  const splitSum = Number(split.cash || 0) + Number(split.upi || 0) + Number(split.card || 0);
  const change = method === 'cash' && tendered ? Number(tendered) - total : 0;

  const payments = () => {
    if (method === 'split') {
      return METHODS.filter((m) => Number(split[m.id]) > 0).map((m) => ({ method: m.id, amount: Number(split[m.id]), reference: m.id !== 'cash' ? reference || undefined : undefined }));
    }
    if (total === 0) return [];
    return [{ method, amount: total, reference: method !== 'cash' ? reference || undefined : undefined, tendered: method === 'cash' && tendered ? Number(tendered) : undefined }];
  };

  const confirm = () => run(async () => {
    try {
      const invoice = await api.post<Invoice>(`/orders/${current.id}/bill`, {
        ...body,
        expected_version: p?.version ?? current.version,
        payments: payments(),
        manager_pin: pin || undefined,
      }, `${payKey}-${p?.version ?? current.version}-${p?.total}`);
      onPaid(invoice);
    } catch (e) {
      const err = e as ApiError;
      if (err.code === 'authorization_required') {
        setNeedPin(true);
        throw err;
      }
      if (err.code === 'order_changed' || err.code === 'stock_shortfall') {
        setFreshOrder(await api.get<Order>(`/orders/${current.id}`));
        notify(err.message, 'error');
        setError(null);
        return;
      }
      throw err;
    }
  });

  const pillClass = (on: boolean) => `h-[72px] flex-1 rounded-[18px] text-[20px] font-black ${on ? 'border-[3px] border-mint bg-mint-soft' : 'border-2 border-line bg-white'}`;
  const splitReady = method !== 'split' || Math.abs(splitSum - total) < 0.005;
  const cashShort = method === 'cash' && tendered !== '' && Number(tendered) < total;

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <header className="flex items-center gap-3 px-4 pb-3 pt-[18px]">
        <button type="button" onClick={onBack} aria-label="Back to items" className="min-h-[52px] rounded-[26px] border-2 border-line bg-white px-4 text-lg font-extrabold">← Back</button>
        <div className="min-w-0">
          <h1 className="text-[28px] font-black leading-tight">Your bill</h1>
          <div className="truncate text-[15px] font-extrabold text-muted-soft">Order {current.order_number}{current.customer_name ? ` · ${current.customer_name}` : ''}</div>
        </div>
      </header>

      <div className="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto px-4 py-1">
        {current.items?.map((line) => (
          <div key={line.id} className="flex items-center justify-between gap-3 rounded-[18px] bg-white px-4 py-3.5">
            <div className="min-w-0">
              <div className="text-[20px] font-black">{line.name}</div>
              <div className="text-base font-bold text-muted">{qty(line.quantity)} × {rupees(line.unit_price)}</div>
              {line.conflict_note && <div className="text-[15px] font-extrabold text-rust">{line.conflict_note}</div>}
            </div>
            <div className="text-2xl font-black">{rupees(line.line_total)}</div>
          </div>
        ))}

        <div className="rounded-[18px] bg-white px-4 py-3 text-[17px] font-extrabold">
          <div className="flex justify-between"><span>Subtotal</span><span>{rupees(p?.subtotal ?? current.subtotal)}</span></div>
          {discountTotal > 0 && <div className="flex justify-between text-mint"><span>Discount{p?.promotions.length ? ` · ${p.promotions.map((o) => o.name).join(', ')}` : ''}</span><span>−{rupees(discountTotal)}</span></div>}
          {p && Number(p.tax_amount) > 0 && <div className="flex justify-between text-muted"><span>GST {useApp.getState().settings['tax.prices_inclusive'] ? '(included)' : ''}</span><span>{rupees(p.tax_amount)}</span></div>}
        </div>

        <div className="flex flex-wrap gap-2">
          <Button tone="plain" onClick={() => setShowDiscount(true)}>{discount && Number(discount.value) > 0 ? 'Change discount' : '+ Discount'}</Button>
          {balance > 0 && <Button tone="plain" onClick={() => setPoints(points ? 0 : Math.min(balance, Math.floor(total)))}>{points ? `Remove ${points} points` : `Use points (${balance})`}</Button>}
        </div>
        {(p?.available_promotions.length ?? 0) > 0 && (
          <div className="rounded-[18px] bg-white p-3">
            <div className="mb-1.5 text-[17px] font-black">Offers</div>
            <div className="flex flex-wrap gap-2">
              {p!.available_promotions.map((o) => {
                const applied = p!.promotions.find((a) => a.id === o.id);
                return (
                  <button key={o.id} type="button" onClick={() => toggleOffer(o.id)} aria-pressed={Boolean(applied)} className={`min-h-[52px] rounded-2xl px-3 text-base font-black ${applied ? 'border-[3px] border-mint bg-mint-soft' : 'border-2 border-line'}`}>
                    {o.name} · −{rupees(applied?.discount ?? o.discount)}
                  </button>
                );
              })}
              <button type="button" onClick={() => setPromotionIds('none')} aria-pressed={!p!.promotions.length} className={`min-h-[52px] rounded-2xl px-3 text-base font-black ${!p!.promotions.length ? 'border-[3px] border-mint bg-mint-soft' : 'border-2 border-line'}`}>No offer</button>
              {promotionIds !== null && <button type="button" onClick={() => setPromotionIds(null)} className="min-h-[52px] rounded-2xl border-2 border-line px-3 text-base font-black">Best offers</button>}
            </div>
          </div>
        )}
        {conflict && <div role="alert" className="rounded-2xl bg-rust-soft px-4 py-3 text-[17px] font-extrabold text-rust">Some items are short of stock. Go back and change them, or open the order in Orders to Be Billed.</div>}
        <ErrorBanner error={error} />
        {preview.error && <ErrorBanner error={preview.error} />}
        {needPin && <PinField value={pin} onChange={setPin} />}
      </div>

      <div className="px-4 pt-3">
        <div className="mb-2.5 text-[20px] font-black">How did they pay?</div>
        <div className="flex gap-2.5">
          {METHODS.map((m) => <button key={m.id} type="button" aria-label={`Pay by ${m.label}`} aria-pressed={method === m.id} onClick={() => setMethod(m.id)} className={pillClass(method === m.id)}>{m.label}</button>)}
          <button type="button" aria-label="Pay by more than one method" aria-pressed={method === 'split'} onClick={() => setMethod('split')} className={pillClass(method === 'split')}>Split</button>
        </div>
        {method === 'cash' && (
          <div className="mt-2.5 flex items-center gap-2">
            <input aria-label="Cash received" inputMode="decimal" placeholder="Cash received (optional)" className={`${inputClass} flex-1`} value={tendered} onChange={(e) => setTendered(e.target.value)} />
            {change > 0 && <div className="shrink-0 text-[19px] font-black text-mint">Change {rupees(change)}</div>}
            {cashShort && <div className="shrink-0 text-[17px] font-black text-rust">Short</div>}
          </div>
        )}
        {(method === 'upi' || method === 'card') && (
          <input aria-label="Payment reference" placeholder="Reference number (optional)" className={`${inputClass} mt-2.5`} value={reference} onChange={(e) => setReference(e.target.value)} />
        )}
        {method === 'split' && (
          <div className="mt-2.5 grid grid-cols-3 gap-2">
            {METHODS.map((m) => <input key={m.id} aria-label={`${m.label} amount`} inputMode="decimal" placeholder={m.label} className={inputClass} value={split[m.id]} onChange={(e) => setSplit({ ...split, [m.id]: e.target.value })} />)}
            <div className={`col-span-3 text-[16px] font-extrabold ${splitReady ? 'text-mint' : 'text-rust'}`}>Entered {rupees(splitSum)} of {rupees(total)}</div>
          </div>
        )}
      </div>

      <footer className="mt-3 border-t-2 border-line-soft bg-white px-4 pb-5 pt-3.5">
        {!online ? (
          <div className="rounded-[20px] bg-sand px-4 py-5 text-center text-lg font-black">Billing needs a connection. This order is saved.</div>
        ) : (
          <button type="button" onClick={confirm} disabled={busy || !p || conflict || !splitReady || cashShort} className="h-[72px] w-full rounded-[20px] bg-mint text-[22px] font-black text-white disabled:bg-line-strong">
            {busy ? 'SAVING…' : `CONFIRM · ${rupees(total)}`}
          </button>
        )}
      </footer>

      {showDiscount && (
        <DiscountModal
          value={discount}
          onClose={() => setShowDiscount(false)}
          onApply={(d, enteredPin) => { setDiscount(d); if (enteredPin) { setPin(enteredPin); setNeedPin(true); } setShowDiscount(false); }}
        />
      )}
    </div>
  );
}

function DiscountModal({ value, onClose, onApply }: { value: { type: 'percent' | 'fixed'; value: string } | null; onClose: () => void; onApply: (d: { type: 'percent' | 'fixed'; value: string } | null, pin: string) => void }) {
  const [type, setType] = useState<'percent' | 'fixed'>(value?.type ?? 'percent');
  const [amount, setAmount] = useState(value?.value ?? '');
  const [pin, setPin] = useState('');
  return (
    <Modal title="Discount" onClose={onClose} footer={<Button big className="w-full" onClick={() => onApply(amount ? { type, value: amount } : null, pin)}>APPLY</Button>}>
      <div className="flex gap-2">
        <button type="button" aria-pressed={type === 'percent'} onClick={() => setType('percent')} className={`min-h-[56px] flex-1 rounded-2xl text-lg font-black ${type === 'percent' ? 'border-[3px] border-berry bg-berry-soft' : 'border-2 border-line bg-white'}`}>Percent %</button>
        <button type="button" aria-pressed={type === 'fixed'} onClick={() => setType('fixed')} className={`min-h-[56px] flex-1 rounded-2xl text-lg font-black ${type === 'fixed' ? 'border-[3px] border-berry bg-berry-soft' : 'border-2 border-line bg-white'}`}>Rupees ₹</button>
      </div>
      <Field label={type === 'percent' ? 'Percent off' : 'Rupees off'}><input className={inputClass} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
      <PinField value={pin} onChange={setPin} />
    </Modal>
  );
}

function DoneView({ invoice, onNew }: { invoice: Invoice; onNew: () => void }) {
  const { print, state, outcome, portal } = usePrint();
  const label = (invoice.payments ?? []).map((p) => p.method.toUpperCase()).join(' + ') || 'No payment due';
  return (
    <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3.5 p-6">
      {portal}
      <div className="flex h-[120px] w-[120px] items-center justify-center rounded-full bg-mint">
        <svg width="60" height="60" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" /></svg>
      </div>
      <h1 className="mt-2 text-[40px] font-black">Paid!</h1>
      <div className="text-2xl font-extrabold">{rupees(invoice.total)} · {label}</div>
      <div className="text-lg font-bold text-muted">{invoice.invoice_number}</div>
      <div className="text-lg font-bold text-muted">Thank you for visiting</div>
      {state === 'failed' && <div role="alert" className="w-full max-w-md rounded-2xl bg-rust-soft px-4 py-3 text-center text-[17px] font-extrabold text-rust">Bill is saved, but it did not print. {outcome?.error}</div>}
      {state === 'done' && <div role="status" className="text-lg font-extrabold text-mint">Printed{outcome?.duplicate ? ' (duplicate)' : ''}</div>}
      <div className="mt-5 flex w-full max-w-md flex-col gap-2.5">
        <button type="button" onClick={() => print(invoice.id)} disabled={state === 'printing'} className="h-[72px] w-full rounded-[20px] border-2 border-ink bg-white text-[22px] font-black">
          {state === 'printing' ? 'PRINTING…' : state === 'failed' ? 'TRY PRINT AGAIN' : state === 'done' ? 'PRINT AGAIN' : 'PRINT BILL'}
        </button>
        <button type="button" onClick={onNew} className="h-[72px] w-full rounded-[20px] bg-berry text-[22px] font-black text-white">NEW BILL</button>
      </div>
    </div>
  );
}

