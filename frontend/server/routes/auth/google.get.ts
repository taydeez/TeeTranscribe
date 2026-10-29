export default defineEventHandler((event) => {
  const config = useRuntimeConfig(event)
  return sendRedirect(event, `${config.apiBase.replace(/\/$/, '')}/auth/google/redirect`)
})
