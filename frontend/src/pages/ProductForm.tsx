import { useEffect, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { BackLink, Button, ErrorBanner, Field, inputClass, useBusy } from '../components/ui';
import { api } from '../lib/api';
import { rupees } from '../lib/format';
import type { Category, Product } from '../lib/types';
import { useApp } from '../stores/app';

const STEP = 5;
const MIN_PRICE = 5;

const schema = z.object({
  name: z.string().trim().min(1, 'Type a name'),
  category_id: z.number({ message: 'Choose a section' }),
  price: z.number().min(0),
  is_active: z.boolean(),
  sku: z.string().optional(),
  code: z.string().optional(),
  description: z.string().optional(),
  unit: z.string().optional(),
  cost: z.string().optional(),
  gst_rate: z.string().regex(/^\d*\.?\d*$/, 'Enter a number').optional(),
  track_inventory: z.boolean(),
  inventory_method: z.enum(['on_serve', 'on_add']),
  min_stock: z.string().optional(),
  opening_stock: z.string().optional(),
  is_favourite: z.boolean(),
  variants: z.array(z.object({ id: z.number().optional(), name: z.string().min(1, 'Name'), price: z.string().min(1, 'Price'), sku: z.string().optional() })),
});
type Form = z.infer<typeof schema>;

/** The design's New Item screen, with the advanced fields folded away under More details. */
export default function ProductForm() {
  const { id } = useParams();
  const [search] = useSearchParams();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const editing = Boolean(id);
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.get<Category[]>('/categories') });
  const existing = useQuery({ queryKey: ['products', 'all'], queryFn: () => api.get<Product[]>('/products', { include_inactive: 1 }), enabled: editing, select: (rows) => rows.find((p) => p.id === Number(id)) });
  const [photo, setPhoto] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const { busy, error, run } = useBusy();
  const gstOn = Boolean(useApp((s) => s.settings['tax.enabled']));

  const { register, handleSubmit, watch, setValue, reset, control, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', price: 20, is_active: true, track_inventory: true, inventory_method: 'on_serve', is_favourite: false, gst_rate: '5', min_stock: '5', unit: 'pc', variants: [], category_id: Number(search.get('category')) || undefined },
  });
  const variants = useFieldArray({ control, name: 'variants' });

  useEffect(() => {
    const p = existing.data;
    if (!p) return;
    reset({
      name: p.name, category_id: p.category_id, price: Number(p.price), is_active: p.is_active, sku: p.sku ?? '', code: p.code ?? '', description: p.description ?? '',
      unit: p.unit, cost: p.cost ?? '', gst_rate: p.gst_rate, track_inventory: p.track_inventory, inventory_method: p.inventory_method as Form['inventory_method'],
      min_stock: p.min_stock, is_favourite: p.is_favourite,
      variants: (p.variants ?? []).map((v) => ({ id: v.id, name: v.name, price: v.price, sku: v.sku ?? '' })),
    });
    setPreview(p.image_url);
  }, [existing.data, reset]);

  const price = watch('price');
  const avail = watch('is_active');
  const name = watch('name');
  const cat = watch('category_id');
  const track = watch('track_inventory');
  const noName = !name?.trim();

  const onFile = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0];
    if (!f) return;
    setPhoto(f);
    setPreview(URL.createObjectURL(f));
    setSaved(false);
  };

  const submit = handleSubmit((v) => run(async () => {
    const body = {
      ...v,
      sku: v.sku || null, code: v.code || null, description: v.description || null,
      cost: v.cost ? Number(v.cost) : null,
      gst_rate: Number(v.gst_rate || 0),
      min_stock: Number(v.min_stock || 0),
      opening_stock: !editing && v.opening_stock ? Number(v.opening_stock) : undefined,
      variants: v.variants.map((x) => ({ ...x, price: Number(x.price), sku: x.sku || null })),
    };
    const product = editing ? await api.put<Product>(`/products/${id}`, body) : await api.post<Product>('/products', body);
    if (photo) {
      const data = new FormData();
      data.append('image', photo);
      await api.post(`/products/${product.id}/image`, data);
    }
    qc.invalidateQueries({ queryKey: ['products'] });
    setSaved(true);
    if (!editing) navigate(`/products/${product.id}`, { replace: true });
  }));

  const list = categories.data ?? [];
  const chip = (on: boolean) => `min-h-[52px] rounded-[18px] px-3.5 py-1.5 text-lg font-black ${on ? 'border-[3px] border-berry bg-berry-soft' : 'border-2 border-line bg-white'}`;
  const toggle = (on: boolean) => `min-h-[60px] w-full rounded-[18px] text-[19px] font-black ${on ? 'border-[3px] border-mint bg-mint-soft' : 'border-2 border-line-strong bg-white'}`;

  return (
    <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
      <header className="flex flex-col gap-2.5 px-4 pb-2.5 pt-[18px]">
        <BackLink to="/products" label="Items" />
        <div>
          <div className="text-[15px] font-extrabold text-muted-soft">Shop owner only</div>
          <h1 className="mt-0.5 text-[28px] font-black leading-tight">{editing ? 'Edit item' : 'New item'}</h1>
        </div>
      </header>
      <div className="flex min-h-0 flex-1 flex-col gap-[18px] overflow-y-auto px-4 pb-4 pt-1">
        <ErrorBanner error={error} />
        <section className="flex flex-col gap-2.5">
          <div className="text-lg font-black">1. Photo</div>
          {preview ? (
            <img src={preview} alt="Item" className="box-border h-[200px] w-full rounded-[18px] border-[3px] border-mint object-cover" />
          ) : (
            <div className="box-border flex h-[200px] flex-col items-center justify-center gap-1.5 rounded-[18px] border-[3px] border-dashed border-line-strong bg-white">
              <svg width="56" height="56" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="3" fill="none" stroke="#6B5B73" strokeWidth="2" /><circle cx="12" cy="13.5" r="3.5" fill="none" stroke="#6B5B73" strokeWidth="2" /><path d="M8.5 7 L10 4.5 H14 L15.5 7" fill="none" stroke="#6B5B73" strokeWidth="2" strokeLinejoin="round" /></svg>
              <div className="text-lg font-extrabold text-muted">No photo yet</div>
            </div>
          )}
          {photo && <div className="text-[15px] font-bold text-muted">Chosen: {photo.name}</div>}
          <input id="camInput" type="file" accept="image/*" capture="environment" onChange={onFile} className="hidden" />
          <input id="fileInput" type="file" accept="image/*" onChange={onFile} className="hidden" />
          <label htmlFor="camInput" className="flex min-h-[60px] cursor-pointer items-center justify-center rounded-[18px] bg-berry text-[20px] font-black text-white">Take photo with camera</label>
          <label htmlFor="fileInput" className="flex min-h-[60px] cursor-pointer items-center justify-center rounded-[18px] border-2 border-ink bg-white text-center text-[20px] font-black">Upload from phone or gallery</label>
        </section>

        <Field label="2. Item name" error={errors.name?.message}>
          <input className={inputClass} placeholder="Example: Coconut" {...register('name')} />
        </Field>

        <section className="flex flex-col gap-2">
          <div className="text-lg font-black">3. Section</div>
          <div className="flex flex-wrap gap-2">
            {list.map((c) => <button key={c.id} type="button" aria-pressed={cat === c.id} className={chip(cat === c.id)} onClick={() => setValue('category_id', c.id, { shouldValidate: true })}>{c.name}</button>)}
          </div>
          {errors.category_id && <span className="text-[15px] font-extrabold text-rust">{errors.category_id.message}</span>}
        </section>

        <section className="flex flex-col gap-2">
          <div className="text-lg font-black">4. Price</div>
          <div className="flex items-center justify-between rounded-[18px] bg-white px-3.5 py-2.5">
            <button type="button" aria-label="Lower price by 5 rupees" onClick={() => setValue('price', Math.max(MIN_PRICE, price - STEP))} className="h-[60px] w-[60px] rounded-2xl bg-sand text-[32px] font-black">−</button>
            <span aria-live="polite" className="text-[32px] font-black">{rupees(price)}</span>
            <button type="button" aria-label="Raise price by 5 rupees" onClick={() => setValue('price', price + STEP)} className="h-[60px] w-[60px] rounded-2xl bg-berry text-[32px] font-black text-white">+</button>
          </div>
        </section>

        <section className="flex flex-col gap-2">
          <div className="text-lg font-black">5. Show in billing?</div>
          <button type="button" aria-pressed={avail} onClick={() => setValue('is_active', !avail)} className={toggle(avail)}>{avail ? 'YES · Sell it in billing' : 'NO · Hide it from billing'}</button>
        </section>

        <details className="rounded-[18px] bg-white p-3.5">
          <summary className="flex min-h-[44px] cursor-pointer items-center text-lg font-black">More details</summary>
          <div className="mt-3 flex flex-col gap-3">
            <div className="grid grid-cols-2 gap-2">
              {gstOn && <Field label="GST %" error={errors.gst_rate?.message}><input className={inputClass} inputMode="decimal" {...register('gst_rate')} /></Field>}
              <Field label="Purchase cost ₹"><input className={inputClass} inputMode="decimal" {...register('cost')} /></Field>
              <Field label="SKU"><input className={inputClass} {...register('sku')} /></Field>
              <Field label="Product code"><input className={inputClass} {...register('code')} /></Field>
              <Field label="Unit"><input className={inputClass} {...register('unit')} /></Field>
              <Field label="Minimum stock"><input className={inputClass} inputMode="decimal" {...register('min_stock')} /></Field>
            </div>
            <Field label="Description"><textarea className={`${inputClass} py-3`} rows={2} {...register('description')} /></Field>
            <button type="button" aria-pressed={track} onClick={() => setValue('track_inventory', !track)} className={toggle(track)}>{track ? 'Stock is tracked' : 'Stock is not tracked'}</button>
            {track && (
              <>
                <Field label="When is stock deducted?">
                  <select className={inputClass} {...register('inventory_method')}>
                    <option value="on_serve">When served or billed</option>
                    <option value="on_add">As soon as it is added</option>
                  </select>
                </Field>
                {!editing && <Field label="Opening stock"><input className={inputClass} inputMode="decimal" {...register('opening_stock')} /></Field>}
              </>
            )}
            <label className="flex min-h-[52px] items-center gap-3 text-lg font-black"><input type="checkbox" className="h-7 w-7" {...register('is_favourite')} /> Favourite (quick tile)</label>
            <div className="flex flex-col gap-2">
              <div className="text-lg font-black">Variants</div>
              {variants.fields.map((f, i) => (
                <div key={f.id} className="grid grid-cols-[1fr_88px_52px] gap-2">
                  <input aria-label="Variant name" placeholder="Small" className={inputClass} {...register(`variants.${i}.name`)} />
                  <input aria-label="Variant price" placeholder="₹" inputMode="decimal" className={inputClass} {...register(`variants.${i}.price`)} />
                  <Button tone="danger" aria-label="Remove variant" onClick={() => variants.remove(i)}>×</Button>
                </div>
              ))}
              <Button tone="plain" onClick={() => variants.append({ name: '', price: String(price), sku: '' })}>+ Add variant</Button>
            </div>
          </div>
        </details>

        {saved && <div role="status" className="rounded-[18px] bg-mint-soft px-4 py-3.5 text-lg font-extrabold text-mint-ink">Item saved.</div>}
      </div>
      <footer className="border-t-2 border-line-soft bg-white px-4 pb-5 pt-3.5">
        <button type="submit" disabled={noName || busy} className={`h-[68px] w-full rounded-[20px] text-[21px] font-black text-white ${saved ? 'bg-mint' : 'bg-berry disabled:bg-line-strong'}`}>
          {saved ? 'SAVED ✓' : noName ? 'TYPE A NAME FIRST' : 'SAVE ITEM'}
        </button>
      </footer>
    </form>
  );
}
