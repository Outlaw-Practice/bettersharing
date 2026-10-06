import { n, t } from '@nextcloud/l10n'

export const READ = 1
export const UPDATE = 2
export const CREATE = 4
export const DELETE = 8

/** The four choices, in display order, with the descriptions people see. */
export const PERMISSIONS = [
	{
		bit: READ,
		label: t('bettersharing', 'View and download'),
		description: t('bettersharing', 'See everything in the folder, open and preview files, and save copies to their own device. People at your firm can also sync it to their computer. Viewing and downloading go together: Nextcloud can hide a download button, but anyone who can open a file can still copy it.'),
	},
	{
		bit: CREATE,
		label: t('bettersharing', 'Upload files and create folders'),
		description: t('bettersharing', 'Add new files and subfolders. On its own this does not let them change or remove anything already in the folder.'),
		folderOnly: true,
	},
	{
		bit: UPDATE,
		label: t('bettersharing', 'Edit existing files'),
		description: t('bettersharing', 'Change and rename files already in the folder, including files other people added. Includes view and download.'),
	},
	{
		bit: DELETE,
		label: t('bettersharing', 'Delete files and folders'),
		description: t('bettersharing', 'Remove files and subfolders, including ones other people added. Includes view and download.'),
		folderOnly: true,
	},
]

/** Checking edit or delete includes view; unchecking view removes edit and delete. */
export function togglePermission(perms, bit, on) {
	let p = on ? perms | bit : perms & ~bit
	if (on && (bit === UPDATE || bit === DELETE)) {
		p |= READ
	}
	if (!on && bit === READ) {
		p &= ~(UPDATE | DELETE)
	}
	return p
}

export function isUploadOnly(perms) {
	return (perms & READ) === 0 && (perms & CREATE) !== 0
}

function list(words) {
	if (words.length < 2) {
		return words.join('')
	}
	return t('bettersharing', '{list} and {last}', { list: words.slice(0, -1).join(', '), last: words[words.length - 1] })
}

/** "Can view, download and upload" */
export function canText(perms) {
	if (isUploadOnly(perms)) {
		return t('bettersharing', 'Can upload, but cannot see what is in the folder')
	}
	const words = []
	if (perms & READ) {
		words.push(t('bettersharing', 'view'), t('bettersharing', 'download'))
	}
	if (perms & CREATE) {
		words.push(t('bettersharing', 'upload'))
	}
	if (perms & UPDATE) {
		words.push(t('bettersharing', 'edit'))
	}
	if (perms & DELETE) {
		words.push(t('bettersharing', 'delete'))
	}
	return t('bettersharing', 'Can {permissions}', { permissions: list(words) })
}

export function presetSummary(preset) {
	let s = canText(preset.permissions) + '.'
	if (isUploadOnly(preset.permissions)) {
		s += ' ' + t('bettersharing', 'People at your firm can also view and download.')
	}
	s += ' ' + (preset.expireDays
		? n('bettersharing', 'Outside links end after %n day.', 'Outside links end after %n days.', preset.expireDays)
		: t('bettersharing', 'Outside links do not expire.'))
	return s
}

export function isoDate(date) {
	const y = date.getFullYear()
	const m = String(date.getMonth() + 1).padStart(2, '0')
	const d = String(date.getDate()).padStart(2, '0')
	return `${y}-${m}-${d}`
}

export function daysFromNow(days) {
	const d = new Date()
	d.setDate(d.getDate() + days)
	return d
}

export function formatDate(value) {
	if (!value) {
		return ''
	}
	const date = typeof value === 'number' ? new Date(value * 1000) : new Date(`${value}T12:00:00`)
	const sameYear = date.getFullYear() === new Date().getFullYear()
	return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: sameYear ? undefined : 'numeric' })
}

export function newKey() {
	return (crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`).replace(/[^A-Za-z0-9-]/g, '')
}

/** Sort comparators for presets; ties fall back to name. */
export const PRESET_SORTS = [
	{ id: 'used', label: t('bettersharing', 'Most used'), compare: (a, b) => b.useCount - a.useCount },
	{ id: 'recent', label: t('bettersharing', 'Recently used'), compare: (a, b) => (b.lastUsedAt ?? 0) - (a.lastUsedAt ?? 0) },
	{ id: 'name', label: t('bettersharing', 'Name, A to Z'), compare: () => 0 },
	{ id: 'changed', label: t('bettersharing', 'Recently changed'), compare: (a, b) => b.updatedAt - a.updatedAt },
]

export function sortPresets(presets, sortId) {
	const sort = PRESET_SORTS.find((s) => s.id === sortId) ?? PRESET_SORTS[0]
	return [...presets].sort((a, b) => sort.compare(a, b) || a.name.localeCompare(b.name))
}

export function matchesPreset(preset, query) {
	const q = query.trim().toLowerCase()
	return !q || `${preset.name} ${preset.message}`.toLowerCase().includes(q)
}
