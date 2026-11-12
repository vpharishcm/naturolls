import { beforeEach, describe, expect, it } from 'vitest';
import { localStore, localTotal, nextOfflineNumber, type LocalOrder } from './offline';
import { flushOrders } from './sync';
import { ApiError } from './api';

function order(uuid: string, at: string): LocalOrder {
  return {
    client_uuid: uuid,
    order_number: `N-${uuid}`,
    created_at: at,
    customer_name: null,
    customer_mobile: null,
    items: [{ product_id: 1, product_variant_id: null, stock_key: 'p1', name: 'Mango', unit_price: '20.00', quantity: 2 }],
  };
}

const okResult = (o: LocalOrder, conflicts: never[] = []) => ({ order: { order_number: o.order_number }, duplicate: false, conflicts }) as never;

describe('offline sync queue', () => {
  beforeEach(async () => {
    for (const o of await localStore.allOrders()) await localStore.deleteOrder(o.client_uuid);
    await localStore.clearNotes();
  });

  it('sends oldest first and clears the queue', async () => {
    await localStore.saveOrder(order('b', '2026-01-01T10:00:00Z'));
    await localStore.saveOrder(order('a', '2026-01-01T09:00:00Z'));
    const seen: string[] = [];
    const res = await flushOrders(async (o) => {
      seen.push(o.client_uuid);
      return okResult(o);
    });
    expect(seen).toEqual(['a', 'b']);
    expect(res).toMatchObject({ sent: 2, waiting: 0 });
  });

  it('keeps orders when the network drops mid-sync so nothing is lost', async () => {
    await localStore.saveOrder(order('a', '2026-01-01T09:00:00Z'));
    await localStore.saveOrder(order('b', '2026-01-01T10:00:00Z'));
    const res = await flushOrders(async (o) => {
      if (o.client_uuid === 'b') throw new ApiError('offline', 'network', 0);
      return okResult(o);
    });
    expect(res).toMatchObject({ sent: 1, waiting: 1 });
    expect((await localStore.allOrders())[0].client_uuid).toBe('b');
  });

  it('resending after a lost reply uses the same UUID', async () => {
    await localStore.saveOrder(order('a', '2026-01-01T09:00:00Z'));
    const uuids: string[] = [];
    await flushOrders(async (o) => {
      uuids.push(o.client_uuid);
      throw new ApiError('offline', 'network', 0);
    });
    await flushOrders(async (o) => {
      uuids.push(o.client_uuid);
      return okResult(o);
    });
    expect(uuids).toEqual(['a', 'a']);
    expect(await localStore.allOrders()).toHaveLength(0);
  });

  it('records conflicts as notes for staff', async () => {
    await localStore.saveOrder(order('a', '2026-01-01T09:00:00Z'));
    const res = await flushOrders(async (o) => ({ order: { order_number: o.order_number }, duplicate: false, conflicts: [{ type: 'stock_shortfall', item: 'Mango', short: '2.000' }] }) as never);
    expect(res.conflicts).toBe(1);
    expect((await localStore.notes())[0].message).toContain('short by 2');
  });

  it('a rejected order stays queued with its reason and does not block others', async () => {
    await localStore.saveOrder(order('a', '2026-01-01T09:00:00Z'));
    await localStore.saveOrder(order('b', '2026-01-01T10:00:00Z'));
    const res = await flushOrders(async (o) => {
      if (o.client_uuid === 'a') throw new ApiError('Bad data', 'validation_failed', 422);
      return okResult(o);
    });
    expect(res).toMatchObject({ sent: 1, waiting: 1 });
    expect((await localStore.getOrder('a'))?.last_error).toBe('Bad data');
  });
});

describe('offline order numbers', () => {
  it('include the terminal code and count up per day', async () => {
    const day = new Date(2026, 10, 1);
    expect(await nextOfflineNumber('TAB3', day)).toBe('261101-TAB3-0001');
    expect(await nextOfflineNumber('TAB3', day)).toBe('261101-TAB3-0002');
    expect(await nextOfflineNumber('TAB3', new Date(2026, 10, 2))).toBe('261102-TAB3-0001');
  });

  it('total is price times quantity', () => {
    expect(localTotal(order('a', ''))).toBe(40);
  });
});
