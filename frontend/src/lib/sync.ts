import { api, ApiError } from './api';
import { localStore, type LocalOrder } from './offline';
import type { Order } from './types';

interface SyncResult {
  order: Order;
  duplicate: boolean;
  conflicts: { type: string; item?: string; short?: string; current_price?: string; message?: string }[];
}

function describe(c: SyncResult['conflicts'][number]): string {
  switch (c.type) {
    case 'stock_shortfall':
      return `${c.item}: short by ${Number(c.short)}. Fix it before billing.`;
    case 'price_changed':
      return `${c.item}: price is now ₹${c.current_price}. The offline price was kept.`;
    case 'product_inactive':
      return `${c.item}: no longer on sale.`;
    default:
      return c.message ?? 'Needs checking.';
  }
}

/**
 * Sends waiting orders one at a time, oldest first. The UUID doubles as the
 * idempotency key, so a reply lost mid-sync can never create a second order.
 */
export async function flushOrders(post: (o: LocalOrder) => Promise<SyncResult> = postOrder): Promise<{ sent: number; waiting: number; conflicts: number }> {
  const waiting = (await localStore.allOrders()).sort((a, b) => a.created_at.localeCompare(b.created_at));
  let sent = 0;
  let conflicts = 0;
  for (const order of waiting) {
    if (order.items.length === 0) {
      await localStore.deleteOrder(order.client_uuid);
      continue;
    }
    try {
      const result = await post(order);
      await localStore.deleteOrder(order.client_uuid);
      sent++;
      for (const c of result.conflicts) {
        conflicts++;
        await localStore.addNote({ at: new Date().toISOString(), order_number: result.order.order_number, message: describe(c) });
      }
    } catch (e) {
      const err = e as ApiError;
      if (err.isNetwork || err.status >= 500 || err.status === 401) break;
      await localStore.saveOrder({ ...order, last_error: err.message });
    }
  }
  return { sent, waiting: (await localStore.allOrders()).length, conflicts };
}

function postOrder(o: LocalOrder): Promise<SyncResult> {
  return api.post<SyncResult>(
    '/orders/sync',
    {
      client_uuid: o.client_uuid,
      order_number: o.order_number,
      created_at: o.created_at,
      customer_name: o.customer_name,
      customer_mobile: o.customer_mobile,
      items: o.items.map((i) => ({
        product_id: i.product_id,
        product_variant_id: i.product_variant_id,
        quantity: i.quantity,
        unit_price: i.unit_price,
      })),
    },
    `sync-${o.client_uuid}`,
  );
}
