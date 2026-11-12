import { openDB, type DBSchema, type IDBPDatabase } from 'idb';
import type { Category, Product } from './types';

export interface LocalItem {
  product_id: number;
  product_variant_id: number | null;
  stock_key: string;
  name: string;
  unit_price: string;
  quantity: number;
}

/** An order entered without a connection. It lives here until the server accepts it. */
export interface LocalOrder {
  client_uuid: string;
  order_number: string;
  created_at: string;
  customer_name: string | null;
  customer_mobile: string | null;
  items: LocalItem[];
  last_error?: string | null;
}

export interface SyncNote {
  id?: number;
  at: string;
  order_number: string;
  message: string;
}

interface PosDB extends DBSchema {
  kv: { key: string; value: unknown };
  orders: { key: string; value: LocalOrder };
  notes: { key: number; value: SyncNote };
}

let dbPromise: Promise<IDBPDatabase<PosDB>> | null = null;
function db() {
  dbPromise ??= openDB<PosDB>('naturolls-pos', 1, {
    upgrade(d) {
      d.createObjectStore('kv');
      d.createObjectStore('orders', { keyPath: 'client_uuid' });
      d.createObjectStore('notes', { keyPath: 'id', autoIncrement: true });
    },
  });
  return dbPromise;
}

export const localStore = {
  async getCatalog(): Promise<{ products: Product[]; categories: Category[] }> {
    const d = await db();
    return {
      products: ((await d.get('kv', 'products')) as Product[]) ?? [],
      categories: ((await d.get('kv', 'categories')) as Category[]) ?? [],
    };
  },
  async saveCatalog(products: Product[], categories: Category[]) {
    const d = await db();
    await d.put('kv', products, 'products');
    await d.put('kv', categories, 'categories');
  },
  async setKv(key: string, value: unknown) {
    await (await db()).put('kv', value, key);
  },
  async getKv<T>(key: string): Promise<T | undefined> {
    return (await (await db()).get('kv', key)) as T | undefined;
  },
  async saveOrder(order: LocalOrder) {
    await (await db()).put('orders', order);
  },
  async getOrder(uuid: string) {
    return (await db()).get('orders', uuid);
  },
  async deleteOrder(uuid: string) {
    await (await db()).delete('orders', uuid);
  },
  async allOrders(): Promise<LocalOrder[]> {
    return (await db()).getAll('orders');
  },
  async addNote(note: SyncNote) {
    await (await db()).add('notes', note);
  },
  async notes(): Promise<SyncNote[]> {
    return (await (await db()).getAll('notes')).reverse().slice(0, 50);
  },
  async clearNotes() {
    await (await db()).clear('notes');
  },
};

function pad(n: number, len: number) {
  return String(n).padStart(len, '0');
}

/**
 * Offline numbers include this terminal's permanent code, so two devices working
 * offline can never produce the same number: 261101-TAB3-0007.
 */
export async function nextOfflineNumber(terminalCode: string, now = new Date()): Promise<string> {
  const day = `${pad(now.getFullYear() % 100, 2)}${pad(now.getMonth() + 1, 2)}${pad(now.getDate(), 2)}`;
  const key = `offline-seq:${day}`;
  const next = ((await localStore.getKv<number>(key)) ?? 0) + 1;
  await localStore.setKv(key, next);
  return `${day}-${terminalCode}-${pad(next, 4)}`;
}

export function localTotal(order: LocalOrder): number {
  return order.items.reduce((sum, i) => sum + Number(i.unit_price) * i.quantity, 0);
}
