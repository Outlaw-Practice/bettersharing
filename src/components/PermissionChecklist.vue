<template>
	<fieldset class="bs-checks">
		<legend class="bs-label">
			{{ legend }}
		</legend>
		<NcCheckboxRadioSwitch v-for="p in visible"
			:key="p.bit"
			:model-value="(modelValue & p.bit) !== 0"
			@update:model-value="(on) => $emit('update:modelValue', togglePermission(modelValue, p.bit, on))">
			<span class="bs-checks__label">{{ p.label }}</span>
			<span class="bs-checks__desc">{{ p.description }}</span>
		</NcCheckboxRadioSwitch>
		<p v-if="isUploadOnly(modelValue)" class="bs-note">
			{{ t('bettersharing', 'Upload only: people outside the firm can add files but cannot see anything in the folder, including what they uploaded. People at your firm always get view and download.') }}
		</p>
	</fieldset>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { computed } from 'vue'
import { isUploadOnly, PERMISSIONS, togglePermission } from '../util.js'

const props = defineProps({
	modelValue: { type: Number, required: true },
	isFolder: { type: Boolean, default: true },
	legend: { type: String, default: () => t('bettersharing', 'They can') },
})
defineEmits(['update:modelValue'])

const visible = computed(() => PERMISSIONS.filter((p) => props.isFolder || !p.folderOnly))
</script>

<style scoped lang="scss">
.bs-checks {
	border: 0;
	margin: 0;
	padding: 0;
	display: grid;
	gap: 2px;

	&__label {
		display: block;
	}

	&__desc {
		display: block;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		white-space: normal;
	}
}
</style>
