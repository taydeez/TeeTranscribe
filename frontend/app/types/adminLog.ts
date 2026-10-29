export interface OperationalLog {
  id: string
  type: string
  level: string
  message: string
  operation_type: string | null
  operation_id: string | null
  provider: string | null
  provider_request_id: string | null
  job_id: string | null
  elapsed_ms: number | null
  created_at: string
  context?: Record<string, unknown>
}
export interface OperationalLogList {
  data: OperationalLog[]
  meta: { currentPage: number; lastPage: number; perPage: number; total: number }
}
