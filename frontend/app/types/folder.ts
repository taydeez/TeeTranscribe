import type { FolderTranscription } from './transcription'

export type Folder = { id: string; name: string; transcriptionIds: string[]; createdAt: string | null }
export type FolderPage = { data: Folder[]; meta: { currentPage: number; lastPage: number; perPage: number; total: number } }
export type FolderDetails = Folder & { transcriptions: FolderTranscription[] }
export type FolderOption = Pick<Folder, 'id' | 'name'>
export type FolderOptionPage = { data: FolderOption[]; meta: { currentPage: number; lastPage: number } }
