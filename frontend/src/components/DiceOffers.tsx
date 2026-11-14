import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge, Button, Card, Chip, ErrorBanner, Field, inputClass, Modal, useBusy } from './ui';
import { api } from '../lib/api';
import { rupees } from '../lib/format';
import type { Category, DiceFace, DiceFaceType, DiceOffer, Product } from '../lib/types';

const PIPS = ['⚀', '⚁', '⚂', '⚃', '⚄', '⚅'];
const TYPES: [DiceFaceType, string][] = [['free_item', 'Free item'], ['discount', '₹ off bill'], ['reroll', 'Roll again'], ['none', 'No prize']];

/** A new offer starts as the usual poster: three free items, ₹20 off, try again and better luck. */
const STARTER: DiceFace[] = [
  { type: 'free_item', label: 'Free mini popsicle' },
  { type: 'reroll', label: 'Try again' },
  { type: 'free_item', label: 'Free mini scoop' },
  { type: 'discount', label: '₹20 off your bill', amount: 20 },
  { type: 'none', label: 'Better luck next time' },
  { type: 'free_item', label: 'Free regular popsicle' },
];

function faceSummary(f: DiceFace) {
  if (f.type === 'discount') return `${rupees(f.amount ?? 0)} off`;
  if (f.type === 'free_item') return 'Free item';
  return f.type === 'reroll' ? 'Roll again' : 'No prize';
}

/** Settings › Offers: the "roll the dice" offers, with each face's prize. */
export function DiceOffersSection() {
  const qc = useQueryClient();
  const { data } = useQuery({ queryKey: ['dice-offers'], queryFn: () => api.get<DiceOffer[]>('/dice-offers') });
  const [editing, setEditing] = useState<DiceOffer | 'new' | null>(null);
  const { busy, error, run } = useBusy();
  const toggle = (o: DiceOffer) => run(async () => {
    await api.put(`/dice-offers/${o.id}`, { ...o, is_active: !o.is_active });
    qc.invalidateQueries({ queryKey: ['dice-offers'] });
  });

  return (
    <>
      <div className="mt-2 flex items-center justify-between gap-2">
        <h2 className="text-[22px] font-black">🎲 Roll the dice</h2>
        <Button tone="plain" onClick={() => setEditing('new')}>+ New dice offer</Button>
      </div>
      <ErrorBanner error={error} />
      {data?.length === 0 && <div className="text-base font-bold text-muted">No dice offers yet. Bills over a set amount get a roll of a real dice, and the number decides the prize.</div>}
      {data?.map((o) => (
        <Card key={o.id} className="flex flex-col gap-2">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="text-[19px] font-black">{o.name}</div>
              <div className="text-base font-bold text-muted">Bills of {rupees(o.min_bill)} or more{o.starts_on ? ` · ${o.starts_on}` : ''}{o.ends_on ? ` to ${o.ends_on}` : ''}</div>
            </div>
            <Badge tone={o.is_active ? 'ok' : 'grey'}>{o.is_active ? 'On' : 'Off'}</Badge>
          </div>
          <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-[15px] font-extrabold sm:grid-cols-3">
            {o.faces.map((f, i) => <div key={i}><span aria-hidden="true" className="text-[20px]">{PIPS[i]}</span> {f.label || faceSummary(f)}</div>)}
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Button tone="plain" onClick={() => setEditing(o)}>Edit</Button>
            <Button tone="plain" disabled={busy} onClick={() => toggle(o)}>{o.is_active ? 'Turn off' : 'Turn on'}</Button>
          </div>
        </Card>
      ))}
      {editing && <DiceOfferForm offer={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}

function DiceOfferForm({ offer, onClose }: { offer: DiceOffer | null; onClose: () => void }) {
  const qc = useQueryClient();
  const products = useQuery({ queryKey: ['products'], queryFn: () => api.get<Product[]>('/products') });
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.get<Category[]>('/categories') });
  const [name, setName] = useState(offer?.name ?? 'Roll the dice');
  const [minBill, setMinBill] = useState(offer ? String(Number(offer.min_bill)) : '250');
  const [startsOn, setStartsOn] = useState(offer?.starts_on ?? '');
  const [endsOn, setEndsOn] = useState(offer?.ends_on ?? '');
  const [faces, setFaces] = useState<DiceFace[]>(() => (offer?.faces ?? STARTER).map((f) => ({ ...f, amount: f.amount !== undefined ? String(Number(f.amount)) : undefined })));
  const [open, setOpen] = useState<number | null>(null);
  const { busy, error, run } = useBusy();

  const setFace = (i: number, patch: Partial<DiceFace>) => setFaces((v) => v.map((f, j) => (j === i ? { ...f, ...patch } : f)));
  const flip = (i: number, key: 'product_ids' | 'category_ids', id: number) => {
    const list = faces[i][key] ?? [];
    setFace(i, { [key]: list.includes(id) ? list.filter((x) => x !== id) : [...list, id] });
  };
  const problem = faces.map((f, i) => {
    if (f.type === 'discount' && !(Number(f.amount) > 0)) return `Face ${i + 1}: enter the rupees off.`;
    if (f.type === 'free_item' && !(f.product_ids?.length || f.category_ids?.length)) return `Face ${i + 1}: choose which items can be given free.`;
    return null;
  }).find(Boolean);

  const save = () => run(async () => {
    const body = {
      name, min_bill: Number(minBill || 0), starts_on: startsOn || null, ends_on: endsOn || null, is_active: offer?.is_active ?? true,
      faces: faces.map((f) => ({ ...f, amount: f.type === 'discount' ? Number(f.amount) : undefined })),
    };
    if (offer) await api.put(`/dice-offers/${offer.id}`, body);
    else await api.post('/dice-offers', body);
    qc.invalidateQueries({ queryKey: ['dice-offers'] });
    onClose();
  });

  return (
    <Modal title={offer ? 'Edit dice offer' : 'New dice offer'} onClose={onClose} footer={
      <Button big className="w-full" disabled={busy || !name.trim() || Boolean(problem)} onClick={save}>SAVE DICE OFFER</Button>
    }>
      <ErrorBanner error={error} />
      <Field label="Name"><input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} /></Field>
      <Field label="Bill must be at least ₹ (before discounts)"><input className={inputClass} inputMode="decimal" value={minBill} onChange={(e) => setMinBill(e.target.value)} /></Field>
      <div className="grid grid-cols-2 gap-2">
        <Field label="Starts"><input type="date" className={inputClass} value={startsOn} onChange={(e) => setStartsOn(e.target.value)} /></Field>
        <Field label="Ends"><input type="date" className={inputClass} value={endsOn} onChange={(e) => setEndsOn(e.target.value)} /></Field>
      </div>
      <div className="text-lg font-black">What each number wins</div>
      {faces.map((f, i) => {
        const picked = (f.product_ids?.length ?? 0) + (f.category_ids?.length ?? 0);
        return (
          <div key={i} className="flex flex-col gap-2 rounded-2xl bg-white p-3">
            <div className="flex items-center gap-2">
              <span aria-hidden="true" className="text-[36px] leading-none">{PIPS[i]}</span>
              <input aria-label={`Face ${i + 1} wording`} className={`${inputClass} flex-1`} value={f.label ?? ''} placeholder={faceSummary(f)} onChange={(e) => setFace(i, { label: e.target.value })} />
            </div>
            <div className="flex flex-wrap gap-2">
              {TYPES.map(([t, label]) => <Chip key={t} active={f.type === t} onClick={() => setFace(i, { type: t })}>{label}</Chip>)}
            </div>
            {f.type === 'discount' && <Field label="Rupees off"><input className={inputClass} inputMode="decimal" value={String(f.amount ?? '')} onChange={(e) => setFace(i, { amount: e.target.value })} /></Field>}
            {f.type === 'free_item' && (
              <>
                <button type="button" onClick={() => setOpen(open === i ? null : i)} className={`min-h-[52px] rounded-2xl border-2 px-3 text-left text-base font-black ${picked ? 'border-line' : 'border-rust text-rust'}`}>
                  {picked ? `${picked} chosen. Staff pick one at the counter.` : 'Choose which items can be given free'} {open === i ? '▲' : '▼'}
                </button>
                {open === i && (
                  <>
                    <div className="text-[15px] font-extrabold text-muted">Categories (everything in them)</div>
                    <div className="flex flex-wrap gap-2">{categories.data?.map((c) => <Chip key={c.id} tone="mint" active={f.category_ids?.includes(c.id)} onClick={() => flip(i, 'category_ids', c.id)}>{c.name}</Chip>)}</div>
                    <div className="text-[15px] font-extrabold text-muted">Items</div>
                    <div className="flex flex-wrap gap-2">{products.data?.filter((p) => p.is_active).map((p) => <Chip key={p.id} tone="mint" active={f.product_ids?.includes(p.id)} onClick={() => flip(i, 'product_ids', p.id)}>{p.name}</Chip>)}</div>
                  </>
                )}
              </>
            )}
          </div>
        );
      })}
      {problem && <div role="alert" className="text-[17px] font-extrabold text-rust">{problem}</div>}
    </Modal>
  );
}
