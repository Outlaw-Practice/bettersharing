import { expect, test } from '@playwright/test'
import { clearMail, login, mailTo, makeFolder, openSidebar } from './helpers.js'

const shots = (name) => ({ path: `test-results/screens/${name}.png`, fullPage: false })

test('sidebar shows BetterSharing tabs in place of the stock Sharing and Versions tabs', async ({ page }) => {
	const folder = await makeFolder('alice', `Tabs ${Date.now()}`)
	await login(page)
	await openSidebar(page, folder.fileid)
	const tabs = await page.getByRole('tab').allInnerTexts()
	expect(tabs.map((t) => t.trim())).toEqual(['Sharing', 'Presets', 'Activity', 'History'])
	await expect(page.getByText('Not shared yet')).toBeVisible()
	await page.screenshot(shots('01-sidebar'))
})

test('guided steps share with a colleague and a client', async ({ page }) => {
	await clearMail()
	const folder = await makeFolder('alice', `Client packet ${Date.now()}`)
	await login(page)
	await openSidebar(page, folder.fileid)
	await page.getByRole('button', { name: 'Share', exact: true }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByText('Who are you sharing this with?')).toBeVisible()
	// Next with nobody on the list is refused, on this step.
	await dialog.getByRole('button', { name: 'Next' }).click()
	await expect(dialog.getByRole('alert')).toHaveText('Add at least one person.')

	const field = dialog.getByLabel('Name or email address')
	await field.fill('bob')
	await dialog.getByRole('button', { name: /Bob Barnes/ }).click()
	await field.fill('carol')
	await page.waitForTimeout(600)
	await expect(dialog.getByRole('button', { name: /Carol/ }), 'another firm is never suggested').toHaveCount(0)
	await field.fill('client@example.com')
	await field.press('Enter')
	await expect(dialog.getByText('client@example.com')).toBeVisible()
	await page.screenshot(shots('02-people'))
	await dialog.getByRole('button', { name: 'Next' }).click()

	// Access: a preset must be chosen.
	await expect(dialog.getByText('What can they do?')).toBeVisible()
	await dialog.getByRole('button', { name: 'Next' }).click()
	await expect(dialog.getByRole('alert')).toContainText('Choose a preset')
	await dialog.getByRole('radio', { name: /View and download/ }).first().click()
	await dialog.getByRole('button', { name: 'Customize' }).click()
	await expect(dialog.getByText('Links for people outside the firm end')).toBeVisible()
	await page.screenshot(shots('03-access'))
	await dialog.getByRole('button', { name: 'Next' }).click()

	await expect(dialog.getByLabel('Share name')).toHaveValue(folder.name)
	await dialog.getByLabel('Message for people with access').fill('Review the documents and upload your signed copy.')
	await dialog.getByRole('button', { name: 'Next' }).click()

	await expect(dialog.getByText('Nothing is shared until you select Share.')).toBeVisible()
	await expect(dialog.getByText('Link by email, password in a separate email.', { exact: false })).toBeVisible()
	await page.screenshot(shots('04-review'))
	await dialog.getByRole('button', { name: 'Share', exact: true }).click()

	await expect(dialog.getByText('Shared with 2 people')).toBeVisible()
	await page.screenshot(shots('05-results'))
	await dialog.getByRole('button', { name: 'Done' }).click()

	// The share is ordinary Nextcloud data, and the client got link and password emails.
	const bundles = (await (await folder.ctx.get(`/ocs/v2.php/apps/bettersharing/api/v1/bundles?fileId=${folder.fileid}`)).json()).ocs.data
	expect(bundles).toHaveLength(1)
	expect(bundles[0].recipients.map((r) => r.status)).toEqual(['shared', 'shared'])
	await expect.poll(async () => (await mailTo('client@example.com')).length).toBe(2)

	await expect(page.getByText('Your shares')).toBeVisible()
	await page.screenshot(shots('06-sharing-tab'))
})

test('existing share opens in the full editor', async ({ page }) => {
	const folder = await makeFolder('alice', `Editor ${Date.now()}`)
	await folder.ctx.post('/ocs/v2.php/apps/bettersharing/api/v1/bundles', {
		data: { fileId: folder.fileid, recipients: [{ type: 'user', id: 'bob' }], permissions: 1, name: folder.name, message: '', idempotencyKey: `e2e-${Date.now()}` },
	})
	await login(page)
	await openSidebar(page, folder.fileid)
	await page.locator('.bs-bundle', { hasText: folder.name }).click()
	await expect(page.getByLabel('Share name')).toHaveValue(folder.name)
	await expect(page.getByText('Stop sharing with everyone')).toBeVisible()
	await page.getByRole('checkbox', { name: /Upload files and create folders/ }).check({ force: true })
	await page.getByRole('button', { name: 'Save changes' }).click()
	await expect(page.getByRole('status')).toContainText('Saved')
	await page.screenshot(shots('07-editor'))
})

test('presets tab is a sortable table', async ({ page }) => {
	const folder = await makeFolder('alice', `Presets ${Date.now()}`)
	await login(page)
	await openSidebar(page, folder.fileid)
	await page.getByRole('tab', { name: 'Presets' }).click()
	const uses = page.getByRole('columnheader', { name: /Uses/ })
	await expect(uses).toHaveAttribute('aria-sort', 'none')
	await uses.getByRole('button').click()
	await expect(uses).toHaveAttribute('aria-sort', 'descending')
	await uses.getByRole('button').click()
	await expect(uses).toHaveAttribute('aria-sort', 'ascending')
	await page.getByRole('button', { name: /View and download/ }).first().click()
	await expect(page.getByRole('button', { name: 'New Share' })).toBeVisible()
	await expect(page.getByRole('button', { name: 'Edit Share' })).toBeVisible()
	await page.screenshot(shots('08-presets'))
})

test('history tab shows access history and the versions note for folders', async ({ page }) => {
	const folder = await makeFolder('alice', `History ${Date.now()}`)
	await folder.ctx.post('/ocs/v2.php/apps/bettersharing/api/v1/bundles', {
		data: { fileId: folder.fileid, recipients: [{ type: 'user', id: 'bob' }], permissions: 1, name: folder.name, message: '', idempotencyKey: `h-${Date.now()}` },
	})
	await login(page)
	await openSidebar(page, folder.fileid)
	await page.getByRole('tab', { name: 'History' }).click()
	await expect(page.getByText('Access history')).toBeVisible()
	await expect(page.locator('.bs-history .bs-lines li').filter({ hasText: /Bob Barnes/ }).first()).toBeVisible()
	await expect(page.getByText('Nextcloud keeps versions of files, not folders.', { exact: false })).toBeVisible()
	await page.screenshot(shots('09-history'))
})

test('results screen reports a failed recipient and Retry shares with them', async ({ page }) => {
	// The server's failure handling is covered by PHPUnit; here the results screen is
	// driven with a recorded failure so the rendered text and Retry can be checked.
	const folder = await makeFolder('alice', `Partial ${Date.now()}`)
	const failed = { id: 99, fileId: folder.fileid, name: folder.name, message: '', presetId: null, presetName: null, status: 'in_progress', createdAt: 0, recipients: [
		{ id: 1, kind: 'user', recipient: 'bob', display: 'Bob Barnes', status: 'shared', result: 'Shared', permissions: 1, expireDate: null, version: 'v1', editable: true },
		{ id: 2, kind: 'email', recipient: 'client@example.com', display: 'client@example.com', status: 'failed', result: 'Blocked by policy', permissions: 1, expireDate: null, version: null, editable: false },
	] }
	await page.route('**/apps/bettersharing/api/v1/bundles', async (route) => {
		if (route.request().method() !== 'POST') {
			return route.continue()
		}
		await route.fulfill({ json: { ocs: { meta: { status: 'ok', statuscode: 200 }, data: failed } } })
	})
	await page.route('**/apps/bettersharing/api/v1/bundles/99/retry', (route) => route.fulfill({ json: { ocs: { meta: { status: 'ok', statuscode: 200 }, data: {
		...failed, status: 'complete', recipients: failed.recipients.map((r) => ({ ...r, status: 'shared', result: 'Shared' })),
	} } } }))
	await login(page)
	await openSidebar(page, folder.fileid)
	await page.getByRole('button', { name: 'Share', exact: true }).click()
	const dialog = page.getByRole('dialog')
	const field = dialog.getByLabel('Name or email address')
	await field.fill('bob')
	await dialog.getByRole('button', { name: /Bob Barnes/ }).click()
	await field.fill('client@example.com')
	await field.press('Enter')
	await dialog.getByRole('button', { name: 'Next' }).click()
	await dialog.getByRole('radio', { name: /View and download/ }).first().click()
	await dialog.getByRole('button', { name: 'Next' }).click()
	await dialog.getByRole('button', { name: 'Next' }).click()
	await dialog.getByRole('button', { name: 'Share', exact: true }).click()

	await expect(dialog.getByText('Shared with 1 person. Some people could not be added.')).toBeVisible()
	await expect(dialog.getByText('Blocked by policy')).toBeVisible()
	await page.screenshot(shots('10-partial'))
	await dialog.getByRole('button', { name: 'Retry' }).click()
	await expect(dialog.getByText('Shared with 2 people')).toBeVisible()
	await expect(dialog.getByRole('button', { name: 'Retry' })).toHaveCount(0)
})

