export type UploadTicket = { upload_url: string; audio_url: string; audio_storage_path: string; headers: Record<string, string> }
export type TranscriptionSubmission = { id: string; status: string; message: string }
export type TranscriptionExport = { id: string; format: 'pdf' | 'txt'; status: 'pending' | 'failed' | 'completed'; downloadUrl: string | null }
export type TranscriptSegment = { start: number; end: number; speaker: string | null; text: string; confidence: number | null }
export type TranscriptUpdate = { id: string; transcript: string; status: string; segments: TranscriptSegment[] }
export type FolderTranscription = { id: string; name: string; fileName: string; status: string; transcript: string | null; duration: number | null; createdAt: string | null; exports: TranscriptionExport[]; provider?: string; segments?: TranscriptSegment[]; audioUrl?: string | null }
export type TranscriptionSource = 'file' | 'record' | 'url'
export type TranscriptionStage = 'idle' | 'preparing' | 'uploading' | 'submitting' | 'done'
