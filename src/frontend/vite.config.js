import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// npm_package_version is exported by npm when the build runs through an npm
// script. The fallback keeps a bare `vite build` usable.
const appVersion = process.env.npm_package_version || '0.0.0'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],

  define: {
    __APP_VERSION__: JSON.stringify(appVersion)
  },

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },

  build: {
    // The web root. The `build` npm script clears ../public/assets and
    // ../public/index.html first; emptyOutDir must stay false so index.php and
    // the rest of the PHP docroot survive the build.
    outDir: '../public',
    emptyOutDir: false,
    assetsDir: 'assets'
  },

  server: {
    port: 5173,
    // The dev server serves the UI; the API is the Compose stack's Caddy.
    proxy: {
      '/api': 'http://localhost:8080',
      '/cron': 'http://localhost:8080',
      '/webhooks': 'http://localhost:8080'
    }
  }
})
