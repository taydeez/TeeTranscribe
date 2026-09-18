import { forwardToBackend } from '../utils/backend'

export default defineEventHandler(event => forwardToBackend(event, 'guest-sessions'))
