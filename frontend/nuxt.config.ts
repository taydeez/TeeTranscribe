import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  modules: ['@pinia/nuxt', '@nuxt/eslint'],
  css: ['~/assets/css/main.css'],
  nitro: {
    compressPublicAssets: true,
    cloudflare: { deployConfig: false, nodeCompat: true },
  },
  vite: { plugins: [tailwindcss()] },
  runtimeConfig: {
    trustedProxyIps: '',
    apiBase: 'http://127.0.0.1:8000/api/v1',
  },
  app: {
    head: {
      title: 'TeeScriber | Audio Transcription & Video Dubbing',
      htmlAttrs: { lang: 'en' },
      link: [
        { rel: 'preload', as: 'font', type: 'font/woff2', href: '/fonts/manrope-latin.woff2', crossorigin: 'anonymous' },
        { rel: 'preload', as: 'font', type: 'font/woff2', href: '/fonts/inter-latin.woff2', crossorigin: 'anonymous' },
        { rel: 'icon', type: 'image/png', href: '/favicon.png' },
      ],
      meta: [{ name: 'description', content: 'Transcribe audio, dub videos, and translate text with TeeScriber. Sign up for 10 free credits and keep your projects together in one workspace.' }],
    },
  },
})
