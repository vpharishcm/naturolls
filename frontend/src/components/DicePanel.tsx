import { rupees } from '../lib/format';
import { CategoryTag } from './CategoryTag';
import type { DiceChoice, DiceForBill } from '../lib/types';

/** Dice faces drawn with their pips, so staff match what is on the real dice at a glance. */
const PIPS = ['⚀', '⚁', '⚂', '⚃', '⚄', '⚅'];

export interface DiceState { rolls: number[]; choice: DiceChoice | null }

/** The rolls to send with the bill, once the customer's roll is settled. Null while nothing is final. */
export function diceToSend(dice: DiceForBill | null | undefined, s: DiceState) {
  const last = s.rolls[s.rolls.length - 1];
  const face = last ? dice?.faces[last - 1] : undefined;
  if (!dice || !face || face.type === 'reroll') return undefined;
  if (face.type === 'free_item') {
    if (!s.choice) return undefined;
    return { rolls: s.rolls, product_id: s.choice.product_id, product_variant_id: s.choice.product_variant_id ?? undefined };
  }
  return { rolls: s.rolls };
}

/** True when a roll has started but is not finished: a "roll again", or a free item not yet picked. */
export function dicePending(dice: DiceForBill | null | undefined, s: DiceState) {
  return s.rolls.length > 0 && !diceToSend(dice, s);
}

/**
 * The customer rolls a real dice; staff tap the number that came up. A "roll again" face asks
 * for another tap. A free-item face asks which item, picking it straight away when there is only one.
 */
export function DicePanel({ dice, subtotal, state, onChange }: { dice: DiceForBill; subtotal: number; state: DiceState; onChange: (s: DiceState) => void }) {
  if (!dice.eligible) {
    const short = Number(dice.min_bill) - subtotal;
    return (
      <div className="rounded-[18px] border-2 border-dashed border-line px-4 py-3 text-[16px] font-extrabold text-muted">
        🎲 {dice.name}: bills of {rupees(dice.min_bill)} or more roll the dice.{short > 0 && <> Add {rupees(short)} more to qualify.</>}
      </div>
    );
  }

  const last = state.rolls[state.rolls.length - 1];
  const face = last ? dice.faces[last - 1] : undefined;
  const waiting = !face || face.type === 'reroll';
  const tap = (n: number) => {
    const next = [...state.rolls, n];
    const f = dice.faces[n - 1];
    onChange({ rolls: next, choice: f.type === 'free_item' && f.choices.length === 1 ? f.choices[0] : null });
  };
  const reset = () => onChange({ rolls: [], choice: null });

  return (
    <div className="rounded-[18px] border-[3px] border-berry bg-berry-soft p-3">
      <div className="flex items-center justify-between gap-2">
        <div className="text-[20px] font-black">🎲 {dice.name}</div>
        {state.rolls.length > 0 && <button type="button" onClick={reset} className="min-h-[44px] rounded-xl px-2 text-base font-black underline">Start again</button>}
      </div>

      {state.rolls.length > 0 && (
        <div className="mt-1 text-[16px] font-extrabold text-muted">Rolled {state.rolls.join(', then ')}</div>
      )}

      {waiting && (
        <>
          <div className="mb-2 mt-1 text-[17px] font-extrabold">
            {face?.type === 'reroll' ? `${face.label || 'Roll again'}! Ask the customer to roll once more, then tap the number.` : 'Ask the customer to roll the dice, then tap the number that came up.'}
          </div>
          <div className="grid grid-cols-3 gap-2">
            {dice.faces.map((f) => (
              <button key={f.number} type="button" onClick={() => tap(f.number)} aria-label={`Rolled ${f.number}: ${f.label}`}
                className="flex min-h-[88px] flex-col items-center justify-center rounded-2xl border-2 border-line bg-white px-1 py-2 text-center">
                <span aria-hidden="true" className="text-[40px] leading-none">{PIPS[f.number - 1]}</span>
                <span className="mt-1 text-[14px] font-black leading-tight">{f.label || f.type}</span>
              </button>
            ))}
          </div>
        </>
      )}

      {face && face.type !== 'reroll' && (
        <div className="mt-2 rounded-2xl bg-white p-3">
          <div className="flex items-center gap-3">
            <span aria-hidden="true" className="text-[44px] leading-none">{PIPS[last - 1]}</span>
            <div className="text-[20px] font-black leading-tight">
              {face.type === 'discount' && <span className="text-mint">{face.label || `${rupees(face.amount ?? 0)} off`} · −{rupees(face.amount ?? 0)}</span>}
              {face.type === 'free_item' && <span className="text-mint">{face.label || 'Free item'}</span>}
              {face.type === 'none' && <span>{face.label || 'No prize this time'}</span>}
            </div>
          </div>
          {face.type === 'free_item' && (
            <div className="mt-2">
              <div className="mb-1.5 text-[16px] font-extrabold text-muted">{face.choices.length ? 'Which one does the customer want?' : 'None of the free items are on sale right now.'}</div>
              <div className="flex flex-wrap gap-2">
                {face.choices.map((c) => {
                  const on = state.choice?.product_id === c.product_id && state.choice?.product_variant_id === c.product_variant_id;
                  return (
                    <button key={`${c.product_id}-${c.product_variant_id ?? 0}`} type="button" aria-pressed={on} onClick={() => onChange({ ...state, choice: c })}
                      className={`min-h-[52px] rounded-2xl px-3 text-base font-black ${on ? 'border-[3px] border-mint bg-mint-soft' : 'border-2 border-line bg-white'}`}>
                      {c.name}<CategoryTag name={c.category_name} />
                    </button>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
