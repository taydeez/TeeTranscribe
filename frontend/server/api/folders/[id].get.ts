import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  const id = getRouterParam(event, 'id')

  return await ofetch(`${config.apiBase.replace(/\/$/, '')}/folders/${encodeURIComponent(id ?? '')}`, {
    headers: {
      Accept: 'application/json',
      ...(authorization ? { Authorization: authorization } : {}),
    },
    retry: 0,
  })
})
