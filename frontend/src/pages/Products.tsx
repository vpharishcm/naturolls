import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Badge, Button, Chip, Empty, ErrorBanner, Field, inputClass, Loading, Modal, PageHeader, useBusy } from '../components/ui';
import { api } from '../lib/api';
import { qty, rupees } from '../lib/format';
import { CategoryTag } from '../components/CategoryTag';
import type { Category, Product } from '../lib/types';
import { useApp } from '../stores/app';

const STEP = 5;
const MIN_PRICE = 5;

/** The design's Manage Items screen: prices move in ₹5 steps and are saved together. */
export default function Products() {
  const qc = useQueryClient();
  const can = useApp((s) => s.can);
  const manage = can('catalog.manage');
  const products = useQuery({ queryKey: ['products', 'all'], queryFn: () => api.get<Product[]>('/products', { include_inactive: 1 }) });
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.get<Category[]>('/categories') });
  const [cat, setCat] = useState<number | null>(null);
  const [term, setTerm] = useState('');
  const [prices, setPrices] = useState<Record<number, number>>({});
  const [confirmId, setConfirmId] = useState<number | null>(null);
  const [saved, setSaved] = useState(false);
  const [addingCat, setAddingCat] = useState(false);
  const { busy, error, run } = useBusy();

  const list = categories.data ?? [];
  const active = cat ?? list.find((c) => products.data?.some((p) => p.category_id === c.id))?.id ?? list[0]?.id ?? null;
  const rows = useMemo(() => {
    const q = term.trim().toLowerCase();
    return (products.data ?? []).filter((p) => (q ? p.name.toLowerCase().includes(q) || p.sku?.toLowerCase().includes(q) : p.category_id === active));
  }, [products.data, active, term]);

  const changed = Object.entries(prices).filter(([id, price]) => Number((products.data ?? []).find((p) => p.id === Number(id))?.price) !== price);
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['products'] });
    qc.invalidateQueries({ queryKey: ['categories'] });
  };

  const save = () => run(async () => {
    await api.post('/products/bulk-prices', { prices: changed.map(([id, price]) => ({ id: Number(id), price })) });
    setPrices({});
    setSaved(true);
    refresh();
  });

  const remove = (id: number) => run(async () => {
    await api.delete(`/products/${id}`);
    setConfirmId(null);
    refresh();
  });

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <PageHeader eyebrow={manage ? 'Shop owner only' : 'Products'} title="Manage items" />
      <div className="px-4 pb-2.5"><input aria-label="Search products" placeholder="Search item or SKU" className={inputClass} value={term} onChange={(e) => setTerm(e.target.value)} /></div>
      <nav aria-label="Categories" className="flex flex-wrap gap-2 px-4 pb-2.5">
        {list.map((c) => <Chip key={c.id} active={c.id === active && !term} onClick={() => { setCat(c.id); setConfirmId(null); setTerm(''); }}>{c.name}</Chip>)}
        {manage && <button type="button" onClick={() => setAddingCat(true)} aria-label="Create a new category" className="min-h-[52px] rounded-[18px] border-2 border-dashed border-berry bg-white px-3.5 text-lg font-black text-berry">+ New category</button>}
      </nav>
      {manage && (
        <div className="px-4 pb-2.5">
          <Link to={`/products/new${active ? `?category=${active}` : ''}`} className="flex h-[60px] items-center justify-center rounded-[18px] bg-berry text-[20px] font-black text-white">+ ADD NEW ITEM</Link>
        </div>
      )}
      <div className="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto px-4 pb-4">
        <ErrorBanner error={error} />
        {products.isLoading && <Loading />}
        {rows.map((p) => {
          const base = Number(p.price);
          const price = prices[p.id] ?? base;
          const available = p.available_qty;
          return (
            <div key={p.id} className="flex flex-col gap-2.5 rounded-[20px] bg-white px-3.5 py-3 shadow-tile">
              <div className="flex flex-wrap items-center justify-between gap-2.5">
                <div className="min-w-0">
                  <Link to={`/products/${p.id}`} className="text-[20px] font-black leading-tight underline decoration-line underline-offset-4">{p.name}</Link>{term && <CategoryTag name={p.category_name} />}
                  <div className="text-[15px] font-bold text-muted">
                    {p.track_inventory ? `${qty(available)} available` : 'Stock not tracked'}
                    {!p.is_active && <> · <Badge tone="grey">Hidden from billing</Badge></>}
                  </div>
                  {price !== base && <div className="text-sm font-extrabold text-amber-ink">Changed (was {rupees(base)})</div>}
                </div>
                {manage ? (
                  <div className="flex shrink-0 items-center gap-2">
                    <button type="button" aria-label={`Lower price of ${p.name} by 5 rupees`} onClick={() => { setSaved(false); setPrices({ ...prices, [p.id]: Math.max(MIN_PRICE, price - STEP) }); }} className="h-[52px] w-[52px] rounded-2xl bg-sand text-[30px] font-black">−</button>
                    <span aria-live="polite" className="min-w-[60px] text-center text-2xl font-black">{rupees(price)}</span>
                    <button type="button" aria-label={`Raise price of ${p.name} by 5 rupees`} onClick={() => { setSaved(false); setPrices({ ...prices, [p.id]: price + STEP }); }} className="h-[52px] w-[52px] rounded-2xl bg-berry text-[30px] font-black text-white">+</button>
                  </div>
                ) : <span className="text-2xl font-black">{rupees(price)}</span>}
              </div>
              {manage && (confirmId === p.id ? (
                <div className="flex flex-col gap-2 rounded-2xl bg-rust-soft p-2.5">
                  <div className="text-lg font-black">Delete {p.name}?</div>
                  <div className="flex gap-2">
                    <button type="button" disabled={busy} onClick={() => remove(p.id)} className="h-[52px] flex-1 rounded-[14px] bg-rust text-[17px] font-black text-white">YES, DELETE</button>
                    <button type="button" onClick={() => setConfirmId(null)} className="h-[52px] flex-1 rounded-[14px] border-2 border-line-strong bg-white text-[17px] font-black">KEEP IT</button>
                  </div>
                </div>
              ) : (
                <button type="button" onClick={() => setConfirmId(p.id)} aria-label={`Delete ${p.name}`} className="h-[52px] rounded-2xl border-2 border-rust-line bg-white text-lg font-black text-rust">Delete this item</button>
              ))}
            </div>
          );
        })}
        {!products.isLoading && rows.length === 0 && <Empty>No items in this category yet. Tap ADD NEW ITEM.</Empty>}
      </div>
      {manage && (
        <footer className="border-t-2 border-line-soft bg-white px-4 pb-5 pt-3.5">
          <div className="mb-2 text-[15px] font-bold text-muted">
            {changed.length === 0 ? 'Tap + or − to change a price. Tap Delete to remove an item.' : `${changed.length} ${changed.length === 1 ? 'price' : 'prices'} changed. Press SAVE to confirm.`}
          </div>
          <button type="button" onClick={save} disabled={busy || changed.length === 0} className={`h-16 w-full rounded-[20px] text-[21px] font-black text-white ${saved ? 'bg-mint' : 'bg-berry disabled:bg-line-strong'}`}>
            {saved ? 'SAVED ✓' : 'SAVE CHANGES'}
          </button>
        </footer>
      )}
      {addingCat && <CategoryModal categories={list} onClose={() => setAddingCat(false)} onSaved={(c) => { setCat(c.id); setAddingCat(false); refresh(); }} />}
    </div>
  );
}

function CategoryModal({ categories, onClose, onSaved }: { categories: Category[]; onClose: () => void; onSaved: (c: Category) => void }) {
  const [name, setName] = useState('');
  const [parent, setParent] = useState('');
  const { busy, error, run } = useBusy();
  const none = name.trim() === '';
  return (
    <Modal title="New category" onClose={onClose} footer={
      <button type="button" disabled={none || busy} onClick={() => run(async () => onSaved(await api.post<Category>('/categories', { name, parent_id: parent || null })))} className="h-[68px] w-full rounded-[20px] bg-berry text-[21px] font-black text-white disabled:bg-line-strong">{none ? 'TYPE A NAME FIRST' : 'SAVE CATEGORY'}</button>
    }>
      <ErrorBanner error={error} />
      <Field label="Category name" hint="After saving, you can add items to this category."><input className={inputClass} placeholder="Example: Sundaes" value={name} onChange={(e) => setName(e.target.value)} /></Field>
      <Field label="Inside (optional)">
        <select className={inputClass} value={parent} onChange={(e) => setParent(e.target.value)}>
          <option value="">Top level</option>
          {categories.filter((c) => !c.parent_id).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </Field>
      <div className="flex flex-col gap-1">
        <div className="text-lg font-black">Display order</div>
        <CategoryOrder categories={categories} />
      </div>
    </Modal>
  );
}

function CategoryOrder({ categories }: { categories: Category[] }) {
  const qc = useQueryClient();
  const move = async (index: number, delta: number) => {
    const ids = categories.map((c) => c.id);
    const [id] = ids.splice(index, 1);
    ids.splice(index + delta, 0, id);
    await api.post('/categories/reorder', { ids });
    qc.invalidateQueries({ queryKey: ['categories'] });
  };
  return (
    <>
      {categories.map((c, i) => (
        <div key={c.id} className="flex items-center justify-between gap-2 rounded-2xl bg-white px-3 py-1.5 text-[17px] font-extrabold">
          <span className="min-w-0 truncate">{c.name}</span>
          <span className="flex shrink-0 gap-1.5">
            <Button tone="sand" aria-label={`Move ${c.name} up`} disabled={i === 0} onClick={() => move(i, -1)}>↑</Button>
            <Button tone="sand" aria-label={`Move ${c.name} down`} disabled={i === categories.length - 1} onClick={() => move(i, 1)}>↓</Button>
          </span>
        </div>
      ))}
    </>
  );
}
