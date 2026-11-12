import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge, Button, Card, Chip, ErrorBanner, Field, inputClass, Loading, Modal, PageHeader, Pager, useBusy } from '../components/ui';
import { api } from '../lib/api';
import { dateTime, rupees } from '../lib/format';
import { connectPrinter, deviceConnected, printerSupport, type PrintDriver } from '../lib/printer';
import type { Category, Product, Settings as SettingsMap } from '../lib/types';
import { useApp } from '../stores/app';

type Tab = 'business' | 'printer' | 'offers' | 'loyalty' | 'staff' | 'backups' | 'audit';

export default function Settings() {
  const can = useApp((s) => s.can);
  const tabs: { id: Tab; label: string; show: boolean }[] = [
    { id: 'business', label: 'Business', show: can('settings.manage') },
    { id: 'printer', label: 'Printer', show: true },
    { id: 'offers', label: 'Offers', show: can('promotions.manage') },
    { id: 'loyalty', label: 'Loyalty', show: can('settings.manage') },
    { id: 'staff', label: 'Staff', show: can('staff.manage') },
    { id: 'backups', label: 'Backups', show: can('backups.manage') },
    { id: 'audit', label: 'Audit', show: can('staff.manage') },
  ];
  const visible = tabs.filter((t) => t.show);
  const [tab, setTab] = useState<Tab>(visible[0].id);
  return (
    <div className="flex flex-col pb-6">
      <PageHeader title="Settings" />
      <div className="flex flex-col gap-2.5 px-4">
        <div className="scroll-x flex gap-2">
          {visible.map((t) => <Chip key={t.id} active={tab === t.id} onClick={() => setTab(t.id)}>{t.label}</Chip>)}
        </div>
        {tab === 'business' && <BusinessTab />}
        {tab === 'printer' && <PrinterTab />}
        {tab === 'offers' && <OffersTab />}
        {tab === 'loyalty' && <LoyaltyTab />}
        {tab === 'staff' && <StaffTab />}
        {tab === 'backups' && <BackupsTab />}
        {tab === 'audit' && <AuditTab />}
      </div>
    </div>
  );
}

function useSettingsForm() {
  const app = useApp();
  const [values, setValues] = useState<SettingsMap>(app.settings);
  const [saved, setSaved] = useState(false);
  const { busy, error, run } = useBusy();
  useEffect(() => { api.get<SettingsMap>('/settings').then(setValues).catch(() => {}); }, []);
  const set = (key: string, value: string | number | boolean) => { setValues((v) => ({ ...v, [key]: value })); setSaved(false); };
  const save = () => run(async () => {
    const res = await api.put<SettingsMap>('/settings', { values });
    app.setSettings(res);
    setSaved(true);
  });
  return { values, set, save, saved, busy, error, editable: app.can('settings.manage') };
}

function SaveBar({ form }: { form: ReturnType<typeof useSettingsForm> }) {
  if (!form.editable) return null;
  return <button type="button" onClick={form.save} disabled={form.busy} className={`h-16 rounded-[20px] text-[21px] font-black text-white ${form.saved ? 'bg-mint' : 'bg-berry'}`}>{form.saved ? 'SAVED ✓' : 'SAVE CHANGES'}</button>;
}

function BusinessTab() {
  const f = useSettingsForm();
  const text = (key: string, label: string, hint?: string) => (
    <Field label={label} hint={hint}><input className={inputClass} value={String(f.values[key] ?? '')} onChange={(e) => f.set(key, e.target.value)} /></Field>
  );
  return (
    <>
      <ErrorBanner error={f.error} />
      {text('business.name', 'Business name')}
      {text('business.address', 'Address')}
      {text('business.phone', 'Phone')}
      {text('receipt.footer', 'Receipt footer')}
      <button type="button" aria-pressed={Boolean(f.values['tax.enabled'])} onClick={() => f.set('tax.enabled', !f.values['tax.enabled'])} className={`min-h-[60px] rounded-[18px] text-[19px] font-black ${f.values['tax.enabled'] ? 'border-[3px] border-mint bg-mint-soft' : 'border-2 border-line-strong bg-white'}`}>
        {f.values['tax.enabled'] ? 'GST is on' : 'GST is off'}
      </button>
      {Boolean(f.values['tax.enabled']) && (
        <>
          {text('business.gstin', 'GSTIN')}
          <button type="button" aria-pressed={Boolean(f.values['tax.prices_inclusive'])} onClick={() => f.set('tax.prices_inclusive', !f.values['tax.prices_inclusive'])} className={`min-h-[60px] rounded-[18px] text-[19px] font-black ${f.values['tax.prices_inclusive'] ? 'border-[3px] border-mint bg-mint-soft' : 'border-2 border-line-strong bg-white'}`}>
            {f.values['tax.prices_inclusive'] ? 'Prices include GST' : 'GST is added on top of prices'}
          </button>
        </>
      )}
      <Field label="Staff can discount up to (%) without the owner PIN"><input className={inputClass} inputMode="decimal" value={String(f.values['discount.staff_max_percent'] ?? 0)} onChange={(e) => f.set('discount.staff_max_percent', Number(e.target.value) || 0)} /></Field>
      <Field label="Staff can edit a bill for (minutes after billing)"><input className={inputClass} inputMode="numeric" value={String(f.values['billing.staff_edit_minutes'] ?? 30)} onChange={(e) => f.set('billing.staff_edit_minutes', Number(e.target.value) || 0)} /></Field>
      <SaveBar form={f} />
    </>
  );
}

function PrinterTab() {
  const f = useSettingsForm();
  const driver = (f.values['printer.driver'] ?? 'browser') as PrintDriver;
  const status = useQuery({ queryKey: ['printer-status'], queryFn: () => api.get<{ connected: boolean; message: string }>('/printer/status'), enabled: driver === 'network', refetchInterval: 10000 });
  const logs = useQuery({ queryKey: ['printer-logs'], queryFn: () => api.page<{ id: number; driver: string; status: string; error: string | null; is_duplicate: boolean; created_at: string; invoice?: { invoice_number: string } }>('/printer/logs', { per_page: 10 }) });
  const [connected, setConnected] = useState(deviceConnected(driver));
  const { busy, error, run } = useBusy();
  const options: { id: PrintDriver; label: string; note: string; ok: boolean }[] = [
    { id: 'browser', label: 'System print', note: 'Uses the Android or PC print dialog', ok: true },
    { id: 'bluetooth', label: 'Bluetooth', note: 'Direct from this device', ok: printerSupport.bluetooth },
    { id: 'usb', label: 'USB', note: 'Direct from this device', ok: printerSupport.usb },
    { id: 'network', label: 'Network', note: 'The server must reach the printer, so not on online hosting', ok: true },
  ];
  return (
    <>
      <ErrorBanner error={f.error ?? error} />
      <div className="grid grid-cols-2 gap-2">
        {options.map((o) => (
          <button key={o.id} type="button" disabled={!o.ok} aria-pressed={driver === o.id} onClick={() => f.set('printer.driver', o.id)} className={`min-h-[84px] rounded-[18px] px-2 text-center disabled:opacity-50 ${driver === o.id ? 'border-[3px] border-berry bg-berry-soft' : 'border-2 border-line bg-white'}`}>
            <span className="block text-lg font-black">{o.label}</span>
            <span className="block text-[15px] font-bold text-muted">{o.ok ? o.note : 'Not available in this browser'}</span>
          </button>
        ))}
      </div>
      <div className="flex gap-2">
        {[58, 80].map((w) => <Chip key={w} active={Number(f.values['printer.width']) === w} onClick={() => f.set('printer.width', w)}>{w} mm paper</Chip>)}
      </div>
      {driver === 'network' && (
        <>
          <div className="grid grid-cols-[1fr_110px] gap-2">
            <Field label="Printer IP address"><input className={inputClass} inputMode="decimal" value={String(f.values['printer.host'] ?? '')} onChange={(e) => f.set('printer.host', e.target.value)} /></Field>
            <Field label="Port"><input className={inputClass} inputMode="numeric" value={String(f.values['printer.port'] ?? 9100)} onChange={(e) => f.set('printer.port', Number(e.target.value))} /></Field>
          </div>
          {status.data && <Badge tone={status.data.connected ? 'ok' : 'out'}>{status.data.message}</Badge>}
        </>
      )}
      {(driver === 'bluetooth' || driver === 'usb') && (
        <Card className="flex items-center justify-between gap-2">
          <Badge tone={connected ? 'ok' : 'low'}>{connected ? 'Connected' : 'Not connected on this device'}</Badge>
          <Button tone="plain" disabled={busy} onClick={() => run(async () => { await connectPrinter(driver); setConnected(deviceConnected(driver)); })}>Connect printer</Button>
        </Card>
      )}
      <SaveBar form={f} />
      <h2 className="mt-2 text-[20px] font-black">Print log</h2>
      {logs.data?.data.map((l) => (
        <div key={l.id} className="flex items-start justify-between gap-2 rounded-2xl bg-white px-3 py-2 text-base font-bold">
          <div className="min-w-0">{l.invoice?.invoice_number} · {l.driver}{l.is_duplicate ? ' · duplicate' : ''} · {dateTime(l.created_at)}{l.error && <div className="text-rust">{l.error}</div>}</div>
          <Badge tone={l.status === 'success' ? 'ok' : 'out'}>{l.status === 'success' ? 'Printed' : 'Failed'}</Badge>
        </div>
      ))}
    </>
  );
}

function LoyaltyTab() {
  const f = useSettingsForm();
  const num = (key: string, label: string, hint?: string) => (
    <Field label={label} hint={hint}><input className={inputClass} inputMode="decimal" value={String(f.values[key] ?? '')} onChange={(e) => f.set(key, Number(e.target.value) || 0)} /></Field>
  );
  return (
    <>
      <ErrorBanner error={f.error} />
      <button type="button" aria-pressed={Boolean(f.values['loyalty.enabled'])} onClick={() => f.set('loyalty.enabled', !f.values['loyalty.enabled'])} className={`min-h-[60px] rounded-[18px] text-[19px] font-black ${f.values['loyalty.enabled'] ? 'border-[3px] border-mint bg-mint-soft' : 'border-2 border-line-strong bg-white'}`}>
        {f.values['loyalty.enabled'] ? 'Loyalty is on' : 'Loyalty is off'}
      </button>
      {num('loyalty.points_per_100', 'Points earned per ₹100')}
      {num('loyalty.rupees_per_point', 'Rupees one point is worth')}
      {num('loyalty.min_redeem_points', 'Fewest points that can be redeemed')}
      <SaveBar form={f} />
    </>
  );
}

interface Promo {
  id: number;
  name: string;
  discount_type: 'percent' | 'fixed' | 'buy_get' | 'bundle';
  discount_value: string;
  buy_qty: number | null;
  get_qty: number | null;
  get_scope: 'same' | 'product' | 'category' | null;
  get_products: { id: number; name: string }[];
  get_categories: { id: number; name: string }[];
  scope: 'order' | 'product' | 'category';
  min_purchase: string;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  requires_authorization: boolean;
  products: { id: number; name: string }[];
  categories: { id: number; name: string }[];
}

function offerLists(p: Promo): string | null {
  if (p.discount_type !== 'buy_get') return null;
  const names = (list: { name: string }[]) => list.map((x) => x.name).join(', ');
  const buy = p.scope === 'order' ? 'any item' : names(p.scope === 'product' ? p.products : p.categories);
  const free = p.get_scope === 'product' ? names(p.get_products) : p.get_scope === 'category' ? names(p.get_categories) : 'same items';
  return `Buy: ${buy} · Free: ${free}`;
}

function offerLabel(p: Promo): string {
  if (p.discount_type === 'buy_get') return `Buy ${p.buy_qty} get ${p.get_qty} free`;
  if (p.discount_type === 'bundle') return `${p.buy_qty} for ${rupees(p.discount_value)}`;
  return p.discount_type === 'percent' ? `${Number(p.discount_value)}% off` : `${rupees(p.discount_value)} off`;
}

function OffersTab() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['promotions'], queryFn: () => api.get<Promo[]>('/promotions') });
  const [editing, setEditing] = useState<Promo | 'new' | null>(null);
  const { busy, error, run } = useBusy();
  const toggle = (p: Promo) => run(async () => {
    await api.put(`/promotions/${p.id}`, { ...p, is_active: !p.is_active, product_ids: p.products.map((x) => x.id), category_ids: p.categories.map((x) => x.id), get_product_ids: p.get_products.map((x) => x.id), get_category_ids: p.get_categories.map((x) => x.id) });
    qc.invalidateQueries({ queryKey: ['promotions'] });
  });
  return (
    <>
      <ErrorBanner error={error} />
      <Button onClick={() => setEditing('new')}>+ New offer</Button>
      {isLoading && <Loading />}
      {data?.map((p) => (
        <Card key={p.id} className="flex flex-col gap-2">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="text-[19px] font-black">{p.name}</div>
              <div className="text-base font-bold text-muted">
                {offerLabel(p)} · {p.scope === 'order' ? 'whole order' : p.scope === 'product' ? 'selected items' : 'selected categories'}
                {Number(p.min_purchase) > 0 ? ` · above ${rupees(p.min_purchase)}` : ''}{p.requires_authorization ? ' · owner approval' : ''}
              </div>
              {offerLists(p) && <div className="text-base font-bold text-muted">{offerLists(p)}</div>}
            </div>
            <Badge tone={p.is_active ? 'ok' : 'grey'}>{p.is_active ? 'On' : 'Off'}</Badge>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Button tone="plain" onClick={() => setEditing(p)}>Edit</Button>
            <Button tone="plain" disabled={busy} onClick={() => toggle(p)}>{p.is_active ? 'Turn off' : 'Turn on'}</Button>
          </div>
        </Card>
      ))}
      {editing && <PromoForm promo={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}

function PromoForm({ promo, onClose }: { promo: Promo | null; onClose: () => void }) {
  const qc = useQueryClient();
  const products = useQuery({ queryKey: ['products'], queryFn: () => api.get<Product[]>('/products') });
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.get<Category[]>('/categories') });
  const [f, setF] = useState({
    name: promo?.name ?? '',
    discount_type: promo?.discount_type ?? 'percent',
    discount_value: promo ? String(Number(promo.discount_value)) : '',
    buy_qty: promo?.buy_qty ? String(promo.buy_qty) : '',
    get_qty: promo?.get_qty ? String(promo.get_qty) : '',
    get_scope: promo?.get_scope ?? 'same',
    scope: promo?.scope ?? 'order',
    min_purchase: promo ? String(Number(promo.min_purchase)) : '0',
    starts_at: promo?.starts_at?.slice(0, 10) ?? '',
    ends_at: promo?.ends_at?.slice(0, 10) ?? '',
    requires_authorization: promo?.requires_authorization ?? false,
  });
  const [ids, setIds] = useState<number[]>(promo ? [...promo.products, ...promo.categories].map((x) => x.id) : []);
  const [getIds, setGetIds] = useState<number[]>(promo ? [...promo.get_products, ...promo.get_categories].map((x) => x.id) : []);
  const flipGet = (id: number) => setGetIds((v) => (v.includes(id) ? v.filter((x) => x !== id) : [...v, id]));
  const { busy, error, run } = useBusy();
  const flip = (id: number) => setIds((v) => (v.includes(id) ? v.filter((x) => x !== id) : [...v, id]));
  const save = () => run(async () => {
    const body = {
      ...f,
      discount_value: Number(f.discount_value),
      buy_qty: Number(f.buy_qty) || null,
      get_qty: Number(f.get_qty) || null,
      min_purchase: Number(f.min_purchase || 0),
      starts_at: f.starts_at ? `${f.starts_at} 00:00:00` : null,
      ends_at: f.ends_at ? `${f.ends_at} 23:59:59` : null,
      is_active: promo?.is_active ?? true,
      product_ids: f.scope === 'product' ? ids : [],
      category_ids: f.scope === 'category' ? ids : [],
      get_scope: f.discount_type === 'buy_get' ? f.get_scope : null,
      get_product_ids: f.get_scope === 'product' ? getIds : [],
      get_category_ids: f.get_scope === 'category' ? getIds : [],
    };
    if (promo) await api.put(`/promotions/${promo.id}`, body);
    else await api.post('/promotions', body);
    qc.invalidateQueries({ queryKey: ['promotions'] });
    onClose();
  });
  const choices = f.scope === 'product' ? products.data : f.scope === 'category' ? categories.data : [];
  const isBuyGet = f.discount_type === 'buy_get';
  const getChoices = f.get_scope === 'product' ? products.data : f.get_scope === 'category' ? categories.data : [];
  const ready = f.discount_type === 'buy_get'
    ? Number(f.buy_qty) >= 1 && Number(f.get_qty) >= 1
    : f.discount_type === 'bundle' ? Number(f.buy_qty) >= 2 && Number(f.discount_value) > 0 : Number(f.discount_value) > 0;
  return (
    <Modal title={promo ? 'Edit offer' : 'New offer'} onClose={onClose} footer={<Button big className="w-full" disabled={busy || !f.name.trim() || !ready || (f.scope !== 'order' && ids.length === 0) || (isBuyGet && f.get_scope !== 'same' && getIds.length === 0)} onClick={save}>SAVE OFFER</Button>}>
      <ErrorBanner error={error} />
      <Field label="Offer name"><input className={inputClass} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
      <div className="flex flex-wrap gap-2">
        <Chip active={f.discount_type === 'percent'} onClick={() => setF({ ...f, discount_type: 'percent' })}>Percent</Chip>
        <Chip active={f.discount_type === 'fixed'} onClick={() => setF({ ...f, discount_type: 'fixed' })}>Fixed ₹</Chip>
        <Chip active={f.discount_type === 'buy_get'} onClick={() => setF({ ...f, discount_type: 'buy_get' })}>Buy & get free</Chip>
        <Chip active={f.discount_type === 'bundle'} onClick={() => setF({ ...f, discount_type: 'bundle' })}>Set price</Chip>
      </div>
      {f.discount_type === 'buy_get' && (
        <>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Buy"><input className={inputClass} inputMode="numeric" value={f.buy_qty} onChange={(e) => setF({ ...f, buy_qty: e.target.value })} /></Field>
            <Field label="Get free"><input className={inputClass} inputMode="numeric" value={f.get_qty} onChange={(e) => setF({ ...f, get_qty: e.target.value })} /></Field>
          </div>
          <div className="text-[16px] font-bold text-muted">Add the free items to the order too, so they come out of stock. If several items could be free, the cheapest are the free ones.</div>
        </>
      )}
      {f.discount_type === 'bundle' && (
        <>
          <div className="grid grid-cols-2 gap-2">
            <Field label="How many"><input className={inputClass} inputMode="numeric" value={f.buy_qty} onChange={(e) => setF({ ...f, buy_qty: e.target.value })} /></Field>
            <Field label="For ₹"><input className={inputClass} inputMode="decimal" value={f.discount_value} onChange={(e) => setF({ ...f, discount_value: e.target.value })} /></Field>
          </div>
          <div className="text-[16px] font-bold text-muted">Flavours can mix. Extra items pay the normal price. It applies only when this is cheaper than the normal prices.</div>
        </>
      )}
      {(f.discount_type === 'percent' || f.discount_type === 'fixed') && (
        <Field label={f.discount_type === 'percent' ? 'Percent off' : 'Rupees off'}><input className={inputClass} inputMode="decimal" value={f.discount_value} onChange={(e) => setF({ ...f, discount_value: e.target.value })} /></Field>
      )}
      {isBuyGet && <div className="text-lg font-black">Buy section: items the customer pays for</div>}
      <div className="flex flex-wrap gap-2">
        {(['order', 'product', 'category'] as const).map((s) => <Chip key={s} active={f.scope === s} onClick={() => { setF({ ...f, scope: s }); setIds([]); }}>{s === 'order' ? 'Whole order' : s === 'product' ? 'Items' : 'Categories'}</Chip>)}
      </div>
      {choices && choices.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {choices.map((c) => <Chip key={c.id} active={ids.includes(c.id)} onClick={() => flip(c.id)}>{c.name}</Chip>)}
        </div>
      )}
      {isBuyGet && (
        <>
          <div className="text-lg font-black">Get section: items that are free</div>
          <div className="flex flex-wrap gap-2">
            {(['same', 'product', 'category'] as const).map((s) => <Chip key={s} active={f.get_scope === s} onClick={() => { setF({ ...f, get_scope: s }); setGetIds([]); }}>{s === 'same' ? 'Same as buy' : s === 'product' ? 'Items' : 'Categories'}</Chip>)}
          </div>
          {getChoices && getChoices.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {getChoices.map((c) => <Chip key={c.id} tone="mint" active={getIds.includes(c.id)} onClick={() => flipGet(c.id)}>{c.name}</Chip>)}
            </div>
          )}
        </>
      )}
      <Field label="Minimum purchase ₹"><input className={inputClass} inputMode="decimal" value={f.min_purchase} onChange={(e) => setF({ ...f, min_purchase: e.target.value })} /></Field>
      <div className="grid grid-cols-2 gap-2">
        <Field label="Starts"><input type="date" className={inputClass} value={f.starts_at} onChange={(e) => setF({ ...f, starts_at: e.target.value })} /></Field>
        <Field label="Ends"><input type="date" className={inputClass} value={f.ends_at} onChange={(e) => setF({ ...f, ends_at: e.target.value })} /></Field>
      </div>
      <label className="flex min-h-[52px] items-center gap-3 text-lg font-black"><input type="checkbox" className="h-7 w-7" checked={f.requires_authorization} onChange={(e) => setF({ ...f, requires_authorization: e.target.checked })} /> Needs owner approval</label>
    </Modal>
  );
}

interface StaffUser { id: number; name: string; username: string; role_id: number; is_active: boolean; role?: { label: string } }

function StaffTab() {
  const qc = useQueryClient();
  const { data } = useQuery({ queryKey: ['staff'], queryFn: () => api.get<{ staff: StaffUser[]; roles: { id: number; label: string }[] }>('/staff') });
  const [editing, setEditing] = useState<StaffUser | 'new' | null>(null);
  const [f, setF] = useState({ name: '', username: '', password: '', pin: '', role_id: 0, is_active: true });
  const { busy, error, run } = useBusy();
  const open = (u: StaffUser | 'new') => {
    setEditing(u);
    setF(u === 'new' ? { name: '', username: '', password: '', pin: '', role_id: data?.roles[1]?.id ?? 0, is_active: true } : { name: u.name, username: u.username, password: '', pin: '', role_id: u.role_id, is_active: u.is_active });
  };
  const save = () => run(async () => {
    const body = { ...f, password: f.password || null, pin: f.pin || null };
    if (editing === 'new') await api.post('/staff', body);
    else if (editing) await api.put(`/staff/${editing.id}`, body);
    qc.invalidateQueries({ queryKey: ['staff'] });
    setEditing(null);
  });
  return (
    <>
      <Button onClick={() => open('new')}>+ New staff</Button>
      {data?.staff.map((u) => (
        <Card key={u.id} className="flex items-center justify-between gap-2">
          <div className="min-w-0">
            <div className="text-[19px] font-black">{u.name}</div>
            <div className="text-base font-bold text-muted">{u.username} · {u.role?.label}</div>
            {!u.is_active && <Badge tone="grey">Disabled</Badge>}
          </div>
          <Button tone="plain" onClick={() => open(u)}>Edit</Button>
        </Card>
      ))}
      {editing && (
        <Modal title={editing === 'new' ? 'New staff' : `Edit ${editing.name}`} onClose={() => setEditing(null)} footer={<Button big className="w-full" disabled={busy} onClick={save}>SAVE</Button>}>
          <ErrorBanner error={error} />
          <Field label="Name"><input className={inputClass} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          <Field label="Username"><input className={inputClass} autoCapitalize="none" value={f.username} onChange={(e) => setF({ ...f, username: e.target.value })} /></Field>
          <Field label="Password" hint={editing === 'new' ? 'At least 8 characters.' : 'Leave empty to keep the current one.'}><input type="password" autoComplete="new-password" className={inputClass} value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} /></Field>
          <Field label="Approval PIN (optional)" hint="4 to 6 digits, for owner approvals at the counter."><input type="password" inputMode="numeric" autoComplete="off" className={inputClass} value={f.pin} onChange={(e) => setF({ ...f, pin: e.target.value })} /></Field>
          <Field label="Role">
            <select className={inputClass} value={f.role_id} onChange={(e) => setF({ ...f, role_id: Number(e.target.value) })}>
              {data?.roles.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
            </select>
          </Field>
          <label className="flex min-h-[52px] items-center gap-3 text-lg font-black"><input type="checkbox" className="h-7 w-7" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} /> Can sign in</label>
        </Modal>
      )}
    </>
  );
}

function BackupsTab() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['backups'], queryFn: () => api.get<{ id: number; status: string; trigger: string; file_name: string | null; size_bytes: number | null; error: string | null; created_at: string }[]>('/backups') });
  const { busy, error, run } = useBusy();
  return (
    <>
      <ErrorBanner error={error} />
      <Button tone="mint" big disabled={busy} onClick={() => run(async () => { await api.post('/backups'); qc.invalidateQueries({ queryKey: ['backups'] }); })}>{busy ? 'BACKING UP…' : 'BACK UP NOW'}</Button>
      <div className="text-[16px] font-bold text-muted">A backup runs every night at 23:30 and is encrypted on disk. Restoring is done on the server; see the backup guide.</div>
      {isLoading && <Loading />}
      {data?.map((b) => (
        <Card key={b.id} className="flex items-center justify-between gap-2">
          <div className="min-w-0">
            <div className="truncate text-[17px] font-black">{b.file_name ?? 'No file'}</div>
            <div className="text-[15px] font-bold text-muted">{dateTime(b.created_at)} · {b.trigger}{b.size_bytes ? ` · ${Math.ceil(b.size_bytes / 1024)} KB` : ''}</div>
            {b.error && <div className="text-[15px] font-extrabold text-rust">{b.error}</div>}
          </div>
          {b.status === 'success' && b.file_name
            ? <Button tone="plain" onClick={() => run(() => api.download(`/backups/${b.id}/download`, b.file_name!))}>Download</Button>
            : <Badge tone="out">{b.status}</Badge>}
        </Card>
      ))}
    </>
  );
}

function AuditTab() {
  const [page, setPage] = useState(1);
  const [action, setAction] = useState('');
  const { data, isLoading } = useQuery({
    queryKey: ['audit', page, action],
    queryFn: () => api.page<{ id: number; action: string; reason: string | null; created_at: string; user?: { name: string } | null; authorizer?: { name: string } | null; after: unknown }>('/audit-logs', { page, action: action || undefined }),
  });
  return (
    <>
      <div className="scroll-x flex gap-2">
        {[['', 'All'], ['order', 'Orders'], ['stock', 'Stock'], ['payment', 'Payments'], ['product', 'Products'], ['auth', 'Sign-ins']].map(([id, label]) => <Chip key={id} active={action === id} onClick={() => { setAction(id); setPage(1); }}>{label}</Chip>)}
      </div>
      {isLoading && <Loading />}
      {data?.data.map((a) => (
        <div key={a.id} className="rounded-2xl bg-white px-3 py-2 text-base font-bold">
          <div className="font-black">{a.action}</div>
          <div className="text-muted">{dateTime(a.created_at)} · {a.user?.name ?? 'System'}{a.authorizer ? ` · approved by ${a.authorizer.name}` : ''}{a.reason ? ` · ${a.reason}` : ''}</div>
        </div>
      ))}
      <Pager meta={data?.meta} onPage={setPage} />
    </>
  );
}
