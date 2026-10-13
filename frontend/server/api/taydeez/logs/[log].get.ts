import { adminRead } from '../../../utils/adminRead'
export default defineEventHandler(event => adminRead(event, `logs/${encodeURIComponent(getRouterParam(event, 'log') ?? '')}`))
