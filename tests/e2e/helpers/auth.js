// @ts-check
const BASE = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')

function hasCreds() {
	return !!(process.env.NC_ADMIN_USER && process.env.NC_ADMIN_PASS)
}

async function login(page, c) {
	const mod = await import('./auth-esm.mjs')
	return mod.login(page, c)
}

function creds() {
	return { username: process.env.NC_ADMIN_USER, password: process.env.NC_ADMIN_PASS }
}

module.exports = { login, hasCreds, creds, BASE }
