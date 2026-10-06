import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), request: vi.fn() } }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))

const { default: axios } = await import('@nextcloud/axios')
const { default: HistoryTab } = await import('./HistoryTab.vue')

const reply = (subject) => ({ status: 200, data: { ocs: { data: [{ activity_id: subject, type: 'shared', subject, datetime: '2026-10-05T12:00:00Z' }] } } })

describe('HistoryTab', () => {
	it('never shows an older item’s activity after switching items', async () => {
		let resolveOld
		axios.get
			.mockImplementationOnce(() => new Promise((r) => { resolveOld = r }))
			.mockImplementationOnce(() => Promise.resolve(reply('You shared B with Bob')))
		const wrapper = mount(HistoryTab, { props: { node: { fileid: 1, type: 'folder' } } })
		await flushPromises()
		await wrapper.setProps({ node: { fileid: 2, type: 'folder' } })
		await flushPromises()
		resolveOld(reply('You shared A with Bob'))
		await flushPromises()
		expect(wrapper.text()).toContain('You shared B with Bob')
		expect(wrapper.text()).not.toContain('You shared A with Bob')
	})
})
