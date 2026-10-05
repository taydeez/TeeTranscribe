import type { SavedUpload } from '~/types/upload'

function database(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('teetranscribe-uploads', 1)
    request.onupgradeneeded = () => request.result.createObjectStore('uploads', { keyPath: 'key' })
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(new Error('Enable browser storage to save resumable uploads.'))
  })
}

async function operation<T>(mode: IDBTransactionMode, perform: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await database()
  return new Promise((resolve, reject) => {
    const transaction = db.transaction('uploads', mode)
    const request = perform(transaction.objectStore('uploads'))
    transaction.oncomplete = () => { db.close(); resolve(request.result) }
    transaction.onerror = transaction.onabort = () => {
      db.close()
      reject(new Error('Could not save upload progress in this browser.'))
    }
  })
}

export async function savedUploads(userId: number): Promise<SavedUpload[]> {
  const records = await operation('readonly', store => store.getAll()) as SavedUpload[]
  return records.filter(record => record.userId === userId).sort((a, b) => b.updatedAt - a.updatedAt)
}
export function saveUpload(record: SavedUpload) {
  return operation('readwrite', store => store.put(record))
}
export function forgetUpload(key: string) {
  return operation('readwrite', store => store.delete(key))
}

/** A small sampled digest identifies a reselected file without reading gigabytes into memory. */
export async function fileFingerprint(file: File): Promise<string> {
  const sample = 64 * 1024
  const middle = Math.max(0, Math.floor(file.size / 2) - sample / 2)
  const data = await new Blob([
    JSON.stringify([file.name, file.size]),
    file.slice(0, sample),
    file.slice(middle, middle + sample),
    file.slice(Math.max(0, file.size - sample)),
  ]).arrayBuffer()
  const hash = await crypto.subtle.digest('SHA-256', data)
  return Array.from(new Uint8Array(hash), byte => byte.toString(16).padStart(2, '0')).join('')
}
