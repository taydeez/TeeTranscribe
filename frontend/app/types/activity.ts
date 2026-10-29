export type ActivityType = 'transcription' | 'translation' | 'dubbing' | 'transcript_tool'
export type ActivityCancellation = { id: string; status: string; cancelledAt: string | null; failureReason: string | null }
