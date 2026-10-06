import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api.js', () => ({
	ApiError: class extends Error {},
	api: {
		status: vi.fn(),
		presets: vi.fn(),
		bundle: vi.fn(),
		bundles: vi.fn(),
		access: vi.fn(),
	},
}))
vi.mock('../dialog.js', () => ({ openShareDialog: vi.fn() }))

const { api } = await import('../api.js')
const { default: BundleEditor } = await import('./BundleEditor.vue')
const { default: SharingTab } = await import('./SharingTab.vue')

const status = { problem: null, outsideProblem: null, firm: { id: 'f', name: 'Firm' }, isFirmAdmin: false }

function deferred() {
	let resolve
	const promise = new Promise((r) => { resolve = r })
	return { promise, resolve }
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('BundleEditor', () => {
	it('shows the error, not an endless spinner, when the share cannot load', async () => {
		api.status.mockResolvedValue(status)
		api.presets.mockResolvedValue([])
		api.bundle.mockRejectedValue(new Error('This share no longer exists.'))
		const wrapper = mount(BundleEditor, { props: { bundleId: 7, node: { fileid: 1, basename: 'x', type: 'folder' } } })
		await flushPromises()
		expect(wrapper.text()).toContain('This share no longer exists.')
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Try again')
	})
})

describe('SharingTab', () => {
	it('never shows the previous item’s shares after switching items', async () => {
		api.status.mockResolvedValue(status)
		const slowA = deferred()
		api.bundles.mockImplementation((fileid) => fileid === 1
			? slowA.promise
			: Promise.resolve([{ id: 2, name: 'Share of B', presetName: null, recipients: [] }]))
		api.access.mockResolvedValue([])
		const wrapper = mount(SharingTab, { props: { node: { fileid: 1, basename: 'A', type: 'folder' } } })
		await flushPromises()
		await wrapper.setProps({ node: { fileid: 2, basename: 'B', type: 'folder' } })
		await flushPromises()
		slowA.resolve([{ id: 1, name: 'Share of A', presetName: null, recipients: [] }])
		await flushPromises()
		expect(wrapper.text()).toContain('Share of B')
		expect(wrapper.text()).not.toContain('Share of A')
	})

	it('keeps the newest data when returning to an item before its first load finished', async () => {
		api.status.mockResolvedValue(status)
		api.access.mockResolvedValue([])
		const firstA = deferred()
		const secondA = deferred()
		api.bundles
			.mockImplementationOnce(() => firstA.promise)
			.mockImplementationOnce(() => Promise.resolve([]))
			.mockImplementationOnce(() => secondA.promise)
		const wrapper = mount(SharingTab, { props: { node: { fileid: 1, basename: 'A', type: 'folder' } } })
		await flushPromises()
		await wrapper.setProps({ node: { fileid: 2, basename: 'B', type: 'folder' } })
		await flushPromises()
		await wrapper.setProps({ node: { fileid: 1, basename: 'A', type: 'folder' } })
		await flushPromises()
		secondA.resolve([{ id: 2, name: 'Newer share of A', presetName: null, recipients: [] }])
		await flushPromises()
		firstA.resolve([{ id: 1, name: 'Older share of A', presetName: null, recipients: [] }])
		await flushPromises()
		expect(wrapper.text()).toContain('Newer share of A')
		expect(wrapper.text()).not.toContain('Older share of A')
	})

	it('clears the previous item’s shares when loading the next one fails', async () => {
		api.status.mockResolvedValue(status)
		api.access.mockResolvedValue([])
		api.bundles.mockResolvedValueOnce([{ id: 1, name: 'Share of A', presetName: null, recipients: [] }])
		const wrapper = mount(SharingTab, { props: { node: { fileid: 1, basename: 'A', type: 'folder' } } })
		await flushPromises()
		expect(wrapper.text()).toContain('Share of A')
		api.bundles.mockRejectedValueOnce(new Error('Could not reach the server.'))
		await wrapper.setProps({ node: { fileid: 2, basename: 'B', type: 'folder' } })
		await flushPromises()
		expect(wrapper.text()).not.toContain('Share of A')
		expect(wrapper.text()).toContain('Could not reach the server.')
	})
})
