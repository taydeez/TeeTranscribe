import type { FolderTranscription } from './transcription'

export type Folder = { id: string; name: string; transcriptionIds: string[]; createdAt: string | null; translationCount: number; dubbingCount: number }
export type FolderPage = { data: Folder[]; meta: { currentPage: number; lastPage: number; perPage: number; total: number } }
export type FolderProject = { id: string; name: string; type: 'translation' | 'dubbing' | 'subtitles'; status: 'pending' | 'processing' | 'complete' | 'failed'; sourceLanguage: string | null; targetLanguage: string; createdAt: string | null; mediaType: 'audio' | 'video' | null }
export type FolderDetails = Folder & { transcriptions: FolderTranscription[]; projects: FolderProject[] }
export type FolderOption = Pick<Folder, 'id' | 'name'>
export type FolderOptionPage = { data: FolderOption[]; meta: { currentPage: number; lastPage: number } }
