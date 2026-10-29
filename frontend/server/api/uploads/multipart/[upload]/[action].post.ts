export default defineEventHandler((event) => {
  const upload = getRouterParam(event, 'upload') ?? ''
  const action = getRouterParam(event, 'action') ?? ''
  if (!/^[0-9A-Z]{26}$/i.test(upload) || !['status', 'part', 'complete', 'abort'].includes(action)) {
    throw createError({ statusCode: 404, message: 'Upload endpoint not found.' })
  }
  return forwardToBackend(event, `uploads/multipart/${upload}/${action}`)
})
