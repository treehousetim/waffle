import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// The release, from the repository's VERSION file: the one place a version is
// written (docs/releasing.md). The fallback keeps a build from a tree without
// one usable.
let appVersion = '0'
try {
  appVersion = readFileSync(new URL('../../VERSION', import.meta.url), 'utf8').trim() || '0'
} catch {
  // No VERSION: build anyway, as release 0.
}

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
