/**
 * BetterSharing in the Files app. Loaded as an init script, so its tabs register
 * before the stock ones: Nextcloud keeps the first tab registered under an ID,
 * which is how BetterSharing's Sharing and History tabs replace the stock
 * Sharing and Versions tabs.
 */
import { mdiAccountMultipleOutline, mdiHistory, mdiShareVariant, mdiTuneVariant } from '@mdi/js'
import { getSidebar, Permission, registerFileAction, registerSidebarTab } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import './styles.scss'

const svg = (path) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${path}"/></svg>`

async function defineTab(tagName, loader) {
	if (window.customElements.get(tagName)) {
		return
	}
	const [{ defineCustomElement }, component] = await Promise.all([import('vue'), loader()])
	window.customElements.define(tagName, defineCustomElement(component.default, { shadowRoot: false }))
}

registerSidebarTab({
	id: 'sharing',
	displayName: t('bettersharing', 'Sharing'),
	iconSvgInline: svg(mdiShareVariant),
	order: 10,
	tagName: 'bettersharing-sharing-tab',
	onInit: () => defineTab('bettersharing-sharing-tab', () => import('./components/SharingTab.vue')),
})

registerSidebarTab({
	id: 'bettersharing-presets',
	displayName: t('bettersharing', 'Presets'),
	iconSvgInline: svg(mdiTuneVariant),
	order: 20,
	tagName: 'bettersharing-presets-tab',
	onInit: () => defineTab('bettersharing-presets-tab', () => import('./components/PresetsTab.vue')),
})

registerSidebarTab({
	id: 'files_versions',
	displayName: t('bettersharing', 'History'),
	iconSvgInline: svg(mdiHistory),
	order: 90,
	tagName: 'bettersharing-history-tab',
	onInit: () => defineTab('bettersharing-history-tab', () => import('./components/HistoryTab.vue')),
})

registerFileAction({
	id: 'bettersharing-share',
	displayName: () => t('bettersharing', 'Share'),
	iconSvgInline: () => svg(mdiAccountMultipleOutline),
	order: -50,
	enabled: ({ nodes }) => nodes.length === 1 && (nodes[0].permissions & Permission.SHARE) !== 0,
	async exec({ nodes }) {
		const { openShareDialog } = await import('./dialog.js')
		await openShareDialog(nodes[0])
		getSidebar().open(nodes[0], 'sharing')
		return null
	},
})
