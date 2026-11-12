import { useMemo, useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { ProductArt } from '../components/ProductArt';
import { Badge, Button, Card, Chip, Empty, ErrorBanner, Field, inputClass, Loading, Modal, PageHeader, Pager, PinField, useBusy } from '../components/ui';
import { api } from '../lib/api';
import { qty, today } from '../lib/format';
import type { Category, Product } from '../lib/types';
import { uuid } from '../lib/uuid';
import { useApp } from '../stores/app';

interface BatchItem { id: number; quantity: string; reversed_at: string | null; reversal_reason: string | null; product?: { name: string } }
interface Batch { id: number; production_date: string; production_time: string; notes: string | null; user?: { name: string }; items: BatchItem[] }

export default function Production() {
  const [tab, setTab] = useState<'entry' | 'history'>('entry');
  return (
    <div className="flex flex-col pb-6">
      <PageHeader title="Daily Production" />
      <div className="flex flex-col gap-2.5 px-4">
        <div className="flex gap-2">
          <Chip active={tab === 'entry'} onClick={() => setTab('entry')}>New entry</Chip>
          <Chip active={tab === 'history'} onClick={() => setTab('history')}>History</Chip>
        </div>
        {tab === 'entry' ? <Entry onSaved={() => setTab('history')} /> : <History />}
      </div>
    </div>
  );
}

function Entry({ onSaved }: { onSaved: () => void }) {
  const qc = useQueryClient();
  const notify = useApp((s) => s.notify);
  const [date, setDate] = useState(today());
  const [counts, setCounts] = useState<Record<number, string>>({});
  const [term, setTerm] = useState('');
  // Stable until saved, so a double tap or retry records one session only.
  const [saveKey, setSaveKey] = useState(() => uuid());
  const { busy, error, run } = useBusy();
  const products = useQuery({ queryKey: ['products'], queryFn: () => api.get<Product[]>('/products') });
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.get<Category[]>('/categories') });

  const flavours = useMemo(() => {
    const cats = categories.data ?? [];
    const popsicleIds = new Set<number>();
    cats.forEach((c) => {
      const parent = cats.find((p) => p.id === c.parent_id);
      if (/popsicle/i.test(c.name) || (parent && /popsicle/i.test(parent.name))) popsicleIds.add(c.id);
    });
    const list = (products.data ?? []).filter((p) => p.track_inventory && (popsicleIds.size === 0 || popsicleIds.has(p.category_id)));
    const q = term.trim().toLowerCase();
    return q ? list.filter((p) => p.name.toLowerCase().includes(q)) : list;
  }, [products.data, categories.data, term]);

  const items = Object.entries(counts).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ product_id: Number(id), quantity: Number(q) }));
  const total = items.reduce((s, i) => s + i.quantity, 0);

  const save = () => run(async () => {
    await api.post('/production', { production_date: date, items }, saveKey);
    setCounts({});
    setSaveKey(uuid());
    qc.invalidateQueries({ queryKey: ['production'] });
    qc.invalidateQueries({ queryKey: ['products'] });
    qc.invalidateQueries({ queryKey: ['stock'] });
    notify(`${total} popsicles added to stock.`, 'ok');
    onSaved();
  });

  return (
    <>
      <Field label="Production date"><input type="date" className={inputClass} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
      <input aria-label="Search flavours" placeholder="Search flavour" className={inputClass} value={term} onChange={(e) => setTerm(e.target.value)} />
      <ErrorBanner error={error} />
      {products.isLoading && <Loading />}
      {flavours.map((p) => {
        const value = Number(counts[p.id] || 0);
        return (
          <Card key={p.id} className="flex items-center gap-2.5">
            <ProductArt product={p} size={48} />
            <div className="min-w-0 flex-1">
              <div className="truncate text-[18px] font-black leading-tight">{p.name}</div>
              <div className="text-[15px] font-bold text-muted">{qty(p.available_qty)} available</div>
            </div>
            <button type="button" aria-label={`10 fewer ${p.name}`} onClick={() => setCounts({ ...counts, [p.id]: String(Math.max(0, value - 10)) })} className="h-[52px] w-[44px] shrink-0 rounded-2xl bg-sand text-[22px] font-black">−</button>
            <input aria-label={`${p.name} quantity`} inputMode="numeric" placeholder="0" className="h-[52px] w-16 shrink-0 rounded-2xl border-2 border-line-strong text-center text-[20px] font-black" value={counts[p.id] ?? ''} onChange={(e) => setCounts({ ...counts, [p.id]: e.target.value.replace(/[^\d.]/g, '') })} />
            <button type="button" aria-label={`10 more ${p.name}`} onClick={() => setCounts({ ...counts, [p.id]: String(value + 10) })} className="h-[52px] w-[44px] shrink-0 rounded-2xl bg-berry text-[22px] font-black text-white">+</button>
          </Card>
        );
      })}
      <button type="button" disabled={busy || items.length === 0} onClick={save} className="sticky bottom-2 h-[68px] rounded-[20px] bg-mint text-[21px] font-black text-white disabled:bg-line-strong">
        {items.length === 0 ? 'ENTER QUANTITIES' : `ADD ${total} TO STOCK · ${items.length} ${items.length === 1 ? 'FLAVOUR' : 'FLAVOURS'}`}
      </button>
    </>
  );
}

function History() {
  const [page, setPage] = useState(1);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [fixing, setFixing] = useState<BatchItem | null>(null);
  const { data, isLoading, error } = useQuery({
    queryKey: ['production', page, from, to],
    queryFn: () => api.page<Batch>('/production', { page, from: from || undefined, to: to || undefined }),
    placeholderData: keepPreviousData,
  });
  return (
    <>
      <div className="grid grid-cols-2 gap-2">
        <Field label="From"><input type="date" className={inputClass} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field label="To"><input type="date" className={inputClass} value={to} onChange={(e) => setTo(e.target.value)} /></Field>
      </div>
      <ErrorBanner error={error} />
      {isLoading && <Loading />}
      {data?.data.map((b) => (
        <Card key={b.id} className="flex flex-col gap-2">
          <div className="text-[18px] font-black">{b.production_date} · {b.production_time.slice(0, 5)}</div>
          <div className="text-[15px] font-bold text-muted">{b.user?.name}</div>
          {b.items.map((i) => (
            <div key={i.id} className="flex items-center justify-between gap-2">
              <div className={`min-w-0 text-[18px] font-extrabold ${i.reversed_at ? 'text-muted line-through' : ''}`}>{i.product?.name} · {qty(i.quantity)}</div>
              {i.reversed_at ? <Badge tone="grey">Reversed</Badge> : <Button tone="plain" onClick={() => setFixing(i)}>Fix</Button>}
            </div>
          ))}
        </Card>
      ))}
      {!isLoading && !data?.data.length && <Empty>No production recorded yet.</Empty>}
      <Pager meta={data?.meta} onPage={setPage} />
      {fixing && <FixModal item={fixing} onClose={() => setFixing(null)} />}
    </>
  );
}

function FixModal({ item, onClose }: { item: BatchItem; onClose: () => void }) {
  const qc = useQueryClient();
  const [quantity, setQuantity] = useState(qty(item.quantity));
  const [reason, setReason] = useState('');
  const [pin, setPin] = useState('');
  const { busy, error, run } = useBusy();
  const send = (path: 'edit' | 'reverse') => run(async () => {
    await api.post(`/production/items/${item.id}/${path}`, { reason, manager_pin: pin || undefined, quantity: path === 'edit' ? Number(quantity) : undefined });
    qc.invalidateQueries({ queryKey: ['production'] });
    qc.invalidateQueries({ queryKey: ['stock'] });
    onClose();
  });
  return (
    <Modal title={`Fix ${item.product?.name}`} onClose={onClose}>
      <ErrorBanner error={error} />
      <Field label="Correct quantity"><input className={inputClass} inputMode="decimal" value={quantity} onChange={(e) => setQuantity(e.target.value)} /></Field>
      <Field label="Reason"><input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
      <PinField value={pin} onChange={setPin} />
      <Button big disabled={busy || !reason.trim() || !Number(quantity)} onClick={() => send('edit')}>SAVE CORRECT QUANTITY</Button>
      <Button tone="danger" disabled={busy || !reason.trim()} onClick={() => send('reverse')}>Reverse the whole entry</Button>
    </Modal>
  );
}
