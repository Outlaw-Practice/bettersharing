<template>
	<div class="bs-tab">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="problem" type="warning" :text="problem" />

		<BundleEditor v-else-if="editing"
			:bundle-id="editing"
			:node="plainNode"
			@back="editing = null; load()" />

		<template v-else>
			<div>
				<NcButton variant="primary" @click="share">
					<template #icon>
						<NcIconSvgWrapper :path="mdiShareVariant" />
					</template>
					{{ t('bettersharing', 'Share') }}
				</NcButton>
			</div>

			<section v-if="bundles.length > 0" class="bs-section">
				<h3 class="bs-label">
					{{ t('bettersharing', 'Your shares') }}
				</h3>
				<button v-for="b in bundles"
					:key="b.id"
					type="button"
					class="bs-bundle"
					@click="editing = b.id">
					<span class="bs-bundle__name">{{ b.name }}</span>
					<span class="bs-note">{{ b.presetName ?? t('bettersharing', 'Custom') }}</span>
					<span class="bs-bundle__people">{{ peopleLine(b) }}</span>
					<span v-if="failed(b) > 0" class="bs-error">
						{{ n('bettersharing', '%n person was not shared with. Open to retry.', '%n people were not shared with. Open to retry.', failed(b)) }}
					</span>
				</button>
			</section>
			<NcEmptyContent v-else-if="access.length === 0"
				:name="t('bettersharing', 'Not shared yet')"
				:description="t('bettersharing', 'Share this item with people at your firm, or with anyone by email address.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiShareVariant" />
				</template>
			</NcEmptyContent>

			<section v-if="access.length > 0" class="bs-section">
				<h3 class="bs-label">
					{{ t('bettersharing', 'Who has access') }}
				</h3>
				<ul class="bs-lines">
					<li v-for="a in access" :key="a.shareId">
						<NcIconSvgWrapper :path="iconFor(a.kind)" :size="20" />
						<span>
							<strong>{{ a.display }}</strong>
							<span class="bs-lines__how">{{ accessLine(a) }}</span>
						</span>
					</li>
				</ul>
			</section>
			<p v-if="error" class="bs-error" role="alert">
				{{ error }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { mdiAccount, mdiAccountGroup, mdiEmailOutline, mdiLink, mdiShareVariant } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, ref, watch } from 'vue'
import { api } from '../api.js'
import { openShareDialog } from '../dialog.js'
import { canText, formatDate } from '../util.js'
import BundleEditor from './BundleEditor.vue'

const props = defineProps({
	node: { type: Object, default: null },
	active: { type: Boolean, default: false },
})

const loading = ref(true)
const problem = ref('')
const error = ref('')
const bundles = ref([])
const access = ref([])
const editing = ref(null)
let generation = 0

const plainNode = computed(() => props.node && { fileid: props.node.fileid, basename: props.node.basename, type: props.node.type })

async function load() {
	if (!props.node) {
		return
	}
	const fileid = props.node.fileid
	const mine = ++generation
	// Only the most recent load may change what the tab shows.
	const current = () => mine === generation
	// Never show the previous item's shares while this one loads, or if it fails.
	bundles.value = []
	access.value = []
	error.value = ''
	try {
		const status = await api.status()
		if (!current()) {
			return
		}
		problem.value = status.problem ?? ''
		if (!problem.value) {
			const [b, a] = await Promise.all([api.bundles(fileid), api.access(fileid)])
			if (!current()) {
				return // the user moved on to another item; drop this response
			}
			bundles.value = b
			access.value = a
		}
	} catch (e) {
		if (current()) {
			error.value = e.message
		}
	} finally {
		if (current()) {
			loading.value = false
		}
	}
}

watch(() => props.node?.fileid, () => {
	editing.value = null
	loading.value = true
	load()
}, { immediate: true })

async function share() {
	await openShareDialog(plainNode.value)
	await load()
}

function peopleLine(b) {
	return b.recipients.filter((r) => r.status === 'shared' || r.status === 'existing').map((r) => r.display).join(', ')
		|| t('bettersharing', 'No one has access through this share.')
}

function failed(b) {
	return b.recipients.filter((r) => r.status === 'failed').length
}

function iconFor(kind) {
	return kind === 'email' ? mdiEmailOutline : kind === 'group' ? mdiAccountGroup : kind === 'link' ? mdiLink : mdiAccount
}

function accessLine(a) {
	const parts = [canText(a.permissions) + '.']
	if (a.expireDate) {
		parts.push(t('bettersharing', 'Ends {date}.', { date: formatDate(a.expireDate) }))
	}
	if (a.inheritedFrom) {
		parts.push(t('bettersharing', 'Through the folder {folder}.', { folder: a.inheritedFrom }))
	}
	if (a.sharedWithYou) {
		parts.push(t('bettersharing', 'Shared with you by {name}.', { name: a.sharedByDisplay }))
	} else if (!a.editable) {
		parts.push(t('bettersharing', 'Shared by {name}. Only they can change it.', { name: a.sharedByDisplay }))
	}
	return parts.join(' ')
}
</script>

<style scoped lang="scss">
.bs-tab {
	display: grid;
	gap: 16px;
	padding: 10px 0;
}

.bs-section {
	display: grid;
	gap: 8px;
}

.bs-bundle {
	display: grid;
	gap: 2px;
	text-align: start;
	width: 100%;
	height: auto;
	margin: 0;
	padding: 10px 12px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	cursor: pointer;
	font-weight: normal;

	&:hover {
		background: var(--color-background-hover);
	}

	&__name {
		font-weight: bold;
	}

	&__people {
		font-size: 13px;
		overflow-wrap: anywhere;
	}
}
</style>
