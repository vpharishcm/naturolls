import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../lib/api';
import { localStore, nextOfflineNumber, type LocalOrder } from '../lib/offline';
import type { Order, Product, Variant } from '../lib/types';
import { uuid } from '../lib/uuid';
import { useApp } from '../stores/app';

export interface DraftLine {
  key: string;
  product: Pick<Product, 'id' | 'name'>;
  variant: Variant | null;
  unitPrice: number;
}

const DOUBLE_TAP_MS = 180;

/**
 * Screen state for an order being built. Taps change a target quantity straight away;
 * a single worker then brings the saved order into line one call at a time. Sending
 * target quantities (not "+1") means a retried call cannot add twice. With no connection
 * the order is kept on the device and synced later.
 */
export function useOrderDraft(initial: { orderId?: number; localId?: string }) {
  const notify = useApp((s) => s.notify);
  const [order, setOrder] = useState<Order | null>(null);
  const [localOrder, setLocalOrder] = useState<LocalOrder | null>(null);
  const [targets, setTargets] = useState<Record<string, number>>({});
  const [saving, setSaving] = useState(false);
  const [waitingForNetwork, setWaitingForNetwork] = useState(false);
  const [loading, setLoading] = useState(Boolean(initial.orderId || initial.localId));

  const lines = useRef(new Map<string, DraftLine>());
  const targetsRef = useRef(targets);
  const orderRef = useRef(order);
  const localRef = useRef(localOrder);
  const running = useRef(false);
  const lastTap = useRef(new Map<string, number>());
  const createKey = useRef(uuid());
  const addKeys = useRef(new Map<string, string>());

  targetsRef.current = targets;
  orderRef.current = order;
  localRef.current = localOrder;

  const adoptOrder = useCallback((o: Order) => {
    setOrder(o);
    orderRef.current = o;
  }, []);

  useEffect(() => {
    let live = true;
    if (initial.orderId) {
      api.get<Order>(`/orders/${initial.orderId}`).then((o) => {
        if (!live) return;
        adoptOrder(o);
        const t: Record<string, number> = {};
        o.items?.forEach((i) => { t[i.stock_key] = (t[i.stock_key] ?? 0) + Number(i.quantity); });
        setTargets(t);
      }).catch((e) => notify(e.message, 'error')).finally(() => live && setLoading(false));
    } else if (initial.localId) {
      localStore.getOrder(initial.localId).then((o) => {
        if (!live || !o) return;
        setLocalOrder(o);
        const t: Record<string, number> = {};
        o.items.forEach((i) => { t[i.stock_key] = i.quantity; });
        setTargets(t);
      }).finally(() => live && setLoading(false));
    }
    return () => { live = false; };
  }, [initial.orderId, initial.localId, adoptOrder, notify]);

  async function saveLocally(): Promise<void> {
    const { terminal } = useApp.getState();
    let current = localRef.current;
    if (!current) {
      current = {
        client_uuid: uuid(),
        order_number: await nextOfflineNumber(terminal?.code ?? 'TNEW'),
        created_at: new Date().toISOString(),
        customer_name: null,
        customer_mobile: null,
        items: [],
      };
    }
    const items = Object.entries(targetsRef.current).filter(([, q]) => q > 0).map(([key, quantity]) => {
      const previous = current!.items.find((i) => i.stock_key === key);
      const line = lines.current.get(key);
      return {
        product_id: previous?.product_id ?? line!.product.id,
        product_variant_id: previous?.product_variant_id ?? line?.variant?.id ?? null,
        stock_key: key,
        name: previous?.name ?? `${line!.product.name}${line?.variant ? ` · ${line.variant.name}` : ''}`,
        unit_price: previous?.unit_price ?? String(line!.unitPrice),
        quantity,
      };
    });
    const next = { ...current, items };
    await localStore.saveOrder(next);
    localRef.current = next;
    setLocalOrder(next);
    useApp.getState().setWaitingOrders((await localStore.allOrders()).length);
  }

  const drain = useCallback(async () => {
    if (running.current) return;
    running.current = true;
    setSaving(true);
    try {
      for (;;) {
        // An order that started on the device stays there until the background sync sends it.
        if (localRef.current) {
          await saveLocally();
          break;
        }
        const current = orderRef.current;
        const savedQty = (key: string) => (current?.items ?? []).filter((i) => i.stock_key === key).reduce((s, i) => s + Number(i.quantity), 0);
        const next = Object.entries(targetsRef.current).find(([key, q]) => q !== savedQty(key));
        if (!next) break;
        const [key, target] = next;
        try {
          let fresh = current;
          if (!fresh) {
            fresh = await api.post<Order>('/orders', {}, createKey.current);
            adoptOrder(fresh);
          }
          const existing = (fresh.items ?? []).filter((i) => i.stock_key === key);
          const saved = existing.reduce((s, i) => s + Number(i.quantity), 0);
          if (target > saved && existing.length === 0) {
            const line = lines.current.get(key)!;
            let addKey = addKeys.current.get(key);
            if (!addKey) {
              addKey = uuid();
              addKeys.current.set(key, addKey);
            }
            const updated = await api.post<Order>(`/orders/${fresh.id}/items`, { product_id: line.product.id, product_variant_id: line.variant?.id ?? null, quantity: target - saved }, addKey);
            addKeys.current.delete(key);
            adoptOrder(updated);
          } else if (existing.length > 0) {
            // Lines at an old price are reduced last, so the newest line absorbs changes first.
            const line = existing[existing.length - 1];
            const others = saved - Number(line.quantity);
            const newQty = Math.max(0, target - others);
            const updated = await api.patch<Order>(`/orders/${fresh.id}/items/${line.id}`, { quantity: newQty });
            adoptOrder(updated);
          } else {
            setTargets((t) => ({ ...t, [key]: saved }));
            targetsRef.current = { ...targetsRef.current, [key]: saved };
          }
          setWaitingForNetwork(false);
        } catch (e) {
          const err = e as ApiError;
          if (err.isNetwork) {
            if (!orderRef.current) {
              // Nothing reached the server yet, so this becomes an offline order.
              await saveLocally();
              setWaitingForNetwork(false);
            } else {
              setWaitingForNetwork(true);
            }
            break;
          }
          notify(err.message, 'error');
          const key2 = key;
          const reverted = orderRef.current ? (orderRef.current.items ?? []).filter((i) => i.stock_key === key2).reduce((s, i) => s + Number(i.quantity), 0) : 0;
          addKeys.current.delete(key);
          const nextTargets = { ...targetsRef.current, [key]: reverted };
          targetsRef.current = nextTargets;
          setTargets(nextTargets);
        }
      }
    } finally {
      running.current = false;
      setSaving(false);
    }
  }, [adoptOrder, notify]); // eslint-disable-line react-hooks/exhaustive-deps

  // Retry unsaved changes as soon as the connection is back.
  const online = useApp((s) => s.online);
  useEffect(() => {
    if (online && waitingForNetwork) drain();
  }, [online, waitingForNetwork, drain]);

  const change = useCallback((line: DraftLine, delta: number) => {
    const stamp = Date.now();
    const tapKey = `${line.key}:${delta}`;
    if (stamp - (lastTap.current.get(tapKey) ?? 0) < DOUBLE_TAP_MS) return;
    lastTap.current.set(tapKey, stamp);
    lines.current.set(line.key, line);
    const value = Math.max(0, (targetsRef.current[line.key] ?? 0) + delta);
    const nextTargets = { ...targetsRef.current, [line.key]: value };
    targetsRef.current = nextTargets;
    setTargets(nextTargets);
    drain();
  }, [drain]);

  const clearAll = useCallback(() => {
    const nextTargets = Object.fromEntries(Object.keys(targetsRef.current).map((k) => [k, 0]));
    targetsRef.current = nextTargets;
    setTargets(nextTargets);
    drain();
  }, [drain]);

  const settled = !saving && !waitingForNetwork;
  return { order, localOrder, targets, change, clearAll, saving, waitingForNetwork, settled, loading, adoptOrder };
}
