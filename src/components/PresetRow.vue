<template>
	<button type="button"
		class="bs-preset"
		:class="{ on: checked }"
		role="radio"
		:aria-checked="checked ? 'true' : 'false'"
		@click="$emit('pick', preset)">
		<span class="bs-preset__radio" aria-hidden="true" />
		<span>
			<span class="bs-preset__name">{{ preset.name }}</span>
			<span v-if="meta && preset.pinned" class="bs-pinmark">
				<NcIconSvgWrapper :path="mdiPin" :size="15" />{{ t('bettersharing', 'Pinned') }}
			</span>
			<span class="bs-preset__summary">{{ presetSummary(preset) }}</span>
			<span v-if="meta" class="bs-preset__meta">{{ usage }}</span>
		</span>
	</button>
</template>

<script setup>
import { mdiPin } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { computed } from 'vue'
import { formatDate, presetSummary } from '../util.js'

const props = defineProps({
	preset: { type: Object, required: true },
	checked: { type: Boolean, default: false },
	meta: { type: Boolean, default: false },
})
defineEmits(['pick'])

const usage = computed(() => props.preset.useCount
	? n('bettersharing', 'Used %n time, most recently {date}', 'Used %n times, most recently {date}', props.preset.useCount, { date: formatDate(props.preset.lastUsedAt) })
	: t('bettersharing', 'Not used yet'))
</script>

<style scoped lang="scss">
.bs-preset {
	display: grid;
	grid-template-columns: 20px minmax(0, 1fr);
	gap: 10px;
	text-align: start;
	width: 100%;
	margin: 0;
	padding: 10px 12px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	cursor: pointer;
	font-weight: normal;
	height: auto;

	&:hover {
		border-color: var(--color-text-maxcontrast);
	}

	&.on {
		border: 2px solid var(--color-primary-element);
		padding: 9px 11px;
		background: var(--color-primary-element-light);
	}

	&__radio {
		width: 18px;
		height: 18px;
		border-radius: 50%;
		border: 2px solid var(--color-text-maxcontrast);
		margin-top: 2px;
	}

	&.on &__radio {
		border: 5px solid var(--color-primary-element);
	}

	&__name {
		font-weight: bold;
	}

	&__summary,
	&__meta {
		display: block;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		white-space: normal;
	}
}
</style>
