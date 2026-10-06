<template>
	<div class="bs-picker">
		<template v-if="selected && !selected.pinned && !custom">
			<p class="bs-label">
				{{ t('bettersharing', 'Selected') }}
			</p>
			<PresetRow :preset="selected" :checked="true" @pick="pick" />
		</template>
		<template v-if="pinned.length > 0">
			<p class="bs-label">
				{{ t('bettersharing', 'Pinned by your firm') }}
			</p>
			<div class="bs-picker__rows" role="radiogroup" :aria-label="t('bettersharing', 'Pinned presets')">
				<PresetRow v-for="p in pinned"
					:key="p.id"
					:preset="p"
					:checked="!custom && p.id === modelValue"
					@pick="pick" />
			</div>
		</template>
		<div class="bs-picker__all">
			<p class="bs-label">
				{{ n('bettersharing', 'All %n preset', 'All %n presets', presets.length) }}
			</p>
			<div class="bs-tools">
				<NcTextField v-model="query"
					:label="t('bettersharing', 'Search presets')"
					:placeholder="t('bettersharing', 'Search by name or purpose')"
					trailing-button-icon="close"
					:show-trailing-button="query !== ''"
					@trailing-button-click="query = ''" />
				<label class="bs-tools__sort">
					<span class="hidden-visually">{{ t('bettersharing', 'Sort presets') }}</span>
					<select v-model="sort">
						<option v-for="s in PRESET_SORTS" :key="s.id" :value="s.id">{{ s.label }}</option>
					</select>
				</label>
			</div>
			<div class="bs-picker__rows">
				<PresetRow v-for="p in shown"
					:key="p.id"
					:preset="p"
					:checked="!custom && p.id === modelValue"
					:meta="true"
					@pick="pick" />
			</div>
			<p v-if="results.length === 0" class="bs-note">
				{{ t('bettersharing', 'No presets match "{query}". Try another word, or customize the settings yourself.', { query: query.trim() }) }}
			</p>
			<div v-if="shown.length < results.length">
				<NcButton variant="tertiary" @click="showAll = true">
					{{ n('bettersharing', 'Show all %n preset', 'Show all %n presets', results.length) }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script setup>
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'
import { matchesPreset, PRESET_SORTS, sortPresets } from '../util.js'
import PresetRow from './PresetRow.vue'

const props = defineProps({
	presets: { type: Array, required: true },
	modelValue: { type: Number, default: null },
	custom: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'pick'])

const query = ref('')
const sort = ref('used')
const showAll = ref(false)
watch([query, sort], () => { showAll.value = false })

const pinned = computed(() => props.presets.filter((p) => p.pinned))
const selected = computed(() => props.presets.find((p) => p.id === props.modelValue) ?? null)
const results = computed(() => sortPresets(props.presets.filter((p) => matchesPreset(p, query.value)), sort.value))
const shown = computed(() => (query.value.trim() || showAll.value) ? results.value : results.value.slice(0, 5))

function pick(preset) {
	emit('update:modelValue', preset.id)
	emit('pick', preset)
}
</script>

<style scoped lang="scss">
.bs-picker {
	display: grid;
	gap: 8px;

	&__rows {
		display: grid;
		gap: 8px;
	}

	&__all {
		display: grid;
		gap: 8px;
		margin-top: 6px;
	}
}
</style>
