/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    // A 10-inch tablet held upright can report a width as low as 600, so width alone would treat it as a phone.
    screens: {
      sm: '640px',
      md: { raw: '(min-width: 768px), (min-width: 560px) and (orientation: portrait)' },
      side: { raw: '(min-width: 768px) and (orientation: landscape)' },
      port: { raw: '(min-width: 560px) and (orientation: portrait)' },
      lg: '1024px',
      xl: '1280px',
      '2xl': '1536px',
    },
    extend: {
      fontFamily: { sans: ['Nunito', 'system-ui', 'sans-serif'] },
      colors: {
        cream: '#FFF7EC',
        ink: '#1E1B2E',
        berry: { DEFAULT: '#B3164A', soft: '#FCE7EE' },
        mint: { DEFAULT: '#0F7B5A', soft: '#E6F5EE', ink: '#0B4F3A' },
        rust: { DEFAULT: '#9A3412', soft: '#FDECE4', line: '#C2410C' },
        amber: { ink: '#8A4B00', soft: '#FFF0D6' },
        sand: '#F3E9DA',
        line: { DEFAULT: '#D8C9B5', soft: '#EFE4D4', strong: '#8C7F73' },
        muted: { DEFAULT: '#5B4B63', soft: '#6B5B73' },
      },
      boxShadow: { tile: '0 2px 0 #E8DCCB' },
    },
  },
  plugins: [],
};
