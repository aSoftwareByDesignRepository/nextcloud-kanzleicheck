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
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
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
