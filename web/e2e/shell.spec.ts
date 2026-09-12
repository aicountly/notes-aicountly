/**
 * The application frame: navigation, theme, search entry points, responsive
 * behaviour and offline. These exercise the shell, which is what every screen
 * sits inside — a break here breaks the whole product.
 *
 * Queries are by accessible role and name rather than by CSS class, so the tests
 * fail when the app becomes unusable rather than when a class is renamed.
 */

import { test, expect, goToSection, openNav } from './fixtures'

test.describe('shell', () => {
  test('signs in and shows the navigation', async ({ page, signIn }) => {
    await signIn()

    // On a phone the navigation is behind the toggle, so getting to it is part
    // of what this asserts — a drawer that cannot be opened is not navigation.
    await openNav(page)

    const nav = page.getByRole('navigation', { name: /notes navigation/i })
    await expect(nav).toBeVisible()
    await expect(nav.getByRole('link', { name: 'Home' })).toBeVisible()
    await expect(nav.getByRole('link', { name: 'My Notes' })).toBeVisible()
    await expect(nav.getByRole('link', { name: 'Trash' })).toBeVisible()
  })

  test('marks the current section for screen readers, not just visually', async ({ page, signIn }) => {
    await signIn()

    await goToSection(page, 'My Notes')

    // aria-current is what a screen reader announces; a green background alone
    // tells a non-sighted user nothing. On a phone the drawer closes behind the
    // navigation and takes its links out of the accessibility tree, so it is
    // reopened here — reading the mark the way assistive technology would.
    await openNav(page)
    await expect(page.getByRole('link', { name: 'My Notes' })).toHaveAttribute(
      'aria-current',
      'page',
    )
  })

  test('opens search from the header and from the keyboard', async ({ page, signIn }) => {
    await signIn()

    await page.getByRole('button', { name: /search anything/i }).click()
    await expect(page.getByRole('dialog')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toBeHidden()

    // Cmd/Ctrl+K is the command palette, which is a different surface.
    await page.keyboard.press('ControlOrMeta+k')
    await expect(page.getByRole('dialog')).toBeVisible()
  })

  test('the theme choice survives a reload', async ({ page, signIn }) => {
    await signIn()

    await page.getByRole('button', { name: /account and settings/i }).click()
    await page.getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

    await page.reload()
    // Applied by the inline script before the first paint, so there is no flash
    // of the wrong theme.
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  })

  test('says so when the connection is gone', async ({ page, signIn, context }) => {
    await signIn({ notes: [{ id: 'n1', title: 'Existing note', excerpt: 'body' }] })
    await goToSection(page, 'My Notes')

    await context.setOffline(true)
    await goToSection(page, 'Home')

    await expect(page.getByRole('button', { name: /offline/i })).toBeVisible({ timeout: 10_000 })
  })

  test('has a skip link for keyboard users', async ({ page, signIn }) => {
    await signIn()
    // Tab only means anything once the app has rendered something to tab
    // through; pressing it against an empty document focuses nothing.
    await expect(page.getByRole('button', { name: /search anything/i })).toBeVisible()

    await page.keyboard.press('Tab')
    await expect(page.getByRole('link', { name: /skip to content/i })).toBeFocused()
  })
})

test.describe('shell on a phone', () => {
  test.use({ viewport: { width: 390, height: 844 } })

  test('navigation is a drawer that closes after choosing', async ({ page, signIn }) => {
    await signIn()

    const nav = page.getByRole('navigation', { name: /notes navigation/i })
    const toggle = page.getByRole('button', { name: /show navigation/i })

    await expect(toggle).toBeVisible()
    await toggle.click()
    await expect(nav.getByRole('link', { name: 'My Notes' })).toBeVisible()

    await nav.getByRole('link', { name: 'My Notes' }).click()
    // Leaving the drawer open would cover the page the user just asked for.
    await expect(page.getByRole('button', { name: /show navigation/i })).toBeVisible()
  })

  test('the page never scrolls sideways', async ({ page, signIn }) => {
    await signIn({ notes: [{ id: 'n1', title: 'A note with a fairly long title that could overflow', excerpt: 'x'.repeat(400) }] })
    await goToSection(page, 'My Notes')

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    )
    expect(overflows).toBe(false)
  })
})
