export interface Variant {
  id: number;
  name: string;
  sku: string | null;
  price: string;
  cost: string | null;
  is_active: boolean;
  stock_key: string;
  available_qty: string | null;
}

export interface Product {
  id: number;
  category_id: number;
  category_name?: string;
  name: string;
  sku: string | null;
  code: string | null;
  description: string | null;
  image_url: string | null;
  art: string | null;
  art_color: string | null;
  unit: string;
  price: string;
  cost: string | null;
  gst_rate: string;
  track_inventory: boolean;
  inventory_method: string;
  min_stock: string;
  is_active: boolean;
  is_favourite: boolean;
  display_order: number;
  stock_key: string;
  available_qty: string | null;
  physical_qty: string | null;
  reserved_qty: string | null;
  variants?: Variant[];
}

export interface Category {
  id: number;
  parent_id: number | null;
  name: string;
  display_order: number;
  is_active: boolean;
}

export interface OrderItem {
  id: number;
  product_id: number;
  product_variant_id: number | null;
  stock_key: string;
  name: string;
  unit_price: string;
  gst_rate: string;
  quantity: string;
  served_qty: string;
  stock_shortfall: string;
  conflict_note: string | null;
  line_total: string;
}

export interface Order {
  id: number;
  order_number: string;
  client_uuid: string | null;
  status: 'unbilled' | 'billed' | 'cancelled';
  customer_id: number | null;
  customer_name: string | null;
  customer_mobile: string | null;
  item_count: string;
  subtotal: string;
  version: number;
  created_offline: boolean;
  cancel_reason: string | null;
  created_at: string;
  billed_at: string | null;
  created_by_name?: string;
  invoice_id?: number | null;
  invoice_number?: string | null;
  invoice_total?: string | null;
  items?: OrderItem[];
}

export interface TaxLine { rate: string; taxable: string; cgst: string; sgst: string; tax: string }

export interface BillPreview {
  subtotal: string;
  promotion_discount: string;
  manual_discount: string;
  loyalty_discount: string;
  taxable_amount: string;
  tax_amount: string;
  total: string;
  tax_breakup: TaxLine[];
  promotion: { id: number; name: string } | null;
  promotions: { id: number; name: string; discount: string }[];
  available_promotions: { id: number; name: string; requires_authorization: boolean; discount: string }[];
  version: number;
}

export interface Payment {
  id: number;
  invoice_id: number;
  invoice_number?: string;
  type: 'payment' | 'refund';
  method: string;
  amount: string;
  reference: string | null;
  status: string;
  reason: string | null;
  reconciled_at: string | null;
  staff?: string;
  created_at: string;
}

export interface Invoice {
  id: number;
  invoice_number: string;
  order_id: number;
  order_number: string;
  customer_name: string | null;
  items: { name: string; quantity: string; unit_price: string; line_total: string }[];
  subtotal: string;
  promotion_discount: string;
  manual_discount: string;
  loyalty_discount: string;
  tax_amount: string;
  total: string;
  refunded_amount: string;
  print_count: number;
  issued_at: string;
  edit_count: number;
  edited_at: string | null;
  can_edit: boolean;
  edit_until: string | null;
  payments?: Payment[];
}

export interface ReceiptData {
  business: { name: string; address: string; phone: string; gstin: string };
  width: number;
  footer: string;
  duplicate: boolean;
  edited: boolean;
  invoice_number: string;
  order_number: string;
  customer_name: string | null;
  issued_at: string;
  items: { name: string; quantity: string; unit_price: string; line_total: string }[];
  subtotal: string;
  discount: string;
  tax_breakup: TaxLine[];
  total: string;
  payments: { method: string; amount: string; tendered: string | null; reference: string | null; change_given: string }[];
  escpos_base64: string;
}

export interface Customer {
  id: number;
  name: string;
  mobile: string | null;
  birthday: string | null;
  notes: string | null;
  invoices_count?: number;
  total_spent?: string | null;
  loyalty_account?: { id: number; points_balance: number; lifetime_earned: number; lifetime_redeemed: number; transactions?: LoyaltyTxn[] } | null;
}

export interface LoyaltyTxn { id: number; type: string; points: number; balance_after: number; note: string | null; created_at: string }

export interface PageMeta { current_page: number; last_page: number; per_page: number; total: number }

export type Settings = Record<string, string | number | boolean>;
