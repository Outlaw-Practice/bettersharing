<template>
	<div class="bs-editor">
		<div>
			<NcButton variant="tertiary" @click="$emit('back')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowLeft" />
				</template>
				{{ t('bettersharing', 'All shares') }}
			</NcButton>
		</div>
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="!bundle">
			<p class="bs-error" role="alert">
				{{ error || t('bettersharing', 'This share could not be loaded.') }}
			</p>
			<div>
				<NcButton @click="reload">
					{{ t('bettersharing', 'Try again') }}
				</NcButton>
			</div>
		</template>
		<template v-else>
			<NcTextField v-model="name" :label="t('bettersharing', 'Share name')" />

			<section class="bs-section">
				<h3 class="bs-label">
					{{ t('bettersharing', 'People') }}
				</h3>
				<ul class="bs-rows">
					<li v-for="r in visibleRecipients" :key="r.id" class="bs-row">
						<NcIconSvgWrapper :path="r.kind === 'email' ? mdiEmailOutline : r.kind === 'group' ? mdiAccountGroup : mdiAccount" :size="20" />
						<span class="bs-row__who">
							{{ r.display }}
							<span class="bs-row__how" :class="{ 'bs-error': r.status === 'failed' }">{{ rowText(r) }}</span>
							<NcButton v-if="r.kind === 'email' && r.status === 'shared' && r.editable"
								variant="tertiary"
								size="small"
								:disabled="busy"
								@click="newPassword(r)">
								{{ t('bettersharing', 'Send new password') }}
							</NcButton>
						</span>
						<span class="bs-tag" :class="r.kind === 'email' ? 'out' : 'firm'">
							{{ r.kind === 'email' ? t('bettersharing', 'Outside the firm') : t('bettersharing', 'At your firm') }}
						</span>
						<NcButton v-if="r.status === 'shared' && r.editable"
							variant="tertiary"
							:aria-label="t('bettersharing', 'Stop sharing with {name}', { name: r.display })"
							:disabled="busy"
							@click="remove(r)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" />
							</template>
						</NcButton>
					</li>
				</ul>
				<PeoplePicker v-model="added" :outside-problem="outsideProblem" />
			</section>

			<section class="bs-section bs-box">
				<label class="bs-label" :for="selectId">{{ t('bettersharing', 'Preset') }}</label>
				<select :id="selectId" :value="presetSelect" @change="applyPreset($event.target.value)">
					<option v-if="presetSelect === ''" value="">
						{{ t('bettersharing', 'Custom') }}
					</option>
					<optgroup v-for="g in presetGroups" :key="g.label" :label="g.label">
						<option v-for="p in g.presets" :key="p.id" :value="String(p.id)">
							{{ p.name }}
						</option>
					</optgroup>
				</select>
				<PermissionChecklist v-model="permissions" :is-folder="node.type === 'folder'" />
				<ExpiryChoice v-if="hasOutside" v-model="expireDate" />
			</section>

			<section class="bs-section bs-box">
				<NcTextArea v-model="message"
					:label="t('bettersharing', 'Message for people with access')"
					:helper-text="t('bettersharing', 'Shown every time they open the share. Changing it emails people outside your firm.')"
					resize="vertical"
					rows="4" />
			</section>

			<div v-if="error" class="bs-error-box">
				<p class="bs-error" role="alert">
					{{ error }}
				</p>
				<NcButton v-if="conflict" @click="reload">
					{{ t('bettersharing', 'Reload') }}
				</NcButton>
			</div>
			<p v-if="saved" class="bs-saved" role="status">
				{{ saved }}
			</p>

			<NcNoteCard v-if="confirmStop" type="warning">
				<p>{{ t('bettersharing', 'Stop sharing “{name}” with everyone on it? Their access ends right away. Access people had before this share is not affected.', { name: bundle.name }) }}</p>
				<div class="bs-actions">
					<NcButton @click="confirmStop = false">
						{{ t('bettersharing', 'Keep sharing') }}
					</NcButton>
					<NcButton variant="error" :disabled="busy" @click="stopAll">
						{{ t('bettersharing', 'Stop sharing') }}
					</NcButton>
				</div>
			</NcNoteCard>

			<div class="bs-actions bs-actions--split">
				<NcButton variant="error" :disabled="busy" @click="confirmStop = true">
					{{ t('bettersharing', 'Stop sharing with everyone') }}
				</NcButton>
				<span class="bs-actions">
					<NcButton v-if="failedCount > 0" :disabled="busy" @click="retry">
						{{ t('bettersharing', 'Retry') }}
					</NcButton>
					<NcButton variant="primary" :disabled="busy" @click="save">
						{{ t('bettersharing', 'Save changes') }}
					</NcButton>
				</span>
			</div>
		</template>
	</div>
</template>

<script setup>
import { mdiAccount, mdiAccountGroup, mdiArrowLeft, mdiClose, mdiEmailOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { canText, daysFromNow, formatDate, isoDate, sortPresets } from '../util.js'
import ExpiryChoice from './ExpiryChoice.vue'
import PeoplePicker from './PeoplePicker.vue'
import PermissionChecklist from './PermissionChecklist.vue'

const props = defineProps({
	bundleId: { type: Number, required: true },
	node: { type: Object, required: true },
})
defineEmits(['back'])

const selectId = `bs-preset-${Math.random().toString(36).slice(2)}`
const bundle = ref(null)
const loading = ref(true)
const conflict = ref(false)
const presets = ref([])
const outsideProblem = ref(null)
const name = ref('')
const message = ref('')
const permissions = ref(1)
const expireDate = ref(null)
const presetSelect = ref('')
const added = ref([])
const busy = ref(false)
const error = ref('')
const saved = ref('')
const confirmStop = ref(false)

const visibleRecipients = computed(() => bundle.value.recipients.filter((r) => r.status !== 'removed'))
const failedCount = computed(() => bundle.value.recipients.filter((r) => r.status === 'failed').length)
const hasOutside = computed(() => bundle.value.recipients.some((r) => r.kind === 'email' && r.status === 'shared') || added.value.some((r) => r.type === 'email'))
const presetGroups = computed(() => [
	{ label: t('bettersharing', 'Pinned'), presets: presets.value.filter((p) => p.pinned) },
	{ label: t('bettersharing', 'All presets'), presets: sortPresets(presets.value.filter((p) => !p.pinned), 'name') },
].filter((g) => g.presets.length > 0))

function fill(b) {
	bundle.value = b
	name.value = b.name
	message.value = b.message
	const live = b.recipients.filter((r) => r.status === 'shared')
	const sample = live.find((r) => r.kind === 'email') ?? live[0]
	permissions.value = sample?.permissions ?? 1
	expireDate.value = live.find((r) => r.kind === 'email')?.expireDate ?? null
	presetSelect.value = b.presetId && presets.value.some((p) => p.id === b.presetId && p.permissions === permissions.value) ? String(b.presetId) : ''
}

async function reload() {
	loading.value = true
	error.value = ''
	conflict.value = false
	saved.value = ''
	try {
		const [status, list, b] = await Promise.all([api.status(), api.presets(), api.bundle(props.bundleId)])
		outsideProblem.value = status.outsideProblem
		presets.value = list
		added.value = []
		fill(b)
	} catch (e) {
		bundle.value = null
		error.value = e.message
	} finally {
		loading.value = false
	}
}

onMounted(reload)

/** The version of each share as loaded, sent with every change so edits made elsewhere are never overwritten. */
function versions() {
	return Object.fromEntries(bundle.value.recipients.filter((r) => r.version).map((r) => [String(r.id), r.version]))
}

function rowText(r) {
	if (r.status === 'existing') {
		return t('bettersharing', 'Already had access before this share. Not changed here.')
	}
	if (r.status === 'failed') {
		return r.result ?? t('bettersharing', 'Not shared.')
	}
	let line = canText(r.permissions) + '.'
	if (r.kind === 'email') {
		line += ' ' + (r.expireDate ? t('bettersharing', 'Ends {date}.', { date: formatDate(r.expireDate) }) : t('bettersharing', 'No end date.'))
	}
	if (!r.editable && r.status === 'shared') {
		line += ' ' + t('bettersharing', 'Read-only: someone else created this share.')
	}
	if (r.result && r.result !== t('bettersharing', 'Shared') && r.status === 'shared') {
		line += ' ' + r.result
	}
	return line
}

function applyPreset(value) {
	presetSelect.value = value
	const p = presets.value.find((x) => String(x.id) === value)
	if (p) {
		permissions.value = p.permissions
		expireDate.value = p.expireDays ? isoDate(daysFromNow(p.expireDays)) : null
		message.value = p.message ?? message.value
	}
}

async function run(fn, done) {
	busy.value = true
	error.value = ''
	conflict.value = false
	saved.value = ''
	try {
		fill(await fn())
		if (done) {
			saved.value = done
		}
	} catch (e) {
		error.value = e.message
		conflict.value = true
	} finally {
		busy.value = false
	}
}

function save() {
	if (name.value.trim() === '') {
		error.value = t('bettersharing', 'Give the share a name.')
		return
	}
	run(() => api.updateBundle(bundle.value.id, {
		name: name.value.trim(),
		message: message.value.trim(),
		permissions: permissions.value,
		expireDate: hasOutside.value ? expireDate.value : null,
		versions: versions(),
		add: added.value.map((r) => ({ type: r.type, id: r.id })),
	}).then((b) => { added.value = []; return b }), t('bettersharing', 'Saved. Check each person for their result.'))
}

function remove(r) {
	run(() => api.removeRecipient(bundle.value.id, r.id, r.version))
}

function newPassword(r) {
	run(() => api.newPassword(bundle.value.id, r.id))
}

function retry() {
	run(() => api.retry(bundle.value.id))
}

function stopAll() {
	confirmStop.value = false
	run(() => api.stopSharing(bundle.value.id, versions()), t('bettersharing', 'Sharing stopped.'))
}
</script>

<style scoped lang="scss">
.bs-editor {
	display: grid;
	gap: 14px;
}

.bs-section {
	display: grid;
	gap: 8px;
}

.bs-box {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.bs-rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	gap: 6px;
}

.bs-row {
	display: grid;
	grid-template-columns: 24px minmax(0, 1fr) auto auto;
	gap: 8px;
	align-items: start;
	padding: 6px 4px 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);

	&__who {
		overflow-wrap: anywhere;
	}

	&__how {
		display: block;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}
}

.bs-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;

	&--split {
		justify-content: space-between;
	}
}

.bs-error-box {
	display: grid;
	gap: 6px;
	justify-items: start;
}

.bs-saved {
	color: var(--color-success-text);
	margin: 0;
}
</style>
