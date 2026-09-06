/**
 * Test fixtures: a signed-in app with a stubbed API.
 *
 * `signIn()` seeds the auth token the portal would have returned and stubs the
 * seskey exchange, so the app boots straight into the shell without a redirect.
 * Nothing in the application is modified to make this work — the stubs sit at
 * the network layer, where a browser can intercept them.
 */

import { test as base, expect } from '@playwright/test'
import type { Page, Route } from '@playwright/test'

export interface StubNote {
  id: string
  title: string | null
  excerpt?: string
  note_type?: string
  is_pinned?: boolean
  is_favourite?: boolean
  is_archived?: boolean
  color?: string | null
  tags?: Array<{ id: string; name: string; slug: string; color: string | null }>
}

function noteSummary(note: StubNote) {
  const now = new Date().toISOString()
  return {
    id: note.id,
    note_type: note.note_type ?? 'document',
    title: note.title,
    display_title: note.title ?? note.excerpt?.split('\n')[0] ?? 'Untitled note',
    excerpt: note.excerpt ?? '',
    notebook_id: null,
    color: note.color ?? null,
    is_pinned: note.is_pinned ?? false,
    is_favourite: note.is_favourite ?? false,
    is_archived: note.is_archived ?? false,
    is_locked: false,
    privacy_mode: 'standard',
    version: 1,
    word_count: 0,
    char_count: 0,
    owner_user_id: 'user-test',
    created_at: now,
    updated_at: now,
    deleted_at: null,
    role: 'owner',
    is_shared: false,
    attachment_count: 0,
    has_reminder: false,
    checklist: null,
    tags: note.tags ?? [],
  }
}

function noteDetail(note: StubNote) {
  return {
    ...noteSummary(note),
    document: {
      type: 'doc',
      content: note.excerpt
        ? [{ type: 'paragraph', attrs: { blockId: '00000000-0000-4000-8000-000000000001' }, content: [{ type: 'text', text: note.excerpt }] }]
        : [],
    },
    document_schema_version: 1,
    content_hash: '',
    source: 'web',
    language: null,
    template_key: null,
    capabilities: {
      view: true, comment: true, edit: true,
      share: true, delete: true, restore: true, manage_members: true,
    },
    backlink_count: 0,
    actions: [],
  }
}

const ok = (data: unknown, meta?: unknown) =>
  JSON.stringify(meta ? { success: true, data, meta } : { success: true, data })

export interface ApiStubOptions {
  notes?: StubNote[]
  features?: Partial<Record<string, boolean>>
}

/**
 * Intercept the API. Anything not explicitly stubbed answers with an empty
 * success rather than a 404, so a screen under test is never derailed by an
 * endpoint that is merely incidental to it.
 */
export async function stubApi(page: Page, options: ApiStubOptions = {}): Promise<void> {
  const notes = options.notes ?? []

  // Anchored to the API base, NOT a `**/api/**` glob. That glob also matches the
  // app's own source modules — `/src/shared/api/client.ts` contains `/api/` —
  // so the dev server's JavaScript came back as JSON and the app never booted.
  await page.route(/\/api\/(?!.*\.[cm]?[jt]sx?(\?|$))/, async (route: Route) => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace(/^.*\/api/, '')
    const method = route.request().method()
    const json = (body: string, status = 200) =>
      route.fulfill({ status, contentType: 'application/json', body })

    if (path === '/config') {
      return json(ok({
        app: 'Notes',
        env: 'test',
        features: {
          ai: false, semantic_search: false, ocr: false, transcription: false,
          realtime: false, canvas: false, private_notes: false, drive: false,
          calendar: false, contacts: false, connect: false,
          ...options.features,
        },
        limits: { max_attachment_bytes: 26214400, trash_retention_days: 30 },
      }))
    }

    if (path === '/global/seskey') {
      return json(JSON.stringify({ ses_key: 'test-ses-key', expires_in: 900 }))
    }

    if (path === '/session') {
      return json(ok({
        authenticated: true, uuid: 'user-test', tenant_id: null,
        display_name: 'Test User', email: 'test@example.test',
      }))
    }

    if (path === '/notes/counts') {
      return json(ok({
        active: notes.length, archived: 0, trashed: 0,
        pinned: notes.filter((n) => n.is_pinned).length,
        favourite: 0, shared_with_me: 0,
      }))
    }

    if (path === '/notes' && method === 'GET') {
      const scope = url.searchParams.get('scope') ?? 'active'
      const visible = scope === 'trash' || scope === 'archive' ? [] : notes
      return json(ok(visible.map(noteSummary), { has_more: false, next_cursor: null }))
    }

    if (path === '/notes' && method === 'POST') {
      const body = route.request().postDataJSON() as { id?: string; title?: string | null }
      const created: StubNote = { id: body.id ?? 'created-note', title: body.title ?? null }
      notes.unshift(created)
      return json(ok(noteDetail(created)), 201)
    }

    const detailMatch = path.match(/^\/notes\/([^/]+)$/)
    if (detailMatch) {
      const found = notes.find((n) => n.id === detailMatch[1])
      if (!found) return json(ok(null), 404)
      if (method === 'PATCH') {
        const patch = route.request().postDataJSON() as { title?: string | null }
        if (patch.title !== undefined) found.title = patch.title
        return json(ok(noteDetail(found)))
      }
      return json(ok(noteDetail(found)))
    }

    if (path === '/notebooks') return json(ok([]))
    if (path === '/tags') return json(ok([]))
    if (path === '/smart-folders') return json(ok([]))
    if (path === '/templates') return json(ok([]))
    if (path === '/reminders') return json(ok([]))
    if (path === '/actions') return json(ok([]))
    if (path.startsWith('/search/')) return json(ok({ results: [], has_more: false }))

    return json(ok(null))
  })
}

/**
 * Bring the navigation on screen, whatever the viewport.
 *
 * Below 860px the sidebar is an off-canvas drawer and a closed drawer is
 * `visibility: hidden` — deliberately, so its links are out of the tab order
 * and out of the accessibility tree. That last part is why this helper is
 * needed even for a read-only assertion: a role query walks the accessibility
 * tree, so a link inside a closed drawer is not merely unclickable, it does not
 * resolve at all.
 */
export async function openNav(page: Page): Promise<void> {
  // The shell has to be on screen before "is the toggle showing?" is a real
  // question. Asked of a page that has not rendered yet the answer is always
  // no, the drawer is left closed, and the caller then waits out its timeout
  // for a link that a closed drawer keeps out of the accessibility tree.
  await page.getByRole('button', { name: /search anything/i }).waitFor()

  // Matches both states, because the button renames itself to "Hide
  // navigation" once the drawer is open.
  const toggle = page.getByRole('button', { name: /(show|hide) navigation/i })
  if (!(await toggle.isVisible())) {
    // Wide enough for the sidebar to be part of the layout: nothing to open.
    return
  }

  // Retried rather than clicked once: navigating closes the drawer, and a call
  // that arrives while that is still in flight would otherwise open it and
  // watch it shut again.
  const nav = page.getByRole('navigation', { name: /notes navigation/i })
  await expect(async () => {
    if (!(await nav.isVisible())) {
      await toggle.click()
    }
    await expect(nav).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 10_000 })
}

/**
 * Click a navigation link at whatever viewport the test is running, so one spec
 * exercises both layouts — which is the point of running the suite in two
 * projects.
 */
export async function goToSection(page: Page, name: string): Promise<void> {
  await openNav(page)

  // Substring matching, deliberately: a nav item carries its count inside the
  // link, so `My Notes` has the accessible name "My Notes 1" the moment the
  // library is not empty. Matching exactly would make the helper work only for
  // empty accounts.
  await page
    .getByRole('navigation', { name: /notes navigation/i })
    .getByRole('link', { name })
    .click()
}

export const test = base.extend<{ signIn: (options?: ApiStubOptions) => Promise<void> }>({
  signIn: async ({ page }, use) => {
    await use(async (options: ApiStubOptions = {}) => {
      await stubApi(page, options)
      // Seed the token the portal would have handed back, so AuthProvider does
      // not redirect away on boot.
      await page.addInitScript(() => {
        window.localStorage.setItem('auth_token', 'test-auth-token')
      })
      await page.goto('/')
    })
  },
})

export { expect }
