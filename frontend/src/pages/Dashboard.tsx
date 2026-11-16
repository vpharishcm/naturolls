import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Button, Card, ErrorBanner, Loading, PageHeader, Stat } from '../components/ui';
import { api } from '../lib/api';
import { qty, rupees, withCategory } from '../lib/format';
import { CategoryTag } from '../components/CategoryTag';
import { useApp } from '../stores/app';

interface Dash {
  total_sales: string;
  total_orders: number;
  completed_bills: number;
  unbilled_count: number;
  unbilled_value: string;
  low_stock: { name: string; category: string | null; available: string }[];
  out_of_stock: { name: string; category: string | null }[];
  payments: Record<string, { received: string; refunded: string; net: string; count: number }>;
  tax_collected: string;
  backup_failed: boolean;
}

export default function Dashboard() {
  const { user, logout, online } = useApp();
  const { data, isLoading, error } = useQuery({ queryKey: ['dashboard'], queryFn: () => api.get<Dash>('/dashboard'), refetchInterval: 15000, enabled: online });

  return (
    <div className="flex flex-col pb-6">
      <PageHeader eyebrow={`Hello, ${user?.name}`} title="Today" action={<Button tone="plain" onClick={() => logout()}>Sign out</Button>} />
      <div className="flex flex-col gap-3 px-4">
        <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
          <Link to="/order/new" className="flex min-h-[72px] items-center justify-center rounded-[20px] bg-berry px-3 text-center text-[20px] font-black text-white">NEW ORDER</Link>
          <Link to="/to-bill" className="flex min-h-[72px] items-center justify-center rounded-[20px] border-2 border-ink bg-white px-3 text-center text-[19px] font-black">Orders to Be Billed</Link>
          <Link to="/orders" className="flex min-h-[72px] items-center justify-center rounded-[20px] border-2 border-line bg-white px-3 text-center text-[19px] font-black">All Orders</Link>
          <Link to="/inventory" className="flex min-h-[72px] items-center justify-center rounded-[20px] border-2 border-line bg-white px-3 text-center text-[19px] font-black">Inventory</Link>
        </div>

        {!online && <Card>Today's figures need a connection. You can still take orders.</Card>}
        {isLoading && <Loading />}
        <ErrorBanner error={error} />
        {data && (
          <>
            {data.backup_failed && <div role="alert" className="rounded-2xl bg-rust-soft px-4 py-3 text-[17px] font-extrabold text-rust">The last database backup failed. Check Settings › Backups.</div>}
            <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
              <Stat label="Today's sales" value={rupees(data.total_sales)} tone="mint" />
              <Stat label="Today's orders" value={data.total_orders} />
              <Stat label="Completed bills" value={data.completed_bills} />
              <Stat label="Unbilled orders" value={<>{data.unbilled_count} <span className="text-lg text-muted">· {rupees(data.unbilled_value)}</span></>} tone="berry" />
            </div>

            <Card>
              <h2 className="mb-2 text-[20px] font-black">Daily sales summary</h2>
              {Object.entries(data.payments).map(([method, p]) => (
                <div key={method} className="flex justify-between py-1 text-[17px] font-extrabold">
                  <span className="uppercase">{method} <span className="font-bold normal-case text-muted">· {p.count} {p.count === 1 ? 'payment' : 'payments'}</span></span>
                  <span>{rupees(p.net)}</span>
                </div>
              ))}
              <div className="mt-1 flex justify-between border-t-2 border-line-soft pt-2 text-[17px] font-extrabold text-muted"><span>Tax included</span><span>{rupees(data.tax_collected)}</span></div>
            </Card>

            <Card>
              <h2 className="mb-2 text-[20px] font-black">Stock alerts</h2>
              {data.out_of_stock.length === 0 && data.low_stock.length === 0 && <div className="text-[17px] font-bold text-muted">All items are above their minimum.</div>}
              {data.out_of_stock.map((s) => <div key={withCategory(s.name, s.category)} className="flex justify-between py-1 text-[17px] font-extrabold text-rust"><span>{s.name}<CategoryTag name={s.category} /></span><span>Out of stock</span></div>)}
              {data.low_stock.map((s) => <div key={withCategory(s.name, s.category)} className="flex justify-between py-1 text-[17px] font-extrabold text-amber-ink"><span>{s.name}<CategoryTag name={s.category} /></span><span>{qty(s.available)} left</span></div>)}
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
