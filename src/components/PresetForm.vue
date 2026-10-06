<template>
	<form class="bs-pform" @submit.prevent="save">
		<p class="bs-label">
			{{ preset ? t('bettersharing', 'Edit preset') : t('bettersharing', 'New preset') }}
		</p>
		<NcTextField v-model="name" :label="t('bettersharing', 'Name')" :placeholder="t('bettersharing', 'Signed documents back')" />
		<PermissionChecklist v-model="permissions" :legend="t('bettersharing', 'People can')" />
		<div>
			<label class="bs-label" :for="selectId">{{ t('bettersharing', 'Links for people outside the firm end') }}</label>
			<select :id="selectId" v-model="days">
				<option v-for="d in dayOptions" :key="d" :value="d">
					{{ d ? n('bettersharing', 'After %n day', 'After %n days', d) : t('bettersharing', 'Never') }}
				</option>
			</select>
			<p class="bs-note">
				{{ t('bettersharing', 'The person sharing can still pick a specific date. People outside the firm always get a password.') }}
			</p>
		</div>
		<NcTextArea v-model="message"
			:label="t('bettersharing', 'Message template')"
			:placeholder="t('bettersharing', 'Instructions shown to everyone who opens a share made with this preset.')"
			resize="vertical"
			rows="4" />
		<NcCheckboxRadioSwitch v-model="pinned">
			{{ t('bettersharing', 'Pin this preset') }}
			<span class="bs-pform__desc">{{ t('bettersharing', 'Pinned presets appear first when people share. Your firm can pin up to 4.') }}</span>
		</NcCheckboxRadioSwitch>
		<p class="bs-note">
			{{ t('bettersharing', 'Changes apply to new shares. Existing shares keep the settings they were created with.') }}
		</p>
		<p v-if="error" class="bs-error" role="alert">
			{{ error }}
		</p>
		<NcNoteCard v-if="confirmDelete" type="warning">
			<p>{{ t('bettersharing', 'Delete the preset “{name}”? Shares already made with it are not affected.', { name: preset.name }) }}</p>
			<div class="bs-pform__actions">
				<NcButton @click="confirmDelete = false">
					{{ t('bettersharing', 'Keep preset') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy" @click="remove">
					{{ t('bettersharing', 'Delete preset') }}
				</NcButton>
			</div>
		</NcNoteCard>
		<div class="bs-pform__actions bs-pform__actions--split">
			<NcButton v-if="preset" variant="error" @click="confirmDelete = true">
				{{ t('bettersharing', 'Delete') }}
			</NcButton>
			<span v-else />
			<span class="bs-pform__actions">
				<NcButton @click="$emit('cancel')">
					{{ t('bettersharing', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" type="submit" :disabled="busy">
					{{ t('bettersharing', 'Save preset') }}
				</NcButton>
			</span>
		</div>
	</form>
</template>

<script setup>
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'
import { api } from '../api.js'
import { READ } from '../util.js'
import PermissionChecklist from './PermissionChecklist.vue'

const props = defineProps({
	preset: { type: Object, default: null },
})
const emit = defineEmits(['saved', 'cancel'])

const selectId = `bs-days-${Math.random().toString(36).slice(2)}`
const name = ref(props.preset?.name ?? '')
const permissions = ref(props.preset?.permissions ?? READ)
const days = ref(props.preset ? (props.preset.expireDays ?? 0) : 14)
const message = ref(props.preset?.message ?? '')
const pinned = ref(props.preset?.pinned ?? false)
const busy = ref(false)
const error = ref('')
const confirmDelete = ref(false)

const dayOptions = computed(() => [...new Set([7, 14, 30, 60, 90, days.value || 0])].filter((d) => d > 0).sort((a, b) => a - b).concat([0]))

async function save() {
	error.value = ''
	if (name.value.trim() === '') {
		error.value = t('bettersharing', 'Give the preset a name.')
		return
	}
	if (permissions.value === 0) {
		error.value = t('bettersharing', 'Choose at least one thing people can do.')
		return
	}
	busy.value = true
	const data = { name: name.value.trim(), permissions: permissions.value, expireDays: days.value || null, message: message.value.trim(), pinned: pinned.value }
	try {
		if (props.preset) {
			await api.updatePreset(props.preset.id, data)
		} else {
			await api.createPreset(data)
		}
		emit('saved')
	} catch (e) {
		error.value = e.message
	} finally {
		busy.value = false
	}
}

async function remove() {
	busy.value = true
	try {
		await api.deletePreset(props.preset.id)
		emit('saved')
	} catch (e) {
		error.value = e.message
		confirmDelete.value = false
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.bs-pform {
	border: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	padding: 12px 14px;
	display: grid;
	gap: 12px;
	background: var(--color-main-background);

	&__desc {
		display: block;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		white-space: normal;
	}

	&__actions {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;

		&--split {
			justify-content: space-between;
		}
	}
}
</style>
