import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'
import { readFileSync } from 'node:fs'
import { Agent } from 'node:https'

// https://vite.dev/config/
export default defineConfig(({ mode, command }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const proxy = {
    target: env.API_PROXY_TARGET || 'http://localhost:8000',
    changeOrigin: true,
    cookieDomainRewrite: '',
    agent: command === 'serve' && env.API_PROXY_CA_FILE
      ? new Agent({ ca: readFileSync(env.API_PROXY_CA_FILE) })
      : undefined,
  }

  return {
    plugins: [react(), tailwindcss()],
    server: {
      port: 5173,
      strictPort: true,
      proxy: { '/api': proxy, '/sanctum': proxy },
    },
  }
})
