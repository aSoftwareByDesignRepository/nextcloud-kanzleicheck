// @ts-check
/**
 * Login helper — reuses a cached session cookie jar per fixture user
 * (tests/e2e/helpers/session-cache.mjs) so the suite performs ONE real UI
 * login per user per run instead of one per test. Per-test UI logins trip
 * the Nextcloud brute-force throttle and self-DoS the whole suite
 * (e2e_suite_auth_self_dos). A bounced cached session falls back to the
 * form flow and re-caches; an active throttle page is cleared once via
 * occ-adjacent table reset and retried.
 */
import { loadSession, saveSession, clearLoginThrottle } from './session-cache.mjs'

const BASE = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>} true when the context already authenticates
 */
async function tryCachedSession(page, username) {
  const cookies = loadSession(username, BASE)
  if (!cookies) {
    return false
  }
  await page.context().addCookies(cookies)
  await page.goto('/', { waitUntil: 'domcontentloaded' })
  return !page.url().includes('/login')
}

async function uiLogin(page, { username, password }) {
  await page.goto('/login')
  await page.waitForLoadState('domcontentloaded')

  const userInput = page
    .getByRole('textbox', { name: /account name|email|benutzername|e-mail/i })
    .or(page.locator('#user'))
    .or(page.locator('input[name="user"]'))
    .first()
  const passInput = page
    .getByRole('textbox', { name: /^password$|^passwort$/i })
    .or(page.locator('#password'))
    .or(page.locator('input[name="password"]'))
    .first()

  await userInput.waitFor({ state: 'visible', timeout: 45000 })
  await userInput.fill(username)
  await passInput.fill(password)

  const submit = page
    .getByRole('button', { name: /^log in$|^anmelden$/i })
    .or(page.locator('button[type="submit"]'))
    .first()
  await submit.click()

  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 45000 })
  await page.waitForLoadState('domcontentloaded')
}

export async function login(page, { username, password }) {
  if (await tryCachedSession(page, username)) {
    return
  }
  try {
    await uiLogin(page, { username, password })
  } catch (err) {
    // Active brute-force throttle ("too many failed login attempts") —
    // clear the attempts table once and retry the form flow before
    // giving up; a sibling lane may have heated the same gateway IP.
    clearLoginThrottle()
    await uiLogin(page, { username, password })
  }
  await saveSession(username, BASE, await page.context().cookies())
}

export function credsFromEnv(role) {
  const u = process.env[`NC_${role}_USER`]
  const p = process.env[`NC_${role}_PASS`]
  if (!u || !p) {
    throw new Error(`Missing env vars NC_${role}_USER / NC_${role}_PASS`)
  }
  return { username: u, password: p }
}
