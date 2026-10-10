import { adminRead } from '../../utils/adminRead'
export default defineEventHandler(event => adminRead(event, 'roles'))
