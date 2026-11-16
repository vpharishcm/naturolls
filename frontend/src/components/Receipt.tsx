import { Fragment, useState } from 'react';
import { createPortal } from 'react-dom';
import { printInvoice, type PrintDriver, type PrintOutcome } from '../lib/printer';
import { numbersRow, plainNumber, receiptAmount, receiptCols } from '../lib/receipt';
import type { ReceiptData } from '../lib/types';
import { useApp } from '../stores/app';

export function ReceiptPaper({ r }: { r: ReceiptData }) {
  const row = 'flex justify-between gap-2';
  const wrapRow = 'flex flex-wrap justify-between gap-x-2';
  const [invoice, order] = numbersRow(r.invoice_number, r.order_number, receiptCols(r.width));
  const taxes = r.tax_breakup.filter((t) => Number(t.tax) > 0);
  const breakdown = Number(r.discount) > 0 || taxes.length > 0;
  return (
    <div style={{ width: `${r.width === 58 ? 50 : 74}mm`, padding: '0 1mm', fontFamily: 'monospace', fontSize: r.width === 58 ? 11 : 13, lineHeight: 1.2, color: '#000', fontWeight: 500 }}>
      {r.duplicate && <div style={{ textAlign: 'center', fontWeight: 800 }}>*** DUPLICATE ***</div>}
      {r.edited && <div style={{ textAlign: 'center', fontWeight: 800 }}>*** EDITED BILL ***</div>}
      <div style={{ textAlign: 'center', fontWeight: 800, fontSize: '1.4em' }}>{r.business.name}</div>
      {r.business.address && <div style={{ textAlign: 'center' }}>{r.business.address}</div>}
      {r.business.phone && <div style={{ textAlign: 'center' }}>{r.business.phone}</div>}
      {r.business.gstin && <div style={{ textAlign: 'center' }}>GSTIN: {r.business.gstin}</div>}
      <hr />
      <div className={wrapRow}><span>{invoice}</span><span>{order}</span></div>
      <div className={wrapRow}><span>{r.issued_at}</span>{r.customer_name && <span>{r.customer_name}</span>}</div>
      <hr />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr auto auto auto', columnGap: '1ch' }}>
        <span>Item</span>
        <span style={{ textAlign: 'right' }}>Price</span>
        <span style={{ textAlign: 'right' }}>Qty</span>
        <span style={{ textAlign: 'right' }}>Amt</span>
        {r.items.map((i, n) => (
          <Fragment key={n}>
            <span>{i.name}{i.category && <span style={{ display: 'block', paddingLeft: '2ch' }}>({i.category})</span>}</span>
            <span style={{ textAlign: 'right' }}>{plainNumber(i.unit_price)}</span>
            <span style={{ textAlign: 'right' }}>{plainNumber(i.quantity)}</span>
            <span style={{ textAlign: 'right' }}>{receiptAmount(i.line_total)}</span>
          </Fragment>
        ))}
      </div>
      <hr />
      {breakdown && <div className={row}><span>Subtotal</span><span>{receiptAmount(r.subtotal)}</span></div>}
      {Number(r.discount) > 0 && <div className={row}><span>Discount</span><span>-{receiptAmount(r.discount)}</span></div>}
      {taxes.map((t) => (
        <div key={t.rate}>
          <div className={row}><span>CGST @{Number(t.rate) / 2}%</span><span>{receiptAmount(t.cgst)}</span></div>
          <div className={row}><span>SGST @{Number(t.rate) / 2}%</span><span>{receiptAmount(t.sgst)}</span></div>
        </div>
      ))}
      <div className={row} style={{ fontWeight: 800, fontSize: '1.2em' }}><span>TOTAL</span><span>₹{receiptAmount(r.total)}</span></div>
      {r.dice_prize && <div>Dice: {r.dice_prize}</div>}
      {r.payments.map((p, n) => (
        <div key={n}>
          {Number(p.change_given) > 0
            ? <div className={wrapRow}><span>{p.method} given {receiptAmount(p.tendered ?? 0)}</span><span>Change {receiptAmount(p.change_given)}</span></div>
            : Number(p.amount) < 0
              ? <div className={row}><span>{p.method} returned</span><span>{receiptAmount(Math.abs(Number(p.amount)))}</span></div>
              : <div className={row}><span>{p.method}</span><span>{receiptAmount(p.amount)}</span></div>}
          {p.reference && <div>Ref: {p.reference}</div>}
        </div>
      ))}
      <div style={{ textAlign: 'center' }}>{r.footer}</div>
    </div>
  );
}

/** Print state for one invoice. A failure offers a retry; it never touches the bill itself. */
export function usePrint() {
  const driver = (useApp((s) => s.settings['printer.driver']) ?? 'browser') as PrintDriver;
  const [paper, setPaper] = useState<ReceiptData | null>(null);
  const [state, setState] = useState<'idle' | 'printing' | 'done' | 'failed'>('idle');
  const [outcome, setOutcome] = useState<PrintOutcome | null>(null);

  async function print(invoiceId: number) {
    setState('printing');
    try {
      const res = await printInvoice(invoiceId, driver, setPaper);
      setOutcome(res);
      setState(res.printed ? 'done' : 'failed');
      return res;
    } catch (e) {
      const res = { printed: false, error: (e as Error).message, duplicate: false };
      setOutcome(res);
      setState('failed');
      return res;
    }
  }

  const portal = paper
    ? createPortal(<ReceiptPaper r={paper} />, document.getElementById('print-root') ?? document.body.appendChild(Object.assign(document.createElement('div'), { id: 'print-root' })))
    : null;

  return { print, state, outcome, portal };
}
