import { forwardToBackend } from '../../../../utils/backend'

export default defineEventHandler(async (event) => {
  setHeader(event, 'Cache-Control', 'private, no-store')
  const id = getRouterParam(event, 'customerId')
  if (!id || !/^\d+$/.test(id)) throw createError({ statusCode: 404, message: 'Customer not found.' })
  return await forwardToBackend(event, `admin/customers/${id}/access`, 'PATCH')
})
