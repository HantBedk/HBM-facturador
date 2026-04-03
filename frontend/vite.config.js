import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  /**
   * Desarrollo local: API en Docker o artisan en 8080 → proxy /api hacia aquí.
   * Si en el futuro usas Vite dentro de Docker, define VITE_API_PROXY_TARGET=http://nginx
   */
  const apiProxyTarget = env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8080'

  return {
    plugins: [vue(), tailwindcss()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      /** Abre siempre http://localhost:5173 (HMR y mismo origen para /api) */
      host: 'localhost',
      port: 5173,
      strictPort: true,
      headers: {
        'Cache-Control': 'no-store',
      },
      hmr: {
        protocol: 'ws',
        host: 'localhost',
        port: 5173,
        clientPort: 5173,
      },
      proxy: {
        '/api': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
      },
    },
  }
})
