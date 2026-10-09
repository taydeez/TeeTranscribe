import type { TranscriptSegment, TranscriptionExportVariant } from './transcription'

export type TranslationLanguage = { code: string; name: string; nigerian: boolean }
export type TranslationQuote = { id: string; status: string; quantity: number; credit_units: number; expires_at: string; available_units: number; enough_credits: boolean }
export type TranslationSummary = { id: string; name: string; folderId: string | null; status: 'pending' | 'processing' | 'complete' | 'failed'; sourceLanguage: string | null; detectedLanguage: string | null; targetLanguage: string; transcriptionId: string | null; createdAt: string; failureReason: string | null }
export type TranslationExport = { format: 'pdf' | 'txt' | 'docx'; variant: TranscriptionExportVariant; status: string; downloadUrl: string | null }
export type TranslationRecord = TranslationSummary & { sourceText: string; translatedText: string | null; segments: TranscriptSegment[]; exportRevision: number; exports: TranslationExport[] }
export type TranslationHistoryPage = { data: TranslationSummary[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }
