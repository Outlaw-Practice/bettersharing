import { expect, request } from '@playwright/test'
import { readFileSync } from 'node:fs'

const seed = Object.fromEntries(readFileSync(new URL('../dev/seed.env', import.meta.url), 'utf8')
	.split('\n').filter(Boolean).map((l) => l.split('=')))

export const PASSWORD = seed.USER_PASSWORD
export const BASE = 'http://127.0.0.1:8191'
export const MAILPIT = 'http://127.0.0.1:8125'

export async function login(page, user = 'alice') {
	await page.goto('/index.php/login')
	await page.getByLabel('Account name or email').fill(user)
	await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await page.waitForURL(/apps\//)
}

export async function api(user = 'alice') {
	return request.newContext({
		baseURL: BASE,
		httpCredentials: { username: user, password: PASSWORD },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
}

/** Creates a folder with one file over WebDAV and returns its file ID and name. */
export async function makeFolder(user, name) {
	const ctx = await api(user)
	const url = `/remote.php/dav/files/${user}/${encodeURIComponent(name)}`
	expect((await ctx.fetch(url, { method: 'MKCOL' })).status()).toBe(201)
	await ctx.put(`${url}/inside.txt`, { data: 'test content' })
	const res = await ctx.fetch(url, {
		method: 'PROPFIND',
		headers: { Depth: '0' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const fileid = Number((await res.text()).match(/<oc:fileid>(\d+)<\/oc:fileid>/)[1])
	return { fileid, name, ctx }
}

export async function clearMail() {
	const ctx = await request.newContext()
	await ctx.delete(`${MAILPIT}/api/v1/messages`)
}

export async function mailTo(address) {
	const ctx = await request.newContext()
	const data = await (await ctx.get(`${MAILPIT}/api/v1/messages?limit=200`)).json()
	return (data.messages ?? []).filter((m) => m.To?.some((t) => t.Address.toLowerCase() === address))
}

export async function openSidebar(page, fileid) {
	await page.goto(`/apps/files/files/${fileid}?opendetails=true`)
	await expect(page.getByRole('tab', { name: 'Sharing' })).toBeVisible()
}
