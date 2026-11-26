// @ts-check
/**
 * e2e session cookie cache — one UI login per fixture user per run, not
 * one per test. Class: e2e_suite_auth_self_dos — a suite that POSTs
 * /login ~100x per run out-rate-limits the very system it tests
 * (Nextcloud brute-force throttle: "Too many failed login attempts").
 *
 * Contract (see COMPANION-DESIGN-SYSTEM e2e_suite_auth_self_dos):
 * globalSetup clears the throttle tables; the first login() per user
 * performs the real UI login and caches the resulting cookie jar in
 * $TMPDIR (atomic tmp+rename); every later login() on a fresh context
 * re-adds the jar and validates it once — a bounced session falls back
 * to the UI flow and re-caches.
 *
 * Cache is per (baseURL, user) so parallel app lanes never share a file.
 */
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, renameSync, rmdirSync, statSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const COMPOSE_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..', '..')
const CACHE_DIR = join(tmpdir(), 'dc-e2e-sessions')

function cachePath(username, baseURL) {
	const key = createHash('sha256').update(`${baseURL}|${username}`).digest('hex').slice(0, 16)
	return join(CACHE_DIR, `${key}.json`)
}

/** @returns {Array<Record<string, unknown>> | null} stored cookies or null */
export function loadSession(username, baseURL) {
	try {
		const path = cachePath(username, baseURL)
		if (!existsSync(path)) {
			return null
		}
		const cookies = JSON.parse(readFileSync(path, 'utf8'))
		return Array.isArray(cookies) && cookies.length > 0 ? cookies : null
	} catch {
		return null
	}
}

/** @param {Array<Record<string, unknown>>} cookies */
export function saveSession(username, baseURL, cookies) {
	try {
		mkdirSync(CACHE_DIR, { recursive: true })
		const path = cachePath(username, baseURL)
		const tmp = `${path}.${process.pid}.tmp`
		writeFileSync(tmp, JSON.stringify(cookies))
		renameSync(tmp, path)
	} catch {
		// cache is best-effort — a write failure must never fail a spec
	}
}

/**
 * Clear the brute-force throttle tables on the shared instance. Attempts
 * accrue to the docker gateway IP the host browser arrives from; a clean
 * slate lets the first UI login per user succeed instead of timing out on
 * "try again in 30 seconds". Idempotent; safe under the shared instance
 * lock (every lane wants the same start state).
 */
export function clearLoginThrottle() {
	spawnSync(
		'docker',
		['compose', 'exec', '-T', 'mariadb', 'bash', '-c',
			'mysql -u nextcloud -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "DELETE FROM oc_bruteforce_attempts"'],
		{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
	)
}

/**
 * Pin the fixture user's UI locale to the suite baseline (en). Locale is
 * shared mutable fixture state: specs that deliberately flip core/lang
 * restore it in `finally`, but an aborted run leaves it stuck — the next
 * run's English-pinned selectors then die on translated chrome (seen live:
 * admin stuck on `nl`, `h2:has-text("Desktop sync")` never matched
 * "Desktopsynchronisatie"). Idempotent, ~1s, best-effort (the suite dies
 * anyway when docker is gone). Specs that need another locale set it
 * AFTER login() — lang is read per request.
 */
const LANG_LOCK = join(CACHE_DIR, 'lang-mutators.lock')
const LANG_LOCK_STALE_MS = 180_000

function lockStale() {
	try {
		return Date.now() - statSync(LANG_LOCK).mtimeMs > LANG_LOCK_STALE_MS
	} catch {
		return true // no lock = treat as free
	}
}

/**
 * Serialize every writer of shared `core/lang` fixture state — parallel
 * workers each flipping the same user's locale interleave and render the
 * wrong catalog mid-assert (l10n-sweep vs transfer-plural race). Atomic
 * mkdir lock + stale reclaim for crashed holders.
 */
export async function acquireLangLock(timeoutMs = 120_000) {
	mkdirSync(CACHE_DIR, { recursive: true })
	const start = Date.now()
	for (;;) {
		if (lockStale()) {
			try { rmdirSync(LANG_LOCK) } catch { /* not present / race */ }
		}
		try {
			mkdirSync(LANG_LOCK)
			return
		} catch {
			if (Date.now() - start > timeoutMs) {
				throw new Error('lang-mutators lock timeout — a parallel spec is holding it')
			}
			await new Promise((r) => setTimeout(r, 250))
		}
	}
}

export function releaseLangLock() {
	try { rmdirSync(LANG_LOCK) } catch { /* already gone */ }
}

export async function pinLocale(username, lang = 'en') {
	await acquireLangLock()
	try {
		spawnSync(
			'docker',
			['compose', 'exec', '-T', '-u', 'www-data', 'nextcloud',
				'php', 'occ', 'user:setting', username, 'core', 'lang', lang],
			{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
		)
	} finally {
		releaseLangLock()
	}
}
