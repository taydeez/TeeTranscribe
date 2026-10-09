export type MultipartSession = {
  id: string
  status: 'uploading' | 'completed' | 'aborted'
  filename: string
  size: number
  part_size: number
  part_count: number
  expires_at: string
}
export type UploadedPart = { number: number; size: number; etag: string }
export type MultipartStatus = MultipartSession & { parts: UploadedPart[] }
export type PartTicket = { upload_url: string; headers: Record<string, string> }
export type CompletedUpload = { audio_url: string; audio_storage_path: string }
export type SavedUpload = {
  key: string
  userId: number
  clientKey: string
  fingerprint: string
  filename: string
  size: number
  contentType: string
  sourceKind?: 'audio' | 'video' | 'recording'
  sessionId?: string
  expiresAt?: string
  updatedAt: number
}
