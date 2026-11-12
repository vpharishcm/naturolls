import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib/api';
import { localStore } from '../lib/offline';
import type { Category, Product } from '../lib/types';
import { useApp } from '../stores/app';

/** Products and categories, served from the device copy when there is no connection. */
export function useCatalog() {
  const online = useApp((s) => s.online);
  const [cached, setCached] = useState<{ products: Product[]; categories: Category[] } | null>(null);

  useEffect(() => {
    localStore.getCatalog().then(setCached);
  }, []);

  const products = useQuery({ queryKey: ['products'], queryFn: () => api.get<Product[]>('/products'), enabled: online, staleTime: 30000 });
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.get<Category[]>('/categories'), enabled: online, staleTime: 60000 });

  useEffect(() => {
    if (products.data && categories.data) localStore.saveCatalog(products.data, categories.data);
  }, [products.data, categories.data]);

  // Every terminal polls live stock so a sale elsewhere shows up within seconds.
  const stock = useQuery({
    queryKey: ['stock'],
    queryFn: () => api.get<{ stock_key: string; available_qty: string }[]>('/stock/availability'),
    enabled: online,
    refetchInterval: 4000,
  });

  const available = new Map<string, number>();
  stock.data?.forEach((s) => available.set(s.stock_key, Number(s.available_qty)));

  return {
    products: products.data ?? cached?.products ?? [],
    categories: categories.data ?? cached?.categories ?? [],
    loading: products.isLoading && !cached?.products.length,
    available,
  };
}
