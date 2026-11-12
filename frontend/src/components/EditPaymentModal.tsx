import { useState } from 'react';
import { Button, Chip, ErrorBanner, Field, inputClass, Modal, useBusy } from './ui';
import { api } from '../lib/api';
import { dateTime, rupees } from '../lib/format';
import type { Invoice } from '../lib/types';
import { uuid } from '../lib/uuid';

interface PayLine { key: string; method: 'cash' | 'upi' | 'card'; amount: string; reference: string }
const PAY_METHODS = [['cash', 'Cash'], ['upi', 'UPI'], ['card', 'Card']] as const;

/** Fixes how a bill was paid when the wrong mode was tapped. The amount received stays the same. */
export function EditPaymentModal({ invoice, onClose, onDone }: { invoice: Invoice; onClose: () => void; onDone: (i: Invoice) => void }) {
  const received = (invoice.payments ?? []).filter((p) => p.type === 'payment' && Number(p.amount) > 0);
  const total = received.reduce((s, p) => s + Number(p.amount), 0);
  const [lines, setLines] = useState<PayLine[]>(() => received.map((p) => ({ key: String(p.id), method: p.method as PayLine['method'], amount: String(Number(p.amount)), reference: p.reference ?? '' })));
  const [reason, setReason] = useState('');
  const [saveKey] = useState(() => uuid());
  const { busy, error, run } = useBusy();
  const sum = lines.reduce((s, l) => s + (Number(l.amount) || 0), 0);
  const balanced = Math.abs(sum - total) < 0.005;
  const set = (key: string, patch: Partial<PayLine>) => setLines((v) => v.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const save = () => run(async () => {
    const updated = await api.post<Invoice>(`/invoices/${invoice.id}/payments/edit`, {
      reason,
      payments: lines.filter((l) => Number(l.amount) > 0).map((l) => ({ method: l.method, amount: Number(l.amount), reference: l.method !== 'cash' ? l.reference || undefined : undefined })),
    }, saveKey);
    onDone(updated);
  });

  return (
    <Modal title="Change payment mode" onClose={onClose} footer={
      <Button big className="w-full" disabled={busy || !reason.trim() || !balanced} onClick={save}>SAVE PAYMENT</Button>
    }>
      <ErrorBanner error={error} />
      <div className="text-[17px] font-bold text-muted">
        Received {rupees(total)} for {invoice.invoice_number}. Choose how it was really paid.
        {invoice.payment_edit_until && <> You can change this until {dateTime(invoice.payment_edit_until)}.</>}
      </div>
      {lines.map((l) => (
        <div key={l.key} className="flex flex-col gap-2 rounded-2xl bg-white p-3">
          <div className="flex flex-wrap gap-2">
            {PAY_METHODS.map(([id, label]) => <Chip key={id} active={l.method === id} onClick={() => set(l.key, { method: id })}>{label}</Chip>)}
            {lines.length > 1 && <Button tone="plain" onClick={() => setLines((v) => v.filter((x) => x.key !== l.key))}>Remove</Button>}
          </div>
          {lines.length > 1 && <Field label="Amount"><input className={inputClass} inputMode="decimal" value={l.amount} onChange={(e) => set(l.key, { amount: e.target.value })} /></Field>}
          {l.method !== 'cash' && <Field label="Reference (optional)"><input className={inputClass} value={l.reference} onChange={(e) => set(l.key, { reference: e.target.value })} /></Field>}
        </div>
      ))}
      {lines.length < 3 && <Button tone="plain" onClick={() => setLines((v) => [...v, { key: uuid(), method: 'upi', amount: String(Math.max(0, Math.round((total - sum) * 100) / 100)), reference: '' }])}>+ Split between modes</Button>}
      {!balanced && <div role="alert" className="text-[17px] font-extrabold text-rust">The amounts add up to {rupees(sum)}. They must add up to {rupees(total)}.</div>}
      <Field label="Reason"><input className={inputClass} value={reason} placeholder="For example: customer paid by UPI, not cash" onChange={(e) => setReason(e.target.value)} /></Field>
    </Modal>
  );
}
