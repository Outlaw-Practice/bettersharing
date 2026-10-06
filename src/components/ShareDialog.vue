<template>
	<NcDialog :name="t('bettersharing', 'Share “{name}”', { name: node.basename })"
		size="normal"
		close-on-click-outside
		@update:open="(open) => !open && close()">
		<div class="bs-dialog">
			<NcLoadingIcon v-if="loading" :size="32" />
			<NcNoteCard v-else-if="status && status.problem" type="warning" :text="status.problem" />

			<!-- Results after Share -->
			<div v-else-if="bundle" class="bs-results">
				<p class="bs-q">
					{{ resultHeading }}
				</p>
				<ul class="bs-lines">
					<li v-for="r in bundle.recipients" :key="r.id" :class="'is-' + r.status">
						<NcIconSvgWrapper :path="r.status === 'failed' ? mdiAlertCircleOutline : mdiCheckCircleOutline" :size="20" />
						<span>
							<strong>{{ r.display }}</strong>
							<span class="bs-lines__how">{{ resultText(r) }}</span>
						</span>
					</li>
				</ul>
				<p v-if="hasOutside" class="bs-note">
					{{ t('bettersharing', 'People outside your firm get an email with the link, then a second email with the password. If an email cannot be delivered, Nextcloud records it in the server log.') }}
				</p>
			</div>

			<template v-else>
				<Stepper :steps="steps" :current="step" @go="go" />

				<section v-if="step === 0" class="bs-step">
					<div>
						<p class="bs-q">
							{{ t('bettersharing', 'Who are you sharing this with?') }}
						</p>
						<p class="bs-sub">
							{{ t('bettersharing', 'Names of people at your firm, or email addresses for anyone else.') }}
						</p>
					</div>
					<PeoplePicker ref="picker" v-model="recipients" :outside-problem="status.outsideProblem" />
				</section>

				<section v-if="step === 1" class="bs-step">
					<div>
						<p class="bs-q">
							{{ t('bettersharing', 'What can they do?') }}
						</p>
						<p class="bs-sub">
							{{ t('bettersharing', 'Your firm’s presets. Pick one, or customize it.') }}
						</p>
					</div>
					<PresetPicker v-model="presetId" :presets="presets" :custom="custom" @pick="applyPreset" />
					<div>
						<NcButton variant="tertiary" :aria-expanded="showCustom ? 'true' : 'false'" @click="showCustom = !showCustom">
							{{ showCustom ? t('bettersharing', 'Hide details') : t('bettersharing', 'Customize') }}
						</NcButton>
					</div>
					<div v-if="showCustom" class="bs-box">
						<PermissionChecklist v-model="permissions" :is-folder="isFolder" />
						<ExpiryChoice v-if="hasOutside" v-model="expireDate" :preset-days="currentPreset?.expireDays ?? null" />
					</div>
				</section>

				<section v-if="step === 2" class="bs-step">
					<div>
						<p class="bs-q">
							{{ t('bettersharing', 'Name and message') }}
						</p>
						<p v-if="currentPreset" class="bs-sub">
							{{ t('bettersharing', 'Filled in from {preset}. Change anything you like.', { preset: currentPreset.name }) }}
						</p>
					</div>
					<NcTextField v-model="name"
						:label="t('bettersharing', 'Share name')"
						:helper-text="t('bettersharing', 'Shown at the top of the page people outside your firm open.')" />
					<NcTextArea v-model="message"
						:label="t('bettersharing', 'Message for people with access')"
						:helper-text="t('bettersharing', 'Shown every time they open the share, and in the email.')"
						:placeholder="t('bettersharing', 'Upload your signed engagement letter and a copy of your ID.')"
						resize="vertical"
						rows="5" />
				</section>

				<section v-if="step === 3" class="bs-step">
					<div>
						<p class="bs-q">
							{{ t('bettersharing', 'Review') }}
						</p>
						<p class="bs-sub">
							{{ t('bettersharing', 'Nothing is shared until you select Share.') }}
						</p>
					</div>
					<div class="bs-summary">
						<p><strong>{{ name }}</strong> <span class="bs-sub">{{ presetLabel }}</span></p>
						<ul class="bs-lines">
							<li v-for="r in recipients" :key="r.type + r.id">
								<NcIconSvgWrapper :path="r.type === 'email' ? mdiEmailOutline : mdiAccount" :size="20" />
								<span>
									<strong>{{ r.label }}</strong>
									<span class="bs-lines__how">{{ howLine(r) }}</span>
								</span>
							</li>
						</ul>
						<p v-if="message.trim()" class="bs-sub">
							{{ t('bettersharing', 'Message: {message}', { message: truncate(message.trim(), 140) }) }}
						</p>
					</div>
				</section>

				<p v-if="error" class="bs-error" role="alert">
					{{ error }}
				</p>
			</template>
		</div>

		<template #actions>
			<template v-if="bundle">
				<NcButton v-if="failedCount > 0" :disabled="busy" @click="retry">
					{{ t('bettersharing', 'Retry') }}
				</NcButton>
				<NcButton variant="primary" @click="close">
					{{ t('bettersharing', 'Done') }}
				</NcButton>
			</template>
			<template v-else-if="!loading && status && !status.problem">
				<NcButton v-if="step > 0" @click="go(step - 1)">
					{{ t('bettersharing', 'Back') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy" @click="next">
					{{ step === 3 ? t('bettersharing', 'Share') : t('bettersharing', 'Next') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script setup>
import { mdiAccount, mdiAlertCircleOutline, mdiCheckCircleOutline, mdiEmailOutline } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { canText, daysFromNow, formatDate, isoDate, newKey, READ } from '../util.js'
import ExpiryChoice from './ExpiryChoice.vue'
import PeoplePicker from './PeoplePicker.vue'
import PermissionChecklist from './PermissionChecklist.vue'
import PresetPicker from './PresetPicker.vue'
import Stepper from './Stepper.vue'

const props = defineProps({
	/** { fileid, basename, type } of the item being shared */
	node: { type: Object, required: true },
	initialPresetId: { type: Number, default: null },
})
const emit = defineEmits(['close'])

const steps = [t('bettersharing', 'People'), t('bettersharing', 'Access'), t('bettersharing', 'Name and message'), t('bettersharing', 'Review')]
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const status = ref(null)
const presets = ref([])
const step = ref(0)
const picker = ref(null)

const recipients = ref([])
const presetId = ref(null)
const permissions = ref(READ)
const expireDate = ref(isoDate(daysFromNow(14)))
const name = ref(props.node.basename)
const message = ref('')
const showCustom = ref(false)
const bundle = ref(null)
let key = null
let lastPayload = null

const isFolder = computed(() => props.node.type === 'folder')
const hasOutside = computed(() => recipients.value.some((r) => r.type === 'email'))
const currentPreset = computed(() => presets.value.find((p) => p.id === presetId.value) ?? null)
const custom = computed(() => {
	const p = currentPreset.value
	if (!p) {
		return true
	}
	const presetDate = p.expireDays ? isoDate(daysFromNow(p.expireDays)) : null
	return p.permissions !== permissions.value || (hasOutside.value && presetDate !== expireDate.value)
})
const presetLabel = computed(() => custom.value
	? (currentPreset.value ? t('bettersharing', 'Custom (from {preset})', { preset: currentPreset.value.name }) : t('bettersharing', 'Custom'))
	: currentPreset.value?.name ?? '')
const failedCount = computed(() => bundle.value?.recipients.filter((r) => r.status === 'failed').length ?? 0)
const resultHeading = computed(() => {
	const ok = bundle.value.recipients.filter((r) => r.status === 'shared').length
	return failedCount.value > 0
		? n('bettersharing', 'Shared with %n person. Some people could not be added.', 'Shared with %n people. Some people could not be added.', ok)
		: n('bettersharing', 'Shared with %n person', 'Shared with %n people', ok)
})

onMounted(async () => {
	try {
		status.value = await api.status()
		if (!status.value.problem) {
			presets.value = await api.presets()
			// Only a preset chosen from the Presets tab is preselected; otherwise people pick one.
			const first = presets.value.find((p) => p.id === props.initialPresetId)
			if (first) {
				applyPreset(first)
			}
		}
	} catch (e) {
		status.value = { problem: e.message }
	} finally {
		loading.value = false
	}
})

function applyPreset(p) {
	presetId.value = p.id
	permissions.value = p.permissions
	expireDate.value = p.expireDays ? isoDate(daysFromNow(p.expireDays)) : null
	message.value = p.message ?? ''
}

function truncate(text, max) {
	return text.length > max ? text.slice(0, max).trim() + ' ' + t('bettersharing', '(continues)') : text
}

function howLine(r) {
	if (r.type === 'email') {
		const ends = expireDate.value ? t('bettersharing', 'Ends {date}.', { date: formatDate(expireDate.value) }) : t('bettersharing', 'No end date.')
		return t('bettersharing', 'Link by email, password in a separate email.') + ' ' + canText(permissions.value) + '. ' + ends
	}
	let line = t('bettersharing', 'Sees it in their Files.') + ' ' + canText(permissions.value | READ) + '.'
	if (!(permissions.value & READ)) {
		line += ' ' + t('bettersharing', 'People at your firm always get view and download.')
	}
	return line
}

function resultText(r) {
	return r.status === 'existing'
		? t('bettersharing', 'Already has access. Their existing share was not changed.')
		: (r.result ?? '')
}

async function validate() {
	error.value = ''
	if (step.value === 0) {
		if (picker.value?.pending()) {
			await picker.value.addTyped()
			if (picker.value.pending()) {
				return false
			}
		}
		if (recipients.value.length === 0) {
			error.value = t('bettersharing', 'Add at least one person.')
			return false
		}
	}
	if (step.value === 1) {
		if (presetId.value === null && !showCustom.value) {
			error.value = t('bettersharing', 'Choose a preset, or select Customize to set access yourself.')
			return false
		}
		if (permissions.value === 0) {
			error.value = t('bettersharing', 'Choose at least one thing they can do.')
			return false
		}
		if (hasOutside.value && expireDate.value && expireDate.value <= isoDate(new Date())) {
			error.value = t('bettersharing', 'Pick an end date after today.')
			return false
		}
	}
	if (step.value === 2 && name.value.trim() === '') {
		error.value = t('bettersharing', 'Give the share a name.')
		return false
	}
	return true
}

async function next() {
	if (!(await validate())) {
		return
	}
	if (step.value < 3) {
		step.value++
		return
	}
	await share()
}

function go(i) {
	error.value = ''
	step.value = i
}

async function share() {
	const payload = {
		fileId: props.node.fileid,
		recipients: recipients.value.map((r) => ({ type: r.type, id: r.id })),
		presetId: presetId.value,
		permissions: permissions.value,
		expireDate: hasOutside.value ? expireDate.value : null,
		name: name.value.trim(),
		message: message.value.trim(),
	}
	const json = JSON.stringify(payload)
	if (json !== lastPayload) {
		key = newKey() // a changed request is a new share; the same request repeats safely
		lastPayload = json
	}
	busy.value = true
	error.value = ''
	try {
		bundle.value = await api.createBundle({ ...payload, idempotencyKey: key })
	} catch (e) {
		error.value = e.message
	} finally {
		busy.value = false
	}
}

async function retry() {
	busy.value = true
	try {
		bundle.value = await api.retry(bundle.value.id)
	} catch (e) {
		error.value = e.message
	} finally {
		busy.value = false
	}
}

function close() {
	emit('close', bundle.value)
}
</script>

<style scoped lang="scss">
.bs-dialog {
	display: grid;
	gap: 16px;
	padding-bottom: 8px;
}

.bs-step {
	display: grid;
	gap: 14px;
}

.bs-box {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	display: grid;
	gap: 12px;
}

.bs-summary {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 12px 14px;
	display: grid;
	gap: 8px;

	p {
		margin: 0;
	}
}

.bs-results {
	display: grid;
	gap: 12px;

	.is-failed strong {
		color: var(--color-error-text);
	}
}
</style>
