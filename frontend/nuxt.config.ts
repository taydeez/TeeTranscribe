// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  runtimeConfig: {
    apiBase: 'http://127.0.0.1:8000/api/v1',
  },
  app: {
    head: {
      title: 'TeeTranscribe · Audio to words',
      meta: [{ name: 'description', content: 'Upload your audio and send it for transcription.' }],
    },
  },
})
