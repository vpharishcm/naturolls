/** Whole rupees stay short, as in the design: ₹20, not ₹20.00. */
export function rupees(value: string | number | null | undefined): string {
  const n = Number(value ?? 0);
  const text = Number.isInteger(n) ? n.toLocaleString('en-IN') : n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return `₹${text}`;
}

export function qty(value: string | number | null | undefined): string {
  return String(Number(value ?? 0));
}

export function dateTime(value: string | null | undefined): string {
  if (!value) return '';
  return new Date(value).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' });
}

export function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function itemLabel(count: number): string {
  return count === 1 ? '1 item' : `${count} items`;
}
