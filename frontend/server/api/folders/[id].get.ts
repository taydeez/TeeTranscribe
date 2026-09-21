export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  const id = getRouterParam(event, 'id')

  return await $fetch(`${config.apiBase.replace(/\/$/, '')}/folders/${encodeURIComponent(id ?? '')}`, {
    headers: {
      Accept: 'application/json',
      ...(authorization ? { Authorization: authorization } : {}),
    },
    retry: 0,
  })
})
