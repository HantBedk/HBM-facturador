import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  /**
   * Proxy /api: en el PC → 127.0.0.1:8080; con Vite en Docker Compose → http://nginx (variable de entorno).
   */
  const apiProxyTarget =
    process.env.VITE_API_PROXY_TARGET || env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8080'

  const rawHmrHost = process.env.VITE_HMR_EXTERNAL_HOST
  const hmrHost =
    rawHmrHost && String(rawHmrHost).trim() !== '' ? String(rawHmrHost).trim() : 'localhost'
  const rawHmrPort = process.env.VITE_HMR_CLIENT_PORT
  const hmrClientPort =
    rawHmrPort && String(rawHmrPort).trim() !== '' ? Number(rawHmrPort) : 5173
  const hmrProtocol =
    process.env.VITE_HMR_PROTOCOL && String(process.env.VITE_HMR_PROTOCOL).trim() === 'wss'
      ? 'wss'
      : 'ws'

  return {
    plugins: [vue(), tailwindcss()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      /** true: accesible desde fuera del contenedor (Docker) y en localhost */
      host: true,
      /** Detrás de Nginx con otro Host (ej. hbm.dataguaviare.com.co) */
      allowedHosts: true,
      port: 5173,
      strictPort: true,
      headers: {
        'Cache-Control': 'no-store',
      },
      watch: {
        usePolling: process.env.CHOKIDAR_USEPOLLING === 'true',
      },
      hmr: {
        protocol: hmrProtocol,
        host: hmrHost,
        port: 5173,
        clientPort: hmrClientPort,
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
