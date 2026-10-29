import { forwardToBackend } from '../../../utils/backend'
export default defineEventHandler(event => {
  const activity = getRouterParam(event, 'activity') ?? ''
  if (!/^[a-z_]+$/.test(activity)) throw createError({ statusCode: 400, message: 'Invalid activity.' })
  return forwardToBackend(event, 'admin/ai-providers/' + activity, 'PUT')
})
