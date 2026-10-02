export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  const id = getRouterParam(event, 'id')
  const body = await readBody(event)

  try {
    return await $fetch(`${config.apiBase.replace(/\/$/, '')}/transcriptions/${encodeURIComponent(id ?? '')}`, {
      method: 'PATCH',
      body,
      headers: {
        Accept: 'application/json',
        ...(authorization ? { Authorization: authorization } : {}),
      },
      retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({
      statusCode: failure.statusCode ?? 502,
      message: failure.data?.message ?? 'The transcript could not be saved.',
    })
  }
})
