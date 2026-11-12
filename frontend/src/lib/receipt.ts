/** Characters that fit across the printed receipt at its screen font size. */
export function receiptCols(width: number): number {
  return width === 58 ? 27 : 34;
}

/** 20.00 prints as 20. */
export function plainNumber(value: string | number): string {
  return String(Number(value));
}

/** 40.00 prints as 40.0 and 12.55 stays 12.55, saving a column without hiding paise. */
export function receiptAmount(value: string | number): string {
  const text = Number(value).toFixed(2);
  return text.endsWith('0') ? text.slice(0, -1) : text;
}

/** Invoice left, order right, shortening labels and the order number only as far as the paper needs. */
export function numbersRow(invoice: string, order: string, cols: number): [string, string] {
  const short = order.replaceAll('-', '');
  const options: [string, string][] = [
    [`Invoice: ${invoice}`, `Order: ${order}`],
    [`Invoice: ${invoice}`, `Order: ${short}`],
    [invoice, `Order: ${order}`],
    [invoice, `Order: ${short}`],
  ];
  return options.find(([l, r]) => l.length + 1 + r.length <= cols) ?? [invoice, `#${short}`];
}
