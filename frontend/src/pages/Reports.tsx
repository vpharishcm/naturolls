import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, Card, Chip, Empty, ErrorBanner, Field, inputClass, Loading, PageHeader, useBusy } from '../components/ui';
import { api } from '../lib/api';
import { dateTime, qty, rupees, today } from '../lib/format';

interface Report {
  title: string;
  from: string;
  to: string;
  columns: { key: string; label: string; type: string }[];
  rows: Record<string, string | number | null>[];
  summary: { label: string; value: number | string; type: string }[];
}

function cell(value: string | number | null | undefined, type: string): string {
  if (value === null || value === undefined) return '';
  if (type === 'money') return rupees(Number(value).toFixed(2));
  if (type === 'qty') return qty(value);
  if (type === 'datetime') return dateTime(String(value).replace(' ', 'T'));
  return String(value);
}

function startOf(period: 'week' | 'month'): string {
  const d = new Date();
  if (period === 'week') d.setDate(d.getDate() - 6);
  else d.setDate(1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default function Reports() {
  const catalog = useQuery({ queryKey: ['report-catalog'], queryFn: () => api.get<Record<string, Record<string, string>>>('/reports') });
  const [type, setType] = useState('sales-period');
  const [group, setGroup] = useState('day');
  const [from, setFrom] = useState(today());
  const [to, setTo] = useState(today());
  const params = { from, to, group: type === 'sales-period' ? group : undefined };
  const { data, isLoading, error } = useQuery({ queryKey: ['report', type, params], queryFn: () => api.get<Report>(`/reports/${type}`, params) });
  const exporting = useBusy();

  const exportFile = (format: string) => exporting.run(() => api.download(`/reports/${type}/export/${format}?from=${from}&to=${to}${group ? `&group=${group}` : ''}`, `${type}_${from}_${to}.${format}`));

  return (
    <div className="flex flex-col pb-6">
      <div className="no-print"><PageHeader title="Reports" /></div>
      <div className="flex flex-col gap-2.5 px-4">
        <div className="no-print flex flex-col gap-2.5">
          <Field label="Report">
            <select className={inputClass} value={type} onChange={(e) => setType(e.target.value)}>
              {Object.entries(catalog.data ?? {}).map(([groupName, items]) => (
                <optgroup key={groupName} label={groupName}>
                  {Object.entries(items).map(([id, label]) => <option key={id} value={id}>{label}</option>)}
                </optgroup>
              ))}
            </select>
          </Field>
          <div className="scroll-x flex gap-2">
            <Chip active={from === today() && to === today()} onClick={() => { setFrom(today()); setTo(today()); setGroup('day'); }}>Today</Chip>
            <Chip active={from === startOf('week') && to === today()} onClick={() => { setFrom(startOf('week')); setTo(today()); }}>7 days</Chip>
            <Chip active={from === startOf('month') && to === today()} onClick={() => { setFrom(startOf('month')); setTo(today()); }}>This month</Chip>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Field label="From"><input type="date" className={inputClass} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
            <Field label="To"><input type="date" className={inputClass} value={to} onChange={(e) => setTo(e.target.value)} /></Field>
          </div>
          {type === 'sales-period' && (
            <div className="flex gap-2">
              {['day', 'week', 'month'].map((g) => <Chip key={g} active={group === g} onClick={() => setGroup(g)}>By {g}</Chip>)}
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            <Button tone="plain" disabled={exporting.busy} onClick={() => exportFile('xlsx')}>Excel</Button>
            <Button tone="plain" disabled={exporting.busy} onClick={() => exportFile('csv')}>CSV</Button>
            <Button tone="plain" disabled={exporting.busy} onClick={() => exportFile('pdf')}>PDF</Button>
            <Button tone="plain" onClick={() => window.print()}>Print</Button>
          </div>
        </div>
        <ErrorBanner error={error ?? exporting.error} />
        {isLoading && <Loading />}
        {data && (
          <div className="report-printable flex flex-col gap-2.5">
            <h2 className="text-[22px] font-black leading-tight">{data.title} <span className="block text-[15px] font-bold text-muted">{data.from} to {data.to}</span></h2>
            {data.summary.length > 0 && (
              <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                {data.summary.map((s) => (
                  <Card key={s.label}>
                    <div className="text-[15px] font-extrabold text-muted">{s.label}</div>
                    <div className="text-[24px] font-black">{cell(s.value, s.type)}</div>
                  </Card>
                ))}
              </div>
            )}
            <div className="overflow-x-auto rounded-[18px] bg-white">
              <table className="w-full min-w-max border-collapse text-left text-[16px]">
                <thead>
                  <tr className="border-b-2 border-line">
                    {data.columns.map((c) => <th key={c.key} scope="col" className={`whitespace-nowrap px-3 py-2.5 font-black ${['money', 'qty', 'number'].includes(c.type) ? 'text-right' : ''}`}>{c.label}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((r, i) => (
                    <tr key={i} className="border-b border-line-soft">
                      {data.columns.map((c) => <td key={c.key} className={`px-3 py-2 font-bold ${['money', 'qty', 'number'].includes(c.type) ? 'text-right tabular-nums' : ''}`}>{cell(r[c.key], c.type)}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
              {data.rows.length === 0 && <Empty>No data for this period.</Empty>}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
