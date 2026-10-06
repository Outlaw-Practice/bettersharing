import { describe, expect, it } from 'vitest'
import { canText, CREATE, DELETE, isUploadOnly, matchesPreset, presetSummary, READ, sortPresets, togglePermission, UPDATE } from './util.js'

describe('permission checklist rules', () => {
	it('checking edit or delete also checks view and download', () => {
		expect(togglePermission(0, UPDATE, true)).toBe(UPDATE | READ)
		expect(togglePermission(CREATE, DELETE, true)).toBe(CREATE | DELETE | READ)
	})

	it('unchecking view and download also unchecks edit and delete', () => {
		expect(togglePermission(READ | CREATE | UPDATE | DELETE, READ, false)).toBe(CREATE)
	})

	it('recognizes upload only', () => {
		expect(isUploadOnly(CREATE)).toBe(true)
		expect(isUploadOnly(CREATE | READ)).toBe(false)
	})
})

describe('plain-language summaries', () => {
	it('lists what people can do', () => {
		expect(canText(READ)).toBe('Can view and download')
		expect(canText(READ | CREATE)).toBe('Can view, download and upload')
		expect(canText(READ | CREATE | UPDATE | DELETE)).toBe('Can view, download, upload, edit and delete')
		expect(canText(CREATE)).toBe('Can upload, but cannot see what is in the folder')
	})

	it('summarizes a preset', () => {
		expect(presetSummary({ permissions: READ, expireDays: 14 })).toBe('Can view and download. Outside links end after 14 days.')
		expect(presetSummary({ permissions: CREATE, expireDays: null }))
			.toBe('Can upload, but cannot see what is in the folder. People at your firm can also view and download. Outside links do not expire.')
	})
})

describe('preset search and sort', () => {
	const presets = [
		{ name: 'Mediation packet', message: 'Review before we meet', useCount: 9, lastUsedAt: 100, updatedAt: 900 },
		{ name: 'Uploads only', message: '', useCount: 41, lastUsedAt: 300, updatedAt: 100 },
		{ name: 'Expert review', message: '', useCount: 9, lastUsedAt: 200, updatedAt: 500 },
	]

	it('searches name and message only', () => {
		expect(presets.filter((p) => matchesPreset(p, 'before')).map((p) => p.name)).toEqual(['Mediation packet'])
		expect(presets.filter((p) => matchesPreset(p, 'download'))).toEqual([])
	})

	it('sorts by most used with ties by name, and by the other orders', () => {
		expect(sortPresets(presets, 'used').map((p) => p.name)).toEqual(['Uploads only', 'Expert review', 'Mediation packet'])
		expect(sortPresets(presets, 'recent')[0].name).toBe('Uploads only')
		expect(sortPresets(presets, 'name')[0].name).toBe('Expert review')
		expect(sortPresets(presets, 'changed')[0].name).toBe('Mediation packet')
	})
})
