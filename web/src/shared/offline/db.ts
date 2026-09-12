/**
 * IndexedDB, hand-rolled.
 *
 * localStorage is the wrong store for this: it is synchronous (so writing a
 * note blocks the editor's frame), string-only (so every note is stringified
 * twice), and capped at a few megabytes — which a hundred notes with inline
 * images will exceed. IndexedDB is none of those things.
 *
 * A wrapper rather than a library because the surface actually needed is small,
 * and a dependency here would ship a second promise abstraction into a bundle
 * that already has one.
 */

const DB_NAME = 'aicountly-notes'
const DB_VERSION = 1

export const STORE_NOTES = 'notes'
export const STORE_QUEUE = 'sync-queue'
export const STORE_META = 'meta'

let dbPromise: Promise<IDBDatabase | null> | null = null

/**
 * Open the database, or resolve `null` when it is unavailable.
 *
 * Private-mode Safari and hardened browser profiles reject IndexedDB outright.
 * That is a reason to lose offline support, never a reason for the app not to
 * load — so every caller here treats `null` as "no local cache" and carries on
 * talking to the server.
 */
export function openDatabase(): Promise<IDBDatabase | null> {
  if (dbPromise) return dbPromise

  dbPromise = new Promise((resolve) => {
    if (typeof indexedDB === 'undefined') {
      resolve(null)
      return
    }

    let request: IDBOpenDBRequest
    try {
      request = indexedDB.open(DB_NAME, DB_VERSION)
    } catch {
      resolve(null)
      return
    }

    request.onupgradeneeded = () => {
      const db = request.result

      if (!db.objectStoreNames.contains(STORE_NOTES)) {
        const notes = db.createObjectStore(STORE_NOTES, { keyPath: 'id' })
        notes.createIndex('updated_at', 'updated_at')
        notes.createIndex('notebook_id', 'notebook_id')
        // Locally-modified notes are the sync queue's working set.
        notes.createIndex('dirty', 'dirty')
      }

      if (!db.objectStoreNames.contains(STORE_QUEUE)) {
        const queue = db.createObjectStore(STORE_QUEUE, { keyPath: 'operation_id' })
        queue.createIndex('created_at', 'created_at')
        queue.createIndex('entity_id', 'entity_id')
      }

      if (!db.objectStoreNames.contains(STORE_META)) {
        db.createObjectStore(STORE_META, { keyPath: 'key' })
      }
    }

    request.onsuccess = () => {
      const db = request.result
      // A second tab running a newer version needs this one to let go, or its
      // upgrade blocks forever.
      db.onversionchange = () => db.close()
      resolve(db)
    }

    request.onerror = () => resolve(null)
    request.onblocked = () => resolve(null)
  })

  return dbPromise
}

function promisify<T>(request: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('IndexedDB request failed'))
  })
}

async function withStore<T>(
  storeName: string,
  mode: IDBTransactionMode,
  work: (store: IDBObjectStore) => Promise<T> | T,
): Promise<T | null> {
  const db = await openDatabase()
  if (!db) return null

  try {
    const transaction = db.transaction(storeName, mode)
    const result = await work(transaction.objectStore(storeName))

    if (mode === 'readwrite') {
      await new Promise<void>((resolve, reject) => {
        transaction.oncomplete = () => resolve()
        transaction.onerror = () => reject(transaction.error ?? new Error('transaction failed'))
        transaction.onabort = () => reject(transaction.error ?? new Error('transaction aborted'))
      })
    }

    return result
  } catch {
    // A quota error or a closed connection. The caller falls back to the
    // network; nothing here is worth failing a render over.
    return null
  }
}

export const idb = {
  async get<T>(store: string, key: IDBValidKey): Promise<T | null> {
    const value = await withStore(store, 'readonly', (s) => promisify<T | undefined>(s.get(key)))
    return value ?? null
  },

  async getAll<T>(store: string, count?: number): Promise<T[]> {
    const value = await withStore(store, 'readonly', (s) => promisify<T[]>(s.getAll(undefined, count)))
    return value ?? []
  },

  async put<T>(store: string, value: T): Promise<boolean> {
    const result = await withStore(store, 'readwrite', (s) => promisify(s.put(value as never)))
    return result !== null
  },

  async putMany<T>(store: string, values: T[]): Promise<boolean> {
    if (values.length === 0) return true
    const result = await withStore(store, 'readwrite', async (s) => {
      for (const value of values) s.put(value as never)
      return true
    })
    return result !== null
  },

  async delete(store: string, key: IDBValidKey): Promise<void> {
    await withStore(store, 'readwrite', (s) => promisify(s.delete(key)))
  },

  async clear(store: string): Promise<void> {
    await withStore(store, 'readwrite', (s) => promisify(s.clear()))
  },

  async count(store: string): Promise<number> {
    return (await withStore(store, 'readonly', (s) => promisify(s.count()))) ?? 0
  },

  /** Rows from an index, oldest first. Used to drain the queue in order. */
  async byIndex<T>(store: string, indexName: string, query?: IDBValidKey | IDBKeyRange): Promise<T[]> {
    const value = await withStore(store, 'readonly', (s) =>
      promisify<T[]>(s.index(indexName).getAll(query)),
    )
    return value ?? []
  },
}

/** Small key/value corner: the sync cursor, the last-seen user. */
export const meta = {
  async get<T>(key: string): Promise<T | null> {
    const row = await idb.get<{ key: string; value: T }>(STORE_META, key)
    return row?.value ?? null
  },
  async set<T>(key: string, value: T): Promise<void> {
    await idb.put(STORE_META, { key, value })
  },
}
