import { create } from 'zustand';
import { api, deviceId, tokens } from '../lib/api';
import type { Settings } from '../lib/types';

interface User { id: number; name: string; username: string; role: string; permissions: string[] }
interface Terminal { id: number; code: string; name: string | null }

const USER_KEY = 'naturolls.user';
const TERMINAL_KEY = 'naturolls.terminal';
const SETTINGS_KEY = 'naturolls.settings';

function load<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : null;
  } catch {
    return null;
  }
}

interface AppState {
  user: User | null;
  terminal: Terminal | null;
  settings: Settings;
  online: boolean;
  waitingOrders: number;
  toast: { text: string; tone: 'info' | 'error' | 'ok' } | null;
  login(username: string, password: string): Promise<void>;
  refreshSession(): Promise<void>;
  logout(): Promise<void>;
  clearSession(): void;
  setOnline(v: boolean): void;
  setWaitingOrders(n: number): void;
  setSettings(s: Settings): void;
  notify(text: string, tone?: 'info' | 'error' | 'ok'): void;
  can(permission: string): boolean;
}

let toastTimer: ReturnType<typeof setTimeout> | undefined;

export const useApp = create<AppState>((set, get) => ({
  user: tokens.get() ? load<User>(USER_KEY) : null,
  terminal: load<Terminal>(TERMINAL_KEY),
  settings: load<Settings>(SETTINGS_KEY) ?? {},
  online: navigator.onLine,
  waitingOrders: 0,
  toast: null,

  async login(username, password) {
    const res = await api.post<{ token: string; user: User; terminal: Terminal }>('/auth/login', {
      username,
      password,
      device_id: deviceId(),
      device_name: navigator.userAgent.slice(0, 80),
    });
    tokens.set(res.token);
    localStorage.setItem(USER_KEY, JSON.stringify(res.user));
    localStorage.setItem(TERMINAL_KEY, JSON.stringify(res.terminal));
    set({ user: res.user, terminal: res.terminal });
    await get().refreshSession();
  },

  async refreshSession() {
    const res = await api.get<{ user: User; settings: Settings }>('/auth/me');
    localStorage.setItem(USER_KEY, JSON.stringify(res.user));
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(res.settings));
    set({ user: res.user, settings: res.settings });
  },

  async logout() {
    try {
      await api.post('/auth/logout');
    } finally {
      get().clearSession();
    }
  },

  clearSession() {
    tokens.clear();
    localStorage.removeItem(USER_KEY);
    set({ user: null });
  },

  setOnline: (online) => set({ online }),
  setWaitingOrders: (waitingOrders) => set({ waitingOrders }),
  setSettings(settings) {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
    set({ settings });
  },
  notify(text, tone = 'info') {
    clearTimeout(toastTimer);
    set({ toast: { text, tone } });
    toastTimer = setTimeout(() => set({ toast: null }), 3500);
  },
  can: (permission) => Boolean(get().user?.permissions.includes(permission)),
}));
