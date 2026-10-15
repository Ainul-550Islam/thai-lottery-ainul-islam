export default {
  plugins: {
    /*
     * Part 66 — the plugin name is the whole fix.
     *
     * resources/css/app.css is written for Tailwind v4: it opens with
     * `@import 'tailwindcss'` and loads its configuration with `@config
     * '../../tailwind.config.js'`. Both are v4 syntax. The toolchain, however,
     * pinned tailwindcss ^3.4.17, whose package has no CSS entry point — so
     * postcss-import resolved `@import 'tailwindcss'` to
     * node_modules/tailwindcss/lib/index.js and tried to parse JavaScript as
     * CSS:
     *
     *   CssSyntaxError: [postcss] postcss-import:
     *   node_modules/tailwindcss/lib/index.js:1:1: Unknown word "use strict"
     *
     * That is why `npm run build` failed and public/build/manifest.json never
     * existed — which in turn is why every page suite that renders a layout
     * calling @vite failed in this environment.
     *
     * In v4 the PostCSS plugin moved to its own package. The v3 name
     * (`tailwindcss`) no longer resolves to a plugin, so leaving it would fail
     * differently but just as firmly.
     */
    '@tailwindcss/postcss': {},
    autoprefixer: {},
  },
};
