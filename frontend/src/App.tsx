import { lazy, Suspense, useEffect } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { KeyboardDone } from './components/KeyboardDone';
import { Layout } from './components/Layout';
import { Loading } from './components/ui';
import { setUnauthenticatedHandler } from './lib/api';
import Login from './pages/Login';
import OrderScreen from './pages/OrderScreen';
import { useApp } from './stores/app';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const OrdersToBill = lazy(() => import('./pages/OrdersToBill'));
const AllOrders = lazy(() => import('./pages/AllOrders'));
const OrderDetail = lazy(() => import('./pages/OrderDetail'));
const Products = lazy(() => import('./pages/Products'));
const ProductForm = lazy(() => import('./pages/ProductForm'));
const Inventory = lazy(() => import('./pages/Inventory'));
const Production = lazy(() => import('./pages/Production'));
const Payments = lazy(() => import('./pages/Payments'));
const Reports = lazy(() => import('./pages/Reports'));
const Customers = lazy(() => import('./pages/Customers'));
const Settings = lazy(() => import('./pages/Settings'));

export default function App() {
  const user = useApp((s) => s.user);

  useEffect(() => {
    setUnauthenticatedHandler(() => useApp.getState().clearSession());
    if (user && navigator.onLine) useApp.getState().refreshSession().catch(() => {});
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  if (!user) return <><Login /><KeyboardDone /></>;

  return (
    <Suspense fallback={<Loading />}>
      <KeyboardDone />
      <Routes>
        <Route element={<Layout />}>
          <Route index element={<Dashboard />} />
          <Route path="order/:id" element={<OrderScreen />} />
          <Route path="to-bill" element={<OrdersToBill />} />
          <Route path="orders" element={<AllOrders />} />
          <Route path="orders/:id" element={<OrderDetail />} />
          <Route path="products" element={<Products />} />
          <Route path="products/new" element={<ProductForm />} />
          <Route path="products/:id" element={<ProductForm />} />
          <Route path="inventory" element={<Inventory />} />
          <Route path="production" element={<Production />} />
          <Route path="payments" element={<Payments />} />
          <Route path="reports" element={<Reports />} />
          <Route path="customers" element={<Customers />} />
          <Route path="settings" element={<Settings />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </Suspense>
  );
}
