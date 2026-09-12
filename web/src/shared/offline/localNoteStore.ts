/**
 * The device's copy of the user's notes.
 *
 * A notes app that shows nothing on a train is not a notes app. Every note the
 * user opens or lists is mirrored here, so a cold start with no network still
 * renders the library and opens a note for editing.
 *
 * Local rows carry two extra fields the server knows nothing about:
 *   `dirty`      — 1 when this device has unsent changes for the note
 *   `local_updated_at` — when this device last touched it
 *
 * `dirty` is what stops a background refresh from overwriting an edit that has
 * not reached the server yet.
 */

import { idb, meta, STORE_NOTES, STORE_QUEUE } from './db'
import type { Note, NoteSummary } from '../api/types'

/**
 * A cached note. Always has the summary fields a card needs; carries the full
 * document only once the note has actually been opened, so listing a library
 * does not force every document into the cache.
 */
export type LocalNote = NoteSummary &
  Partial<Omit<Note, keyof NoteSummary>> & {
    /** 1 = has unsent local changes. Numeric because IndexedDB cannot index booleans. */
    dirty?: 0 | 1
    local_updated_at?: string
  }

const SYNC_CURSOR_KEY = 'sync:cursor'
const OWNER_KEY = 'sync:user'

export const localNoteStore = {
  async get(id: string): Promise<LocalNote | null> {
    return idb.get<LocalNote>(STORE_NOTES, id)
  },

  async all(): Promise<LocalNote[]> {
    const notes = await idb.getAll<LocalNote>(STORE_NOTES)
    return notes.sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''))
  },

  /** The offline list: live notes, newest first, archived and trashed excluded. */
  async list(): Promise<LocalNote[]> {
    return (await this.all()).filter((note) => !note.deleted_at && !note.is_archived)
  },

  async save(note: LocalNote): Promise<void> {
    await idb.put(STORE_NOTES, note)
  },

  /**
   * Write server rows into the cache without clobbering unsent work.
   *
   * A note flagged dirty keeps its local copy: the server's version is older
   * than what the user has typed, and taking it would be exactly the silent
   * data loss the sync design exists to prevent.
   */
  async mergeFromServer(notes: NoteSummary[] | Note[]): Promise<void> {
    const existing = new Map((await this.all()).map((note) => [note.id, note]))

    const merged = notes.map((incoming) => {
      const local = existing.get(incoming.id)
      if (local?.dirty === 1) return local

      const next: LocalNote = {
        ...local,
        ...incoming,
        dirty: 0 as const,
        local_updated_at: local?.local_updated_at,
      }

      // A summary carries `version` but not `document` — only the detail row
      // does — so this spread would otherwise take the server's new version
      // number and keep the old cached body underneath it. That row then reads
      // as a note on version 5 whose text is version 4's, and the offline open
      // path hands it to the editor as a full note. The next save carries
      // version 5 with a document that never contained the other person's
      // paragraph, the optimistic lock passes, and their work is gone with no
      // 409 and no conflict banner — the exact silent loss the version
      // mechanism exists to prevent.
      //
      // So the body is dropped whenever the version moved. The note stays
      // cached and listable; it simply stops claiming to hold a document it
      // cannot vouch for, and opening it offline says so rather than editing
      // the wrong text.
      if (
        local?.document !== undefined &&
        !('document' in incoming) &&
        local.version !== incoming.version
      ) {
        delete next.document
        delete next.content_hash
      }

      return next
    })

    await idb.putMany(STORE_NOTES, merged)
  },

  async markDirty(id: string, patch: Partial<LocalNote>): Promise<LocalNote | null> {
    const existing = await this.get(id)
    if (!existing) return null

    const updated: LocalNote = {
      ...existing,
      ...patch,
      dirty: 1,
      local_updated_at: new Date().toISOString(),
    }
    await this.save(updated)
    return updated
  },

  async markClean(id: string): Promise<void> {
    const existing = await this.get(id)
    if (existing) await this.save({ ...existing, dirty: 0 })
  },

  async dirtyNotes(): Promise<LocalNote[]> {
    return idb.byIndex<LocalNote>(STORE_NOTES, 'dirty', 1)
  },

  async remove(id: string): Promise<void> {
    await idb.delete(STORE_NOTES, id)
  },

  async cursor(): Promise<string | null> {
    return meta.get<string>(SYNC_CURSOR_KEY)
  },

  async setCursor(value: string): Promise<void> {
    await meta.set(SYNC_CURSOR_KEY, value)
  },

  /**
   * Drop this device's offline state when a different user signs in.
   *
   * The cache is the obvious half: without clearing it, one person's notes
   * would be readable offline by the next person to use the same browser
   * profile.
   *
   * **The queue is the half that matters more.** An unsent operation carries
   * no identity of its own — the engine drains it with whatever session is
   * signed in when the network returns. Left behind across a user switch, the
   * first person's unsent note is created, and their unsent edits are written,
   * *inside the second person's account*: their words under someone else's
   * name, and gone from their own. On a shared machine that is the worst
   * outcome this store can produce, so it is cleared in the same breath as the
   * notes.
   *
   * Clearing rather than draining first is deliberate. Draining would need the
   * previous user's session, which is exactly what has just gone away.
   */
  async ensureOwner(userId: string): Promise<void> {
    const previous = await meta.get<string>(OWNER_KEY)
    if (previous && previous !== userId) {
      await idb.clear(STORE_NOTES)
      await idb.clear(STORE_QUEUE)
      await meta.set(SYNC_CURSOR_KEY, '')
    }
    if (previous !== userId) await meta.set(OWNER_KEY, userId)
  },
}
