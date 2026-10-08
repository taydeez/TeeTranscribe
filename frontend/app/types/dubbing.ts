export type DubbingLanguage = { code: string; name: string; nigerian: boolean }
export type DubbingCatalog = { data: DubbingLanguage[]; configured: boolean }
export type DubbingQuote = { id: string; status: 'measuring' | 'ready' | 'failed' | 'submitted'; quantity: number | null; credit_units: number | null; expires_at: string; failure_reason: string | null; available_units: number; enough_credits: boolean }
export type DubbingSummary = { id: string; name: string; sourceLanguage: string | null; targetLanguage: string; durationMs: number; status: 'pending' | 'processing' | 'complete' | 'failed'; createdAt: string; failureReason: string | null; canRetryExports: boolean }
export type DubbingRecord = DubbingSummary & { videoUrl: string | null; videoDownloadUrl: string | null; audioDownloadUrl: string | null }
export type DubbingHistory = { data: DubbingSummary[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }
