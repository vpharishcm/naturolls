/** A small label after an item's name saying which category it is from, so Strawberry in two categories can be told apart. */
export function CategoryTag({ name }: { name?: string | null }) {
  if (!name) return null;
  return <span className="ml-1.5 inline-block rounded-lg bg-sand px-1.5 align-middle text-[14px] font-extrabold leading-snug text-muted">{name}</span>;
}
