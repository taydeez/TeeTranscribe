import type { TranscriptSegment } from './transcription'

export type TranscriptToolOperation = 'cleanup' | 'summary'
export type TranscriptToolResult = { text?: string; segments?: TranscriptSegment[]; summary?: string; keyPoints?: string[]; actionItems?: string[] }
export type TranscriptTool = {
  id: string
  transcriptionId: string
  operation: TranscriptToolOperation
  status: 'pending' | 'processing' | 'complete' | 'failed'
  result: TranscriptToolResult | null
  failureReason: string | null
  createdAt: string | null
  stale: boolean
}
export type TranscriptToolsResponse = { configured: boolean; data: TranscriptTool[] }
export type TranscriptToolQuote = { id: string; status: 'ready'; quantity: number; credit_units: number; expires_at: string; available_units: number; enough_credits: boolean }
