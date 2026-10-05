/** @type {import('tailwindcss').Config} */
const colors = require('tailwindcss/colors');

/*
 * Build (standalone CLI, no Node needed):
 *   ./public/vendor/tailwind/tailwindcss-linux-x64 -c tailwind.config.js \
 *     -i public/vendor/tailwind/input.css -o public/vendor/tailwind/tailwind.min.css --minify
 *
 * The output is PURGED to the classes found in `content`, so rebuild after any
 * markup change that introduces a class the build has not seen — otherwise the
 * class silently resolves to nothing.
 */
module.exports = {
  content: [
    "./*.php",
    "./{admin,api,attapi,faculty,hod,student,includes,classes}/**/*.php",
    "./public/js/*.js",
    "./public/vendor/tailwind/input.css",
  ],
  // Class names assembled at runtime (PHP string concatenation, JS template
  // literals such as `bg-${color}-100`) are invisible to the purge scan.
  safelist: [
    { pattern: /^(bg|text|border|ring)-(gray|slate|red|rose|orange|amber|yellow|green|emerald|teal|sky|blue|indigo|violet|purple)-(50|100|200|500|600|700|800)$/ },
  ],
  theme: {
    extend: {
      colors: {
        // One brand hue for the whole portal. Pages were written against
        // Tailwind's `blue`, so the brand is mapped onto that name: every
        // existing bg-blue-600 / text-blue-700 picks it up without markup churn.
        blue: {
          50: '#eff4ff', 100: '#dce6fd', 200: '#c1d3fc', 300: '#97b6fa',
          400: '#6690f5', 500: '#426cef', 600: '#2d4fe3', 700: '#253ed0',
          800: '#2535a9', 900: '#243285', 950: '#1a2051',
        },
        brand: {
          50: '#eff4ff', 100: '#dce6fd', 200: '#c1d3fc', 300: '#97b6fa',
          400: '#6690f5', 500: '#426cef', 600: '#2d4fe3', 700: '#253ed0',
          800: '#2535a9', 900: '#243285', 950: '#1a2051',
        },
        // Cooler neutrals read as more modern than Tailwind's warm gray.
        gray: colors.slate,
      },
      fontFamily: {
        sans: ['"Inter Variable"', 'Inter', 'ui-sans-serif', 'system-ui', '-apple-system', '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'Arial', 'sans-serif'],
      },
      boxShadow: {
        sm: '0 1px 2px 0 rgb(15 23 42 / 0.05)',
        DEFAULT: '0 1px 3px 0 rgb(15 23 42 / 0.07), 0 1px 2px -1px rgb(15 23 42 / 0.05)',
        md: '0 4px 12px -2px rgb(15 23 42 / 0.08), 0 2px 4px -2px rgb(15 23 42 / 0.04)',
        lg: '0 12px 28px -8px rgb(15 23 42 / 0.14), 0 4px 10px -4px rgb(15 23 42 / 0.06)',
        xl: '0 24px 48px -12px rgb(15 23 42 / 0.18)',
        '2xl': '0 32px 64px -16px rgb(15 23 42 / 0.25)',
        ring: '0 0 0 4px rgb(45 79 227 / 0.14)',
      },
      borderRadius: {
        xl: '0.875rem',
        '2xl': '1.125rem',
      },
      keyframes: {
        'fade-in': { from: { opacity: 0, transform: 'translateY(4px)' }, to: { opacity: 1, transform: 'none' } },
      },
      animation: {
        'fade-in': 'fade-in .25s ease-out',
      },
    },
  },
  plugins: [],
}
