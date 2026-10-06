<template>
	<div class="bs-history">
		<section class="bs-section">
			<h3 class="bs-label">
				{{ t('bettersharing', 'Access history') }}
			</h3>
			<p class="bs-note">
				{{ t('bettersharing', 'Who has been given access to this item, and when. Taken from Nextcloud’s activity record, which may be incomplete and is kept for a limited time.') }}
			</p>
			<NcLoadingIcon v-if="loadingActivity" :size="24" />
			<p v-else-if="activityError" class="bs-error">
				{{ activityError }}
			</p>
			<p v-else-if="activities.length === 0" class="bs-note">
				{{ t('bettersharing', 'No sharing activity recorded for this item.') }}
			</p>
			<ul v-else class="bs-lines">
				<li v-for="a in activities" :key="a.activity_id">
					<NcIconSvgWrapper :path="mdiShareVariant" :size="20" />
					<span>
						{{ a.subject }}
						<span class="bs-lines__how">{{ formatTime(a.datetime) }}</span>
					</span>
				</li>
			</ul>
		</section>

		<section class="bs-section">
			<h3 class="bs-label">
				{{ t('bettersharing', 'Versions') }}
			</h3>
			<p v-if="node && node.type !== 'file'" class="bs-note">
				{{ t('bettersharing', 'Nextcloud keeps versions of files, not folders. Select a file in this folder to see and restore its earlier versions here.') }}
			</p>
			<template v-else>
				<NcLoadingIcon v-if="loadingVersions" :size="24" />
				<p v-else-if="versionsError" class="bs-error">
					{{ versionsError }}
				</p>
				<p v-else-if="versions.length === 0" class="bs-note">
					{{ t('bettersharing', 'No earlier versions of this file.') }}
				</p>
				<ul v-else class="bs-lines">
					<li v-for="v in versions" :key="v.name">
						<NcIconSvgWrapper :path="mdiFileClockOutline" :size="20" />
						<span class="bs-version">
							<span>
								{{ formatTime(v.modified) }}
								<span class="bs-lines__how">{{ v.size }}</span>
							</span>
							<NcButton variant="tertiary" size="small" :disabled="busy" @click="restore(v)">
								{{ t('bettersharing', 'Restore') }}
							</NcButton>
						</span>
					</li>
				</ul>
				<p v-if="restored" class="bs-note" role="status">
					{{ restored }}
				</p>
			</template>
		</section>
	</div>
</template>

<script setup>
import { mdiFileClockOutline, mdiShareVariant } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { formatFileSize } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl, generateRemoteUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { ref, watch } from 'vue'

const props = defineProps({
	node: { type: Object, default: null },
	active: { type: Boolean, default: false },
})

const SHARING_TYPES = ['shared', 'public_links', 'remote_share']
const activities = ref([])
const loadingActivity = ref(true)
const activityError = ref('')
const versions = ref([])
const loadingVersions = ref(true)
const versionsError = ref('')
const busy = ref(false)
const restored = ref('')
// Only the most recent load for each list may change what the tab shows.
let activityGeneration = 0
let versionsGeneration = 0

function formatTime(value) {
	const date = value instanceof Date ? value : new Date(value)
	return date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

async function loadActivity(fileid) {
	const mine = ++activityGeneration
	loadingActivity.value = true
	activityError.value = ''
	activities.value = []
	try {
		const response = await axios.get(generateOcsUrl('/apps/activity/api/v2/activity/filter'), {
			params: { format: 'json', object_type: 'files', object_id: fileid, limit: 100 },
			headers: { 'OCS-APIRequest': 'true' },
			validateStatus: (s) => s === 200 || s === 304,
		})
		if (mine !== activityGeneration) {
			return
		}
		const list = response.status === 200 ? response.data.ocs.data : []
		activities.value = list.filter((a) => SHARING_TYPES.includes(a.type))
	} catch {
		if (mine === activityGeneration) {
			activityError.value = t('bettersharing', 'The activity record is not available on this server.')
		}
	} finally {
		if (mine === activityGeneration) {
			loadingActivity.value = false
		}
	}
}

function versionsUrl(fileid) {
	return generateRemoteUrl(`dav/versions/${encodeURIComponent(getCurrentUser().uid)}/versions/${fileid}`)
}

async function loadVersions(fileid) {
	const mine = ++versionsGeneration
	loadingVersions.value = true
	versionsError.value = ''
	restored.value = ''
	versions.value = []
	try {
		const response = await axios.request({
			method: 'PROPFIND',
			url: versionsUrl(fileid),
			headers: { Depth: '1' },
			data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getlastmodified/><d:getcontentlength/></d:prop></d:propfind>',
		})
		if (mine !== versionsGeneration) {
			return
		}
		const doc = new DOMParser().parseFromString(response.data, 'application/xml')
		const entries = [...doc.getElementsByTagNameNS('DAV:', 'response')].slice(1)
		versions.value = entries.map((r) => {
			const href = r.getElementsByTagNameNS('DAV:', 'href')[0].textContent
			return {
				name: decodeURIComponent(href.replace(/\/$/, '').split('/').pop()),
				modified: new Date(r.getElementsByTagNameNS('DAV:', 'getlastmodified')[0]?.textContent),
				size: formatFileSize(Number(r.getElementsByTagNameNS('DAV:', 'getcontentlength')[0]?.textContent ?? 0)),
			}
		}).sort((a, b) => b.modified - a.modified)
	} catch {
		if (mine === versionsGeneration) {
			versionsError.value = t('bettersharing', 'Versions could not be loaded.')
		}
	} finally {
		if (mine === versionsGeneration) {
			loadingVersions.value = false
		}
	}
}

async function restore(v) {
	busy.value = true
	try {
		await axios.request({
			method: 'MOVE',
			url: `${versionsUrl(props.node.fileid)}/${encodeURIComponent(v.name)}`,
			headers: { Destination: generateRemoteUrl(`dav/versions/${encodeURIComponent(getCurrentUser().uid)}/restore/target`) },
		})
		restored.value = t('bettersharing', 'Restored the version from {time}. The previous current version was kept as a version.', { time: formatTime(v.modified) })
		await loadVersions(props.node.fileid)
	} catch {
		versionsError.value = t('bettersharing', 'That version could not be restored.')
	} finally {
		busy.value = false
	}
}

watch(() => props.node?.fileid, (fileid) => {
	if (!fileid) {
		return
	}
	loadActivity(fileid)
	if (props.node.type === 'file') {
		loadVersions(fileid)
	} else {
		versionsGeneration++ // a folder: any versions load still in flight is for another item
		versions.value = []
	}
}, { immediate: true })
</script>

<style scoped lang="scss">
.bs-history {
	display: grid;
	gap: 18px;
	padding: 10px 0;
}

.bs-section {
	display: grid;
	gap: 8px;
}

.bs-version {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}
</style>
