import axios, { AxiosError, type AxiosRequestConfig } from 'axios';
import type { PageMeta } from './types';
import { uuid } from './uuid';

const TOKEN_KEY = 'naturolls.token';
const DEVICE_KEY = 'naturolls.device';

export function deviceId(): string {
  let id = localStorage.getItem(DEVICE_KEY);
  if (!id) {
    id = uuid();
    localStorage.setItem(DEVICE_KEY, id);
  }
  return id;
}

export const tokens = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (t: string) => localStorage.setItem(TOKEN_KEY, t),
  clear: () => localStorage.removeItem(TOKEN_KEY),
};

export class ApiError extends Error {
  code: string;
  status: number;
  details?: Record<string, unknown>;
  errors?: Record<string, string[]>;

  constructor(message: string, code: string, status: number, details?: Record<string, unknown>, errors?: Record<string, string[]>) {
    super(message);
    this.code = code;
    this.status = status;
    this.details = details;
    this.errors = errors;
  }
  /** True when the request never got an answer, so it is safe to keep and retry. */
  get isNetwork() {
    return this.status === 0;
  }
}

const client = axios.create({ baseURL: '/api/v1', timeout: 15000, headers: { Accept: 'application/json' } });

client.interceptors.request.use((config) => {
  const token = tokens.get();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  config.headers['X-Device-Id'] = deviceId();
  return config;
});

let onUnauthenticated: () => void = () => {};
export function setUnauthenticatedHandler(fn: () => void) {
  onUnauthenticated = fn;
}

function toApiError(e: unknown): ApiError {
  const err = e as AxiosError<{ message?: string; code?: string; details?: Record<string, unknown>; errors?: Record<string, string[]> }>;
  // A gateway error means the server was never reached, which is the same as being offline.
  if (!err.response || [502, 503, 504].includes(err.response.status)) return new ApiError('No connection to the server.', 'network', 0);
  const { data, status } = err.response;
  if (status === 401 && data?.code === 'unauthenticated') onUnauthenticated();
  return new ApiError(data?.message ?? 'Something went wrong.', data?.code ?? 'error', status, data?.details, data?.errors);
}

export async function request<T>(config: AxiosRequestConfig): Promise<T> {
  try {
    const res = await client.request(config);
    return res.data.data as T;
  } catch (e) {
    throw toApiError(e);
  }
}

export const api = {
  get: <T>(url: string, params?: object) => request<T>({ method: 'GET', url, params }),
  post: <T>(url: string, data?: unknown, key?: string) =>
    request<T>({ method: 'POST', url, data, headers: key ? { 'Idempotency-Key': key } : undefined }),
  patch: <T>(url: string, data?: unknown, key?: string) =>
    request<T>({ method: 'PATCH', url, data, headers: key ? { 'Idempotency-Key': key } : undefined }),
  put: <T>(url: string, data?: unknown) => request<T>({ method: 'PUT', url, data }),
  delete: <T>(url: string, data?: unknown, key?: string) =>
    request<T>({ method: 'DELETE', url, data, headers: key ? { 'Idempotency-Key': key } : undefined }),
  async page<T>(url: string, params?: object): Promise<{ data: T[]; meta: PageMeta }> {
    try {
      const res = await client.get(url, { params });
      return { data: res.data.data, meta: res.data.meta };
    } catch (e) {
      throw toApiError(e);
    }
  },
  async download(url: string, fileName: string): Promise<void> {
    try {
      const res = await client.get(url, { responseType: 'blob', timeout: 120000 });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(res.data);
      link.download = fileName;
      link.click();
      setTimeout(() => URL.revokeObjectURL(link.href), 10000);
    } catch (e) {
      throw toApiError(e);
    }
  },
};
