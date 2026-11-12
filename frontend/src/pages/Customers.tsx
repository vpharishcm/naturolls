import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router-dom';
import { z } from 'zod';
import { Badge, Button, Card, Empty, ErrorBanner, Field, inputClass, Loading, Modal, PageHeader, Pager, useBusy } from '../components/ui';
import { api } from '../lib/api';
import { dateTime, rupees } from '../lib/format';
import type { Customer, Invoice } from '../lib/types';
import { useApp } from '../stores/app';

const schema = z.object({
  name: z.string().trim().min(1, 'Enter a name'),
  mobile: z.string().regex(/^$|^[0-9+\- ]{6,20}$/, 'Enter a valid mobile number'),
  birthday: z.string().optional(),
  notes: z.string().optional(),
  join_loyalty: z.boolean(),
});
type Form = z.infer<typeof schema>;

export default function Customers() {
  const can = useApp((s) => s.can);
  const [term, setTerm] = useState('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Customer | 'new' | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);
  const { data, isLoading, error } = useQuery({
    queryKey: ['customers', term, page],
    queryFn: () => api.page<Customer>('/customers', { search: term || undefined, page }),
    placeholderData: keepPreviousData,
  });
  return (
    <div className="flex flex-col pb-6">
      <PageHeader title="Customers" action={can('customers.manage') && <Button onClick={() => setEditing('new')}>+ New</Button>} />
      <div className="flex flex-col gap-2.5 px-4">
        <input aria-label="Search customers" placeholder="Name or mobile" className={inputClass} value={term} onChange={(e) => { setTerm(e.target.value); setPage(1); }} />
        <ErrorBanner error={error} />
        {isLoading && <Loading />}
        {data?.data.map((c) => (
          <button key={c.id} type="button" className="text-left" onClick={() => setOpenId(c.id)}>
            <Card>
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="text-[20px] font-black">{c.name}</div>
                  <div className="text-base font-bold text-muted">{c.mobile ?? 'No mobile'} · {c.invoices_count ?? 0} bills</div>
                  {c.loyalty_account && <Badge tone="info">{c.loyalty_account.points_balance} points</Badge>}
                </div>
                <div className="shrink-0 text-[22px] font-black">{rupees(c.total_spent ?? 0)}</div>
              </div>
            </Card>
          </button>
        ))}
        {!isLoading && !data?.data.length && <Empty>No customers yet.</Empty>}
        <Pager meta={data?.meta} onPage={setPage} />
      </div>
      {editing && <CustomerForm customer={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {openId && <CustomerDetail id={openId} onClose={() => setOpenId(null)} onEdit={(c) => { setOpenId(null); setEditing(c); }} />}
    </div>
  );
}

function CustomerForm({ customer, onClose }: { customer: Customer | null; onClose: () => void }) {
  const qc = useQueryClient();
  const { busy, error, run } = useBusy();
  const { register, handleSubmit, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { name: customer?.name ?? '', mobile: customer?.mobile ?? '', birthday: customer?.birthday ?? '', notes: customer?.notes ?? '', join_loyalty: Boolean(customer?.loyalty_account) },
  });
  const save = handleSubmit((v) => run(async () => {
    const body = { ...v, mobile: v.mobile || null, birthday: v.birthday || null, notes: v.notes || null };
    if (customer) await api.put(`/customers/${customer.id}`, body);
    else await api.post('/customers', body);
    qc.invalidateQueries({ queryKey: ['customers'] });
    onClose();
  }));
  return (
    <Modal title={customer ? 'Edit customer' : 'New customer'} onClose={onClose} footer={<Button big className="w-full" disabled={busy} onClick={save}>SAVE</Button>}>
      <ErrorBanner error={error} />
      <Field label="Name" error={errors.name?.message}><input className={inputClass} {...register('name')} /></Field>
      <Field label="Mobile number" error={errors.mobile?.message}><input className={inputClass} inputMode="tel" {...register('mobile')} /></Field>
      <Field label="Birthday (optional)"><input type="date" className={inputClass} {...register('birthday')} /></Field>
      <Field label="Notes (optional)"><textarea className={`${inputClass} py-3`} rows={2} {...register('notes')} /></Field>
      <label className="flex min-h-[52px] items-center gap-3 text-lg font-black"><input type="checkbox" className="h-7 w-7" {...register('join_loyalty')} /> Join loyalty points</label>
    </Modal>
  );
}

function CustomerDetail({ id, onClose, onEdit }: { id: number; onClose: () => void; onEdit: (c: Customer) => void }) {
  const can = useApp((s) => s.can);
  const qc = useQueryClient();
  const key = ['customer', id];
  const { data, isLoading } = useQuery({ queryKey: key, queryFn: () => api.get<Customer & { recent_invoices: Invoice[] }>(`/customers/${id}`) });
  const [points, setPoints] = useState('');
  const [note, setNote] = useState('');
  const { busy, error, run } = useBusy();
  const account = data?.loyalty_account;
  return (
    <Modal title={data?.name ?? 'Customer'} onClose={onClose}>
      {isLoading && <Loading />}
      <ErrorBanner error={error} />
      {data && (
        <>
          <div className="text-[17px] font-bold text-muted">
            {data.mobile ?? 'No mobile'}{data.birthday ? ` · Birthday ${data.birthday}` : ''}
            {data.notes && <div>{data.notes}</div>}
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Card><div className="text-[15px] font-extrabold text-muted">Total spent</div><div className="text-[24px] font-black">{rupees(data.total_spent ?? 0)}</div></Card>
            <Card><div className="text-[15px] font-extrabold text-muted">Purchases</div><div className="text-[24px] font-black">{data.invoices_count ?? 0}</div></Card>
          </div>
          {can('customers.manage') && <Button tone="plain" onClick={() => onEdit(data)}>Edit customer</Button>}

          <h3 className="text-[20px] font-black">Loyalty</h3>
          {account ? (
            <>
              <div className="text-[18px] font-extrabold">{account.points_balance} points · earned {account.lifetime_earned} · redeemed {account.lifetime_redeemed}</div>
              {account.transactions?.slice(-10).reverse().map((t) => (
                <div key={t.id} className="flex justify-between text-base font-bold text-muted">
                  <span>{t.type}{t.note ? ` · ${t.note}` : ''} · {dateTime(t.created_at)}</span>
                  <span>{t.points > 0 ? `+${t.points}` : t.points}</span>
                </div>
              ))}
              {can('loyalty.adjust') && (
                <div className="grid grid-cols-[90px_1fr] gap-2">
                  <input aria-label="Points to adjust" placeholder="±" inputMode="numeric" className={inputClass} value={points} onChange={(e) => setPoints(e.target.value)} />
                  <input aria-label="Reason" placeholder="Reason" className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} />
                  <Button tone="plain" className="col-span-2" disabled={busy || !Number(points) || !note.trim()} onClick={() => run(async () => { await api.post(`/loyalty/${account.id}/adjust`, { points: Number(points), note }); setPoints(''); setNote(''); qc.invalidateQueries({ queryKey: key }); })}>Adjust points</Button>
                </div>
              )}
            </>
          ) : can('customers.manage') ? (
            <Button disabled={busy} onClick={() => run(async () => { await api.post(`/customers/${id}/loyalty`); qc.invalidateQueries({ queryKey: key }); })}>Join loyalty points</Button>
          ) : <div className="text-[17px] font-bold text-muted">Not a member.</div>}

          <h3 className="text-[20px] font-black">Purchase history</h3>
          {data.recent_invoices.map((i) => (
            <Link key={i.id} to={`/orders/${i.order_id}`} className="flex min-h-[48px] items-center justify-between gap-2 rounded-2xl bg-white px-3 text-[17px] font-extrabold">
              <span className="min-w-0 truncate">{i.invoice_number} <span className="font-bold text-muted">· {dateTime(i.issued_at)}</span></span>
              <span>{rupees(i.total)}</span>
            </Link>
          ))}
          {data.recent_invoices.length === 0 && <div className="text-[17px] font-bold text-muted">No purchases yet.</div>}
        </>
      )}
    </Modal>
  );
}
