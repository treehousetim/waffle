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
    // tools/npm.sh runs the dev server in a container: it listens on all of the
    // container's interfaces (published to 127.0.0.1 only) and reaches the
    // stack's Caddy through host.docker.internal. Outside one, the defaults.
    host: process.env.VITE_DEV_HOST || 'localhost',
    proxy: {
      '/api': process.env.VITE_API_TARGET || 'http://localhost:8080',
      '/cron': process.env.VITE_API_TARGET || 'http://localhost:8080',
      '/webhooks': process.env.VITE_API_TARGET || 'http://localhost:8080'
    }
  }
})
