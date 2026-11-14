import { qty } from '../lib/format';

/** What is in an order, so staff can tell apart orders with the same total. Same items at different prices are shown once. */
export function ItemChips({ items }: { items: { name: string; quantity: string | number }[] }) {
  const merged = new Map<string, number>();
  for (const i of items) merged.set(i.name, (merged.get(i.name) ?? 0) + Number(i.quantity));
  const lines = [...merged].filter(([, n]) => n > 0);
  if (!lines.length) return null;
  return (
    <ul aria-label="Items in this order" className="mt-2 flex flex-wrap gap-1.5">
      {lines.map(([name, n]) => (
        <li key={name} className="rounded-xl border border-line-soft bg-cream px-2.5 py-1 text-[16px] font-extrabold leading-tight">
          <span className="text-berry">{qty(n)} ×</span> {name}
        </li>
      ))}
    </ul>
  );
}
