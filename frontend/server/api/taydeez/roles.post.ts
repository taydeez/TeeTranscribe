import { forwardToBackend } from '../../utils/backend'
export default defineEventHandler(async (event) => {
  
  return await forwardToBackend(event, `admin/roles`, 'POST')
})
