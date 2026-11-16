/** The item's category after its name ("Strawberry | Water based"), so Strawberry in two categories can be told apart. */
export function CategoryTag({ name }: { name?: string | null }) {
  if (!name) return null;
  return <span className="font-bold text-muted"> | {name}</span>;
}
