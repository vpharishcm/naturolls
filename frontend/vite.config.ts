import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'icons/*.png'],
      manifest: {
        name: 'Naturolls POS',
        short_name: 'Naturolls',
        description: 'Billing, stock and daily production for Naturolls',
        theme_color: '#B3164A',
        background_color: '#FFF7EC',
        display: 'standalone',
        orientation: 'any',
        start_url: '/',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api/, /^\/storage/],
        runtimeCaching: [
          {
            urlPattern: /\/storage\/products\//,
            handler: 'CacheFirst',
            options: { cacheName: 'product-images', expiration: { maxEntries: 300, maxAgeSeconds: 2592000 } },
          },
        ],
      },
    }),
  ],
  server: {
    port: 5173,
    host: true,
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/storage': 'http://127.0.0.1:8000',
    },
  },
  // The dev server has no manifest or service worker. Use this to try the real, installable build on a device.
  preview: {
    port: 4173,
    host: true,
    allowedHosts: ['.devtunnels.ms'],
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/storage': 'http://127.0.0.1:8000',
    },
  },
  test: { environment: 'node', setupFiles: ['./src/test-setup.ts'] },
});
