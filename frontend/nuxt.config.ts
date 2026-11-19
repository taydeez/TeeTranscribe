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
      script: [{
        key: 'google-tag-manager',
        tagPosition: 'head',
        tagPriority: 'high',
        innerHTML: "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-WVPDC4HR');",
      }],
      noscript: [{
        key: 'google-tag-manager-noscript',
        tagPosition: 'bodyOpen',
        innerHTML: '<iframe src="https://www.googletagmanager.com/ns.html?id=GTM-WVPDC4HR" height="0" width="0" style="display:none;visibility:hidden"></iframe>',
      }],
      link: [
        { rel: 'preload', as: 'font', type: 'font/woff2', href: '/fonts/manrope-latin.woff2', crossorigin: 'anonymous' },
        { rel: 'preload', as: 'font', type: 'font/woff2', href: '/fonts/inter-latin.woff2', crossorigin: 'anonymous' },
        { rel: 'icon', type: 'image/png', href: '/favicon.png' },
      ],
      meta: [{ name: 'description', content: 'Transcribe audio, dub videos, and translate text with TeeScriber. Sign up for 10 free credits and keep your projects together in one workspace.' }],
    },
  },
})
