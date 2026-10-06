<template>
	<fieldset class="bs-expiry">
		<legend class="bs-label">
			{{ t('bettersharing', 'Links for people outside the firm end') }}
		</legend>
		<NcCheckboxRadioSwitch v-for="d in dayOptions"
			:key="d"
			type="radio"
			:name="name"
			:model-value="choice"
			:value="String(d)"
			@update:model-value="pickDays(d)">
			{{ n('bettersharing', 'In %n day', 'In %n days', d) }}
			<span class="bs-expiry__when">{{ formatDate(isoDate(daysFromNow(d))) }}</span>
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch type="radio"
			:name="name"
			:model-value="choice"
			value="date"
			@update:model-value="pickDate">
			{{ t('bettersharing', 'On a specific date') }}
		</NcCheckboxRadioSwitch>
		<div v-if="choice === 'date'" class="bs-expiry__date">
			<NcDateTimePickerNative :model-value="dateValue"
				type="date"
				:label="t('bettersharing', 'End date')"
				:min="daysFromNow(1)"
				@update:model-value="setDate" />
		</div>
		<NcCheckboxRadioSwitch type="radio"
			:name="name"
			:model-value="choice"
			value="never"
			@update:model-value="$emit('update:modelValue', null)">
			{{ t('bettersharing', 'Never') }}
		</NcCheckboxRadioSwitch>
	</fieldset>
</template>

<script setup>
import { n, t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { computed, ref } from 'vue'
import { daysFromNow, formatDate, isoDate } from '../util.js'

/** modelValue is a YYYY-MM-DD date or null for never. */
const props = defineProps({
	modelValue: { type: String, default: null },
	presetDays: { type: Number, default: null },
})
const emit = defineEmits(['update:modelValue'])

const name = `bs-expiry-${Math.random().toString(36).slice(2)}`
const forcedDate = ref(false)

const dayOptions = computed(() => {
	const set = new Set([7, 14, 30])
	if (props.presetDays) {
		set.add(props.presetDays)
	}
	return [...set].sort((a, b) => a - b)
})

const choice = computed(() => {
	if (props.modelValue === null) {
		return 'never'
	}
	if (!forcedDate.value) {
		for (const d of dayOptions.value) {
			if (isoDate(daysFromNow(d)) === props.modelValue) {
				return String(d)
			}
		}
	}
	return 'date'
})

const dateValue = computed(() => props.modelValue ? new Date(`${props.modelValue}T12:00:00`) : daysFromNow(14))

function pickDays(d) {
	forcedDate.value = false
	emit('update:modelValue', isoDate(daysFromNow(d)))
}

function pickDate() {
	forcedDate.value = true
	emit('update:modelValue', props.modelValue ?? isoDate(daysFromNow(14)))
}

function setDate(value) {
	if (value instanceof Date && !isNaN(value)) {
		emit('update:modelValue', isoDate(value))
	}
}
</script>

<style scoped lang="scss">
.bs-expiry {
	border: 0;
	margin: 0;
	padding: 0;

	&__when {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		margin-inline-start: 6px;
		font-variant-numeric: tabular-nums;
	}

	&__date {
		margin: 2px 0 6px 34px;
		max-width: 220px;
	}
}
</style>
