import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import ShareDialog from './components/ShareDialog.vue'

/**
 * Opens the guided share dialog for a Files node (or a plain
 * { fileid, basename, type } object). Resolves with the created bundle, if any.
 */
export function openShareDialog(node, initialPresetId = null) {
	return spawnDialog(ShareDialog, {
		node: { fileid: node.fileid, basename: node.basename, type: node.type },
		initialPresetId,
	})
}
