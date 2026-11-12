import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

const NOT_TYPED = new Set(['checkbox', 'radio', 'file', 'range', 'button', 'submit', 'reset', 'color', 'hidden']);

function isTyping(el: Element | null): el is HTMLInputElement | HTMLTextAreaElement {
  if (el instanceof HTMLTextAreaElement) return true;
  return el instanceof HTMLInputElement && !NOT_TYPED.has(el.type);
}

function closeKeyboard() {
  if (isTyping(document.activeElement)) document.activeElement.blur();
}

/**
 * An installed app has no browser controls, and many tablets offer no obvious way to dismiss
 * the keyboard. This closes it with a Done button above the keyboard, the keyboard's own
 * Enter key, or a tap anywhere else, on every screen.
 */
export function KeyboardDone() {
  const [typing, setTyping] = useState(false);
  const [bottom, setBottom] = useState(0);
  const [touch] = useState(() => window.matchMedia('(pointer: coarse)').matches);

  useEffect(() => {
    const sync = () => setTyping(isTyping(document.activeElement));
    const onFocusIn = (e: FocusEvent) => {
      sync();
      const el = e.target;
      // Enter inside a form still submits it, as it does on the sign-in screen.
      if (isTyping(el as Element) && !(el as HTMLInputElement).form && el instanceof HTMLInputElement) el.enterKeyHint = 'done';
    };
    const onFocusOut = () => setTimeout(sync, 0);
    const onPointerDown = (e: PointerEvent) => {
      const target = e.target as Element;
      if (!target.closest?.('input, textarea, select, label')) closeKeyboard();
    };
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Enter' && e.target instanceof HTMLInputElement && isTyping(e.target) && !e.target.form) e.target.blur();
    };
    const view = window.visualViewport;
    const place = () => setBottom(view ? Math.max(0, window.innerHeight - view.height - view.offsetTop) : 0);

    document.addEventListener('focusin', onFocusIn);
    document.addEventListener('focusout', onFocusOut);
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    view?.addEventListener('resize', place);
    view?.addEventListener('scroll', place);
    return () => {
      document.removeEventListener('focusin', onFocusIn);
      document.removeEventListener('focusout', onFocusOut);
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
      view?.removeEventListener('resize', place);
      view?.removeEventListener('scroll', place);
    };
  }, []);

  if (!typing || !touch) return null;
  return createPortal(
    <button type="button" onClick={closeKeyboard} style={{ bottom: bottom + 8 }} className="no-print fixed right-3 z-[70] min-h-[46px] rounded-full bg-ink px-5 text-[17px] font-black text-white shadow-lg">
      Done ⌄
    </button>,
    document.body,
  );
}
