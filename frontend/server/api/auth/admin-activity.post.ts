import { forwardToBackend } from '../../utils/backend'

export default defineEventHandler(event => forwardToBackend(event, 'admin/session/activity'))
