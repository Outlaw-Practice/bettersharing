<template>
	<ol class="bs-steps" :aria-label="t('bettersharing', 'Progress')">
		<li v-for="(label, i) in steps"
			:key="label"
			:class="{ done: i < current, on: i === current }"
			:aria-current="i === current ? 'step' : undefined">
			<button v-if="i < current"
				type="button"
				class="bs-steps__dot"
				:aria-label="t('bettersharing', 'Go back to {step}', { step: label })"
				@click="$emit('go', i)">
				<NcIconSvgWrapper :path="mdiCheck" :size="18" />
			</button>
			<span v-else class="bs-steps__dot">{{ i + 1 }}</span>
			<span class="bs-steps__label">{{ label }}</span>
		</li>
	</ol>
</template>

<script setup>
import { mdiCheck } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

defineProps({
	steps: { type: Array, required: true },
	current: { type: Number, required: true },
})
defineEmits(['go'])
</script>

<style scoped lang="scss">
.bs-steps {
	list-style: none;
	margin: 0;
	padding: 0 0 14px;
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	border-bottom: 1px solid var(--color-border);

	li {
		position: relative;
		display: grid;
		justify-items: center;
		align-content: start;
		gap: 6px;
		text-align: center;
		font-size: 13px;
		line-height: 1.3;
		color: var(--color-text-maxcontrast);

		&::before {
			content: '';
			position: absolute;
			top: 13px;
			inset-inline: calc(-50% + 20px) calc(50% + 20px);
			height: 2px;
			background: var(--color-border-dark);
		}

		&:first-child::before {
			display: none;
		}

		&.done::before,
		&.on::before {
			background: var(--color-primary-element);
		}

		&.done,
		&.on {
			color: var(--color-main-text);
		}

		&.on .bs-steps__label {
			font-weight: bold;
		}
	}

	&__dot {
		width: 28px;
		height: 28px;
		min-height: 28px;
		border-radius: 50%;
		border: 2px solid var(--color-border-dark);
		display: grid;
		place-items: center;
		font-weight: bold;
		font-size: 13px;
		background: var(--color-main-background);
		padding: 0;
		margin: 0;
		font-variant-numeric: tabular-nums;
	}

	li.done &__dot {
		background: var(--color-primary-element);
		border-color: var(--color-primary-element);
		color: var(--color-primary-element-text);
		cursor: pointer;
	}

	li.on &__dot {
		border-color: var(--color-primary-element);
		color: var(--color-primary-element);
		box-shadow: 0 0 0 4px var(--color-primary-element-light);
	}
}

@media (max-width: 480px) {
	.bs-steps li:not(.on) .bs-steps__label {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip: rect(0 0 0 0);
	}
}
</style>
