// Generates the PWA icons from public/favicon.svg. Run: node scripts/make-icons.mjs
import { mkdirSync, readFileSync } from 'node:fs';
import sharp from 'sharp';

const svg = readFileSync('public/favicon.svg');
mkdirSync('public/icons', { recursive: true });
await sharp(svg).resize(192, 192).png().toFile('public/icons/icon-192.png');
await sharp(svg).resize(512, 512).png().toFile('public/icons/icon-512.png');
await sharp(svg).resize(400, 400).extend({ top: 56, bottom: 56, left: 56, right: 56, background: '#B3164A' }).png().toFile('public/icons/icon-maskable-512.png');
