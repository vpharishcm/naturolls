import { useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge, Button, Card, Chip, Empty, ErrorBanner, Field, inputClass, Loading, Modal, PageHeader, Pager, useBusy } from '../components/ui';
import { api } from '../lib/api';
import { dateTime, qty, withCategory } from '../lib/format';
import { useApp } from '../stores/app';

interface Row {
  id: number;
  name: string;
  category: string | null;
  opening_qty: string;
  physical_qty: string;
  reserved_qty: string;
  available_qty: string;
  min_stock: string;
  state: 'ok' | 'low' | 'out';
}
interface Movement {
  id: number;
  type: string;
  physical_delta: string;
  reserved_delta: string;
  physical_after: string;
  reason: string | null;
  created_at: string;
  user?: { name: string } | null;
  inventory?: { product?: { name: string; category?: { name: string } | null } };
}

const TYPE_LABEL: Record<string, string> = { opening: 'Opening stock', reserve: 'Reserved', release: 'Released', consume: 'Consumed', production: 'Produced', production_reversal: 'Production reversed', correction: 'Stock count', wastage: 'Wastage', return: 'Returned', reconciliation: 'Reconciled' };

export default function Inventory() {
  const can = useApp((s) => s.can);
  const [filter, setFilter] = useState('');
  const [term, setTerm] = useState('');
  const [tab, setTab] = useState<'stock' | 'history'>('stock');
  const [action, setAction] = useState<{ row: Row; kind: 'correct' | 'wastage' | 'opening' } | null>(null);
  const [historyFor, setHistoryFor] = useState<Row | null>(null);
  const { data, isLoading, error } = useQuery({
    queryKey: ['inventory', filter, term],
    queryFn: () => api.get<Row[]>('/inventory', { filter: filter || undefined, search: term || undefined }),
    refetchInterval: 5000,
  });

  return (
    <div className="flex flex-col pb-6">
      <PageHeader title="Inventory" />
      <div className="flex flex-col gap-2.5 px-4">
        <div className="flex gap-2">
          <Chip active={tab === 'stock'} onClick={() => setTab('stock')}>Stock</Chip>
          <Chip active={tab === 'history'} onClick={() => { setHistoryFor(null); setTab('history'); }}>Movement history</Chip>
        </div>
        {tab === 'history' ? <History row={historyFor} /> : (
          <>
            <input aria-label="Search stock" placeholder="Search item" className={inputClass} value={term} onChange={(e) => setTerm(e.target.value)} />
            <div className="scroll-x flex gap-2">
              <Chip active={filter === ''} onClick={() => setFilter('')}>All</Chip>
              <Chip active={filter === 'low'} onClick={() => setFilter('low')}>Low stock</Chip>
              <Chip active={filter === 'out'} onClick={() => setFilter('out')}>Out of stock</Chip>
            </div>
            <ErrorBanner error={error} />
            {isLoading && <Loading />}
            {data?.map((r) => (
              <Card key={r.id} className="flex flex-col gap-2">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="text-[20px] font-black leading-tight">{r.name}</div>
                    <div className="text-[15px] font-bold text-muted">{r.category}</div>
                  </div>
                  <Badge tone={r.state}>{r.state === 'out' ? 'Out of stock' : r.state === 'low' ? 'Low' : 'OK'}</Badge>
                </div>
                <div className="grid grid-cols-3 gap-2 text-center">
                  <div className="rounded-2xl bg-cream py-1.5"><div className="text-[15px] font-bold text-muted">Physical</div><div className="text-[22px] font-black">{qty(r.physical_qty)}</div></div>
                  <div className="rounded-2xl bg-cream py-1.5"><div className="text-[15px] font-bold text-muted">Reserved</div><div className="text-[22px] font-black">{qty(r.reserved_qty)}</div></div>
                  <div className="rounded-2xl bg-mint-soft py-1.5"><div className="text-[15px] font-bold text-mint-ink">Available</div><div className="text-[22px] font-black">{qty(r.available_qty)}</div></div>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button tone="plain" onClick={() => { setHistoryFor(r); setTab('history'); }}>History</Button>
                  {can('stock.correct') && (
                    <>
                      <Button tone="plain" onClick={() => setAction({ row: r, kind: 'correct' })}>Stock count</Button>
                      <Button tone="plain" onClick={() => setAction({ row: r, kind: 'wastage' })}>Wastage</Button>
                      <Button tone="plain" onClick={() => setAction({ row: r, kind: 'opening' })}>Opening</Button>
                    </>
                  )}
                </div>
              </Card>
            ))}
            {!isLoading && !data?.length && <Empty>No items match.</Empty>}
          </>
        )}
      </div>
      {action && <StockModal {...action} onClose={() => setAction(null)} />}
    </div>
  );
}

function StockModal({ row, kind, onClose }: { row: Row; kind: 'correct' | 'wastage' | 'opening'; onClose: () => void }) {
  const qc = useQueryClient();
  const [value, setValue] = useState('');
  const [reason, setReason] = useState('');
  const { busy, error, run } = useBusy();
  const titles = { correct: 'Stock count', wastage: 'Record wastage', opening: 'Opening stock' };
  const labels = { correct: 'Counted quantity', wastage: 'Quantity wasted', opening: 'Opening quantity' };
  const needsReason = kind !== 'opening';
  const save = () => run(async () => {
    const body = kind === 'correct' ? { counted: Number(value), reason } : kind === 'wastage' ? { quantity: Number(value), reason } : { quantity: Number(value) };
    await api.post(`/inventory/${row.id}/${kind === 'correct' ? 'correct' : kind}`, body);
    qc.invalidateQueries({ queryKey: ['inventory'] });
    qc.invalidateQueries({ queryKey: ['stock'] });
    qc.invalidateQueries({ queryKey: ['products'] });
    onClose();
  });
  return (
    <Modal title={`${titles[kind]}: ${row.name}`} onClose={onClose} footer={<Button big className="w-full" disabled={busy || value === '' || (needsReason && !reason.trim())} onClick={save}>SAVE</Button>}>
      <ErrorBanner error={error} />
      <div className="text-[17px] font-bold text-muted">Now: {qty(row.physical_qty)} physical, {qty(row.reserved_qty)} reserved. This is recorded in the audit history.</div>
      <Field label={labels[kind]}><input className={inputClass} inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} /></Field>
      {needsReason && <Field label="Reason"><input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>}
    </Modal>
  );
}

function History({ row }: { row: Row | null }) {
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useQuery({
    queryKey: ['movements', row?.id, page],
    queryFn: () => api.page<Movement>('/inventory/movements', { inventory_id: row?.id, page }),
    placeholderData: keepPreviousData,
  });
  const delta = (v: string) => (Number(v) > 0 ? `+${qty(v)}` : qty(v));
  return (
    <>
      {row && <div className="text-lg font-black">{row.name}</div>}
      <ErrorBanner error={error} />
      {isLoading && <Loading />}
      {data?.data.map((m) => (
        <Card key={m.id}>
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="text-[18px] font-black">{TYPE_LABEL[m.type] ?? m.type}{!row && m.inventory?.product ? ` · ${withCategory(m.inventory.product.name, m.inventory.product.category?.name)}` : ''}</div>
              <div className="text-[15px] font-bold text-muted">{dateTime(m.created_at)} · {m.user?.name ?? 'System'}{m.reason ? ` · ${m.reason}` : ''}</div>
            </div>
            <div className="shrink-0 text-right text-[16px] font-extrabold">
              {Number(m.physical_delta) !== 0 && <div>Physical {delta(m.physical_delta)}</div>}
              {Number(m.reserved_delta) !== 0 && <div className="text-muted">Reserved {delta(m.reserved_delta)}</div>}
            </div>
          </div>
        </Card>
      ))}
      <Pager meta={data?.meta} onPage={setPage} />
    </>
  );
}
