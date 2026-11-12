import { api } from './api';
import type { ReceiptData } from './types';

export type PrintDriver = 'browser' | 'bluetooth' | 'usb' | 'network';

export interface PrintOutcome {
  printed: boolean;
  error: string | null;
  duplicate: boolean;
}

/* Web Bluetooth and WebUSB are not in TypeScript's standard DOM types. */
/* eslint-disable @typescript-eslint/no-explicit-any */
const nav = navigator as any;

const BT_SERVICES = ['000018f0-0000-1000-8000-00805f9b34fb', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2', '49535343-fe7d-4ae5-8fa9-9fafd205e455'];

let btCharacteristic: any = null;
let usbDevice: any = null;
let usbEndpoint = 0;

export const printerSupport = {
  bluetooth: typeof navigator !== 'undefined' && 'bluetooth' in navigator,
  usb: typeof navigator !== 'undefined' && 'usb' in navigator,
};

async function connectBluetooth(): Promise<void> {
  const device = await nav.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BT_SERVICES });
  const server = await device.gatt.connect();
  for (const service of await server.getPrimaryServices()) {
    for (const c of await service.getCharacteristics()) {
      if (c.properties.write || c.properties.writeWithoutResponse) {
        btCharacteristic = c;
        return;
      }
    }
  }
  throw new Error('This Bluetooth device does not accept print data.');
}

async function connectUsb(): Promise<void> {
  const device = await nav.usb.requestDevice({ filters: [] });
  await device.open();
  if (!device.configuration) await device.selectConfiguration(1);
  for (const iface of device.configuration.interfaces) {
    const alt = iface.alternates[0];
    const out = alt.endpoints.find((e: any) => e.direction === 'out');
    if (out) {
      await device.claimInterface(iface.interfaceNumber);
      usbDevice = device;
      usbEndpoint = out.endpointNumber;
      return;
    }
  }
  throw new Error('This USB device does not accept print data.');
}

export async function connectPrinter(driver: PrintDriver): Promise<void> {
  if (driver === 'bluetooth') await connectBluetooth();
  if (driver === 'usb') await connectUsb();
}

export function deviceConnected(driver: PrintDriver): boolean {
  if (driver === 'bluetooth') return Boolean(btCharacteristic?.service?.device?.gatt?.connected);
  if (driver === 'usb') return Boolean(usbDevice?.opened);
  return true;
}

function decode(b64: string): Uint8Array {
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
}

async function sendBluetooth(bytes: Uint8Array): Promise<void> {
  if (!deviceConnected('bluetooth')) await connectBluetooth();
  // Small chunks: most Bluetooth printers drop anything larger than a low-energy packet.
  for (let i = 0; i < bytes.length; i += 180) {
    const chunk = bytes.slice(i, i + 180);
    if (btCharacteristic.properties.writeWithoutResponse) await btCharacteristic.writeValueWithoutResponse(chunk);
    else await btCharacteristic.writeValue(chunk);
  }
}

async function sendUsb(bytes: Uint8Array): Promise<void> {
  if (!deviceConnected('usb')) await connectUsb();
  const result = await usbDevice.transferOut(usbEndpoint, bytes);
  if (result.status !== 'ok') throw new Error('The USB printer did not accept the data.');
}

/** Hands off to the system print dialog, which reaches any printer Android or the PC knows about. */
function browserPrint(render: (r: ReceiptData) => void, receipt: ReceiptData): Promise<void> {
  return new Promise((resolve) => {
    render(receipt);
    const style = document.createElement('style');
    style.textContent = `@media print { @page { size: ${receipt.width}mm auto; margin: 0; } }`;
    document.head.appendChild(style);
    const done = () => {
      window.removeEventListener('afterprint', done);
      style.remove();
      resolve();
    };
    window.addEventListener('afterprint', done);
    setTimeout(() => window.print(), 50);
  });
}

/**
 * Prints an already-issued invoice. The invoice and payment are committed before this
 * runs, so a failure here is reported and logged but changes nothing else.
 */
export async function printInvoice(invoiceId: number, driver: PrintDriver, renderReceipt: (r: ReceiptData) => void): Promise<PrintOutcome> {
  if (driver === 'network') {
    return api.post<PrintOutcome>(`/invoices/${invoiceId}/print`);
  }
  const receipt = await api.get<ReceiptData>(`/invoices/${invoiceId}/receipt`);
  let error: string | null = null;
  try {
    if (driver === 'browser') await browserPrint(renderReceipt, receipt);
    if (driver === 'bluetooth') await sendBluetooth(decode(receipt.escpos_base64));
    if (driver === 'usb') await sendUsb(decode(receipt.escpos_base64));
  } catch (e) {
    error = (e as Error).message || 'Printing failed.';
  }
  await api.post(`/invoices/${invoiceId}/print-log`, { driver, status: error ? 'failed' : 'success', error });
  return { printed: !error, error, duplicate: receipt.duplicate };
}
