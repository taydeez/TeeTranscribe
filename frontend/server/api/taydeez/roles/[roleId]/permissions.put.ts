import { forwardToBackend } from '../../../../utils/backend'
export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'roleId')
  if (!id || !/^\d+$/.test(id)) throw createError({ statusCode: 404, message: 'Record not found.' })
  return await forwardToBackend(event, `admin/roles/${id}/permissions`, 'PUT')
})
