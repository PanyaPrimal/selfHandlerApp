import { toRaw } from 'vue'

export interface LocalEntry { key: string; value: unknown }
export interface LocalMutation<T> { put?: LocalEntry[]; remove?: string[]; result: T }

/** Read and replace related metadata in one durable transaction, without reading audio blobs. */
export async function mutateLocalEntries<T>(prefixes: string[], update: (entries: LocalEntry[]) => LocalMutation<T>): Promise<T> {
  const db = await database()
  return new Promise((resolve, reject) => {
    const tx = db.transaction('entries', 'readwrite')
    const store = tx.objectStore('entries')
    const entries = new Map<string, LocalEntry>()
    let remaining = prefixes.length
    let result: T
    let failure: unknown
    const commit = () => {
      try {
        const mutation = update([...entries.values()])
        for (const key of [...(mutation.put ?? []).map(entry => entry.key), ...(mutation.remove ?? [])]) {
          if (!prefixes.some(prefix => key.startsWith(prefix))) throw new Error('Local transaction escaped its account scope.')
        }
        for (const entry of mutation.put ?? []) store.put(unwrapped(entry.value), entry.key)
        for (const key of mutation.remove ?? []) store.delete(key)
        result = mutation.result
      } catch (error) { failure = error; tx.abort() }
    }
    for (const prefix of prefixes) {
      const request = store.openCursor(IDBKeyRange.bound(prefix, `${prefix}\uffff`))
      request.onsuccess = () => {
        const cursor = request.result
        if (cursor) { entries.set(String(cursor.key), { key: String(cursor.key), value: cursor.value }); cursor.continue() }
        else if (--remaining === 0) commit()
      }
    }
    if (!remaining) commit()
    tx.oncomplete = () => resolve(result!)
    tx.onabort = tx.onerror = () => reject(failure ?? tx.error ?? new Error('Local transaction failed.'))
  })
}

/** Durable structured-clone store. Keys include account identity; never store credentials here. */
let opening: Promise<IDBDatabase> | null = null

function database(): Promise<IDBDatabase> {
  if (!opening) opening = new Promise((resolve, reject) => {
    const request = indexedDB.open('selfhandler-personal-v1', 1)
    request.onupgradeneeded = () => request.result.createObjectStore('entries')
    request.onsuccess = () => { request.result.onversionchange = () => { request.result.close(); opening = null }; resolve(request.result) }
    request.onerror = () => { opening = null; reject(request.error) }
    request.onblocked = () => { opening = null; reject(new Error('Storage upgrade is blocked by another window.')) }
  })
  return opening
}

export async function localRead<T>(key: string): Promise<T | undefined> {
  const db = await database()
  return new Promise((resolve, reject) => {
    const tx = db.transaction('entries', 'readonly')
    const request = tx.objectStore('entries').get(key)
    request.onsuccess = () => resolve(request.result as T | undefined)
    request.onerror = () => reject(request.error)
  })
}

export async function localWrite(key: string, value: unknown): Promise<void> {
  const db = await database()
  return new Promise((resolve, reject) => {
    const tx = db.transaction('entries', 'readwrite')
    tx.objectStore('entries').put(unwrapped(value), key)
    tx.oncomplete = () => resolve()
    tx.onabort = tx.onerror = () => reject(tx.error ?? new Error('Local save failed.'))
  })
}

function unwrapped(value: unknown): unknown {
  if (value === null || typeof value !== 'object') return value
  const raw = toRaw(value)
  if (raw instanceof Blob || raw instanceof Date || raw instanceof ArrayBuffer) return raw
  if (Array.isArray(raw)) return raw.map(unwrapped)
  return Object.fromEntries(Object.entries(raw).map(([key, entry]) => [key, unwrapped(entry)]))
}

export async function localRemove(key: string): Promise<void> {
  const db = await database()
  return new Promise((resolve, reject) => {
    const tx = db.transaction('entries', 'readwrite')
    tx.objectStore('entries').delete(key)
    tx.oncomplete = () => resolve()
    tx.onabort = tx.onerror = () => reject(tx.error)
  })
}

export async function localEntries<T>(prefix: string): Promise<Array<{ key: string; value: T }>> {
  const db = await database()
  return new Promise((resolve, reject) => {
    const tx = db.transaction('entries', 'readonly')
    const output: Array<{ key: string; value: T }> = []
    const request = tx.objectStore('entries').openCursor(IDBKeyRange.bound(prefix, `${prefix}\uffff`))
    request.onsuccess = () => {
      const cursor = request.result
      if (cursor) { output.push({ key: String(cursor.key), value: cursor.value as T }); cursor.continue() }
      else resolve(output)
    }
    request.onerror = () => reject(request.error)
  })
}
