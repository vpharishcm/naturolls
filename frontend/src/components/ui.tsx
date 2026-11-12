import { useState, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ApiError } from '../lib/api';
import type { PageMeta } from '../lib/types';

type Tone = 'berry' | 'mint' | 'plain' | 'danger' | 'sand';

const tones: Record<Tone, string> = {
  berry: 'bg-berry text-white disabled:bg-line-strong',
  mint: 'bg-mint text-white disabled:bg-line-strong',
  plain: 'bg-white text-ink border-2 border-line',
  danger: 'bg-white text-rust border-2 border-rust-line',
  sand: 'bg-sand text-ink',
};

export function Button({ tone = 'berry', big, className = '', ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { tone?: Tone; big?: boolean }) {
  return (
    <button
      type="button"
      {...rest}
      className={`${big ? 'min-h-[68px] rounded-[20px] px-6 text-[21px]' : 'min-h-[52px] rounded-2xl px-4 text-lg'} font-black ${tones[tone]} ${className}`}
    />
  );
}

export function BackLink({ to, label }: { to: string; label: string }) {
  return (
    <Link to={to} className="inline-flex min-h-[52px] items-center self-start rounded-[26px] border-2 border-line bg-white px-4 text-lg font-extrabold">
      ← {label}
    </Link>
  );
}

export function PageHeader({ eyebrow, title, action }: { eyebrow?: string; title: string; action?: ReactNode }) {
  return (
    <header className="flex flex-wrap items-center justify-between gap-3 px-4 pb-2.5 pt-[18px]">
      <div className="min-w-0">
        {eyebrow && <div className="text-[15px] font-extrabold text-muted-soft">{eyebrow}</div>}
        <h1 className="mt-0.5 text-[28px] font-black leading-tight">{title}</h1>
      </div>
      {action}
    </header>
  );
}

export function Chip({ active, tone = 'berry', className = '', ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { active?: boolean; tone?: 'berry' | 'mint' }) {
  const on = tone === 'berry' ? 'border-[3px] border-berry bg-berry-soft' : 'border-[3px] border-mint bg-mint-soft';
  return (
    <button
      type="button"
      aria-pressed={active}
      {...rest}
      className={`min-h-[52px] shrink-0 rounded-[18px] px-3.5 py-1.5 text-lg font-black leading-tight ${active ? on : 'border-2 border-line bg-white'} ${className}`}
    />
  );
}

export function Card({ className = '', children }: { className?: string; children: ReactNode }) {
  return <div className={`rounded-[20px] bg-white p-3.5 shadow-tile ${className}`}>{children}</div>;
}

export function Field({ label, error, hint, children }: { label: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-lg font-black">{label}</span>
      {children}
      {hint && !error && <span className="text-[15px] font-bold text-muted">{hint}</span>}
      {error && <span className="text-[15px] font-extrabold text-rust">{error}</span>}
    </label>
  );
}

export const inputClass = 'min-h-[56px] w-full rounded-2xl border-2 border-line-strong bg-white px-4 text-lg font-extrabold text-ink box-border';

export function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: 'berry' | 'mint' | 'rust' }) {
  const color = tone === 'berry' ? 'text-berry' : tone === 'mint' ? 'text-mint' : tone === 'rust' ? 'text-rust' : '';
  return (
    <Card>
      <div className="text-[15px] font-extrabold text-muted">{label}</div>
      <div className={`mt-0.5 text-[28px] font-black leading-tight ${color}`}>{value}</div>
    </Card>
  );
}

export function Badge({ tone, children }: { tone: 'ok' | 'low' | 'out' | 'info' | 'grey'; children: ReactNode }) {
  const map = {
    ok: 'bg-mint-soft text-mint-ink',
    low: 'bg-amber-soft text-amber-ink',
    out: 'bg-rust-soft text-rust',
    info: 'bg-berry-soft text-berry',
    grey: 'bg-sand text-muted',
  };
  return <span className={`inline-block rounded-full px-2.5 py-0.5 text-sm font-extrabold ${map[tone]}`}>{children}</span>;
}

export function Modal({ title, onClose, children, footer }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 sm:items-center" role="dialog" aria-modal="true" aria-label={title}>
      <div className="flex max-h-[92vh] w-full max-w-lg flex-col rounded-t-[24px] bg-cream sm:rounded-[24px]">
        <div className="flex items-center justify-between gap-3 px-4 pb-2 pt-4">
          <h2 className="text-[24px] font-black leading-tight">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close" className="min-h-[52px] min-w-[52px] rounded-2xl border-2 border-line bg-white text-2xl font-black">×</button>
        </div>
        <div className="flex flex-1 flex-col gap-3 overflow-y-auto px-4 pb-4">{children}</div>
        {footer && <div className="border-t-2 border-line-soft bg-white px-4 py-3">{footer}</div>}
      </div>
    </div>
  );
}

/** Asks for the owner's PIN when the signed-in person cannot approve an action themselves. */
export function PinField({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <Field label="Owner PIN" hint="Needed unless the owner is signed in.">
      <input className={inputClass} type="password" inputMode="numeric" autoComplete="off" value={value} onChange={(e) => onChange(e.target.value)} />
    </Field>
  );
}

export function ErrorBanner({ error }: { error: unknown }) {
  if (!error) return null;
  const e = error as ApiError;
  return <div role="alert" className="rounded-2xl bg-rust-soft px-4 py-3 text-[17px] font-extrabold text-rust">{e.message ?? 'Something went wrong.'}</div>;
}

export function Empty({ children }: { children: ReactNode }) {
  return <div className="px-2 py-8 text-center text-lg font-bold text-muted">{children}</div>;
}

export function Loading() {
  return <div className="px-2 py-8 text-center text-lg font-bold text-muted" role="status">Loading…</div>;
}

export function Pager({ meta, onPage }: { meta?: PageMeta; onPage: (p: number) => void }) {
  if (!meta || meta.last_page <= 1) return null;
  return (
    <div className="flex items-center justify-between gap-2 py-2">
      <Button tone="plain" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>← Newer</Button>
      <span className="text-base font-extrabold text-muted">Page {meta.current_page} of {meta.last_page}</span>
      <Button tone="plain" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Older →</Button>
    </div>
  );
}

export function useBusy() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    setBusy(true);
    setError(null);
    try {
      return await fn();
    } catch (e) {
      setError(e);
      return undefined;
    } finally {
      setBusy(false);
    }
  }
  return { busy, error, run, setError };
}
