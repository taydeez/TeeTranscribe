import { forwardToBackend } from '../../../../utils/backend'

export default defineEventHandler(async (event) => {
  const type = getRouterParam(event, 'type') ?? ''
  const id = getRouterParam(event, 'id') ?? ''
  if (!['transcription', 'translation', 'dubbing', 'transcript_tool'].includes(type) || !/^[0-9a-z]{26}$/i.test(id)) throw createError({ statusCode: 404, message: 'Task not found.' })
  return forwardToBackend(event, `activities/${type}/${id}/cancel`)
})
