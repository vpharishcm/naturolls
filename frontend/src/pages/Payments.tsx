import { useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Badge, Button, Card, Chip, Empty, ErrorBanner, Field, inputClass, Loading, PageHeader, Pager, Stat, useBusy } from '../components/ui';
import { api } from '../lib/api';
import { dateTime, rupees, today } from '../lib/format';
import type { Payment } from '../lib/types';
import { useApp } from '../stores/app';

interface Summary {
  date: string;
  methods: Record<string, { received: string; refunded: string; net: string; count: number }>;
  opening_balance: string;
  expected_cash: string;
  closing: { counted_balance: string; variance: string } | null;
}

export default function Payments() {
  const qc = useQueryClient();
  const can = useApp((s) => s.can);
  const [date, setDate] = useState(today());
  const [method, setMethod] = useState('');
  const [unreconciled, setUnreconciled] = useState(false);
  const [page, setPage] = useState(1);
  const [counted, setCounted] = useState('');
  const { busy, error, run } = useBusy();

  const summary = useQuery({ queryKey: ['payments', 'summary', date], queryFn: () => api.get<Summary>('/payments/summary', { date }) });
  const list = useQuery({
    queryKey: ['payments', 'list', date, method, unreconciled, page],
    queryFn: () => api.page<Payment>('/payments', { date: unreconciled ? undefined : date, method: method || undefined, unreconciled: unreconciled ? 1 : undefined, page }),
    placeholderData: keepPreviousData,
  });
  const refresh = () => qc.invalidateQueries({ queryKey: ['payments'] });
  const s = summary.data;
  const variance = counted === '' ? null : Number(counted) - Number(s?.expected_cash ?? 0);

  return (
    <div className="flex flex-col pb-6">
      <PageHeader title="Payments" />
      <div className="flex flex-col gap-2.5 px-4">
        <Field label="Date"><input type="date" className={inputClass} value={date} onChange={(e) => { setDate(e.target.value); setPage(1); }} /></Field>
        <ErrorBanner error={error ?? list.error ?? summary.error} />
        {s && (
          <>
            <div className="grid grid-cols-3 gap-2">
              {Object.entries(s.methods).map(([m, v]) => <Stat key={m} label={m.toUpperCase()} value={rupees(v.net)} />)}
            </div>
            <Card className="flex flex-col gap-2">
              <h2 className="text-[20px] font-black">Cash closing</h2>
              <div className="text-[17px] font-extrabold">
                <div className="flex justify-between"><span>Opening balance</span><span>{rupees(s.opening_balance)}</span></div>
                <div className="flex justify-between"><span>Cash received</span><span>{rupees(s.methods.cash.received)}</span></div>
                <div className="flex justify-between"><span>Cash refunded</span><span>−{rupees(s.methods.cash.refunded)}</span></div>
                <div className="flex justify-between border-t-2 border-line-soft pt-1 font-black"><span>Should be in drawer</span><span>{rupees(s.expected_cash)}</span></div>
              </div>
              {s.closing && <Badge tone={Number(s.closing.variance) === 0 ? 'ok' : 'low'}>Closed at {rupees(s.closing.counted_balance)} · difference {rupees(s.closing.variance)}</Badge>}
              {can('payments.manage') && (
                <>
                  <Field label="Cash counted"><input className={inputClass} inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} /></Field>
                  {variance !== null && <div className={`text-[17px] font-black ${variance === 0 ? 'text-mint' : 'text-rust'}`}>Difference {rupees(variance)}</div>}
                  <Button tone="mint" disabled={busy || counted === ''} onClick={() => run(async () => { await api.post('/payments/cash-closing', { business_date: date, counted_balance: Number(counted) }); setCounted(''); refresh(); })}>CLOSE CASH</Button>
                </>
              )}
            </Card>
          </>
        )}

        <div className="scroll-x flex gap-2">
          {['', 'cash', 'upi', 'card'].map((m) => <Chip key={m} active={method === m} onClick={() => { setMethod(m); setPage(1); }}>{m ? m.toUpperCase() : 'All'}</Chip>)}
          {can('payments.manage') && <Chip active={unreconciled} onClick={() => { setUnreconciled(!unreconciled); setPage(1); }}>To reconcile</Chip>}
        </div>
        {list.isLoading && <Loading />}
        {list.data?.data.map((p) => (
          <Card key={p.id}>
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <div className="text-[18px] font-black">{p.type === 'refund' ? 'Refund · ' : ''}{p.method.toUpperCase()}{p.reference ? ` · ${p.reference}` : ''}</div>
                <div className="text-[15px] font-bold text-muted">{dateTime(p.created_at)} · {p.invoice_number} · {p.staff}{p.reason ? ` · ${p.reason}` : ''}</div>
                {p.reconciled_at && <Badge tone="ok">Reconciled</Badge>}
              </div>
              <div className={`shrink-0 text-[22px] font-black ${p.type === 'refund' ? 'text-rust' : ''}`}>{p.type === 'refund' ? '−' : ''}{rupees(p.amount)}</div>
            </div>
            {unreconciled && <Button tone="plain" className="mt-2 w-full" disabled={busy} onClick={() => run(async () => { await api.post('/payments/reconcile', { payment_ids: [p.id] }); refresh(); })}>Matches bank / UPI statement</Button>}
          </Card>
        ))}
        {!list.isLoading && !list.data?.data.length && <Empty>No payments here.</Empty>}
        <Pager meta={list.data?.meta} onPage={setPage} />
        <div className="text-center text-[16px] font-bold text-muted">To refund or reprint, open the bill from <Link className="text-berry underline" to="/orders">All Orders</Link>.</div>
      </div>
    </div>
  );
}
