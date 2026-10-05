import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  const query = getQuery(event)

  return await ofetch(`${config.apiBase.replace(/\/$/, '')}/folders`, {
    query,
    headers: {
      Accept: 'application/json',
      ...(authorization ? { Authorization: authorization } : {}),
    },
    retry: 0,
  })
})
