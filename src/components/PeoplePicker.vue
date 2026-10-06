<template>
	<div class="bs-people">
		<div class="bs-people__field">
			<NcTextField v-model="query"
				:label="t('bettersharing', 'Name or email address')"
				:placeholder="t('bettersharing', 'Dana Whitfield or client@example.com')"
				autocomplete="off"
				@keydown.enter.prevent="addTyped" />
			<NcButton @click="addTyped">
				{{ t('bettersharing', 'Add') }}
			</NcButton>
		</div>
		<ul v-if="suggestions.length > 0" class="bs-people__suggestions" :aria-label="t('bettersharing', 'Suggestions')">
			<li v-for="s in suggestions" :key="s.type + s.id">
				<button type="button" @click="add(s)">
					<NcIconSvgWrapper :path="iconFor(s.type)" :size="18" />
					<span class="bs-people__label">{{ s.label }}</span>
					<span class="bs-tag" :class="s.type === 'email' ? 'out' : 'firm'">{{ tagFor(s.type) }}</span>
				</button>
			</li>
		</ul>
		<p v-if="error" class="bs-error" role="alert">
			{{ error }}
		</p>
		<NcNoteCard v-if="outsideProblem" type="info" :text="outsideProblem" />
		<ul class="bs-people__list">
			<li v-for="(p, i) in modelValue" :key="p.type + p.id" class="bs-person">
				<NcIconSvgWrapper :path="iconFor(p.type)" :size="20" />
				<span class="bs-person__name">
					{{ p.label }}
					<span v-if="detail" class="bs-person__how">{{ detail(p) }}</span>
				</span>
				<span class="bs-tag" :class="p.type === 'email' ? 'out' : 'firm'">{{ tagFor(p.type) }}</span>
				<NcButton variant="tertiary" :aria-label="t('bettersharing', 'Remove {name}', { name: p.label })" @click="remove(i)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script setup>
import { mdiAccount, mdiAccountGroup, mdiClose, mdiEmailOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref, watch } from 'vue'
import { api } from '../api.js'

const props = defineProps({
	modelValue: { type: Array, required: true },
	outsideProblem: { type: String, default: null },
	detail: { type: Function, default: null },
})
const emit = defineEmits(['update:modelValue'])

const query = ref('')
const suggestions = ref([])
const error = ref('')
let timer = null
let seq = 0

watch(query, (value) => {
	error.value = ''
	clearTimeout(timer)
	if (value.trim().length < 2) {
		suggestions.value = []
		return
	}
	const mine = ++seq
	timer = setTimeout(async () => {
		try {
			const found = await api.searchRecipients(value.trim())
			if (mine === seq) {
				suggestions.value = found.filter((s) => !props.modelValue.some((p) => p.type === s.type && p.id === s.id))
			}
		} catch (e) {
			error.value = e.message
		}
	}, 250)
})

function iconFor(type) {
	return type === 'email' ? mdiEmailOutline : type === 'group' ? mdiAccountGroup : mdiAccount
}

function tagFor(type) {
	return type === 'email'
		? t('bettersharing', 'Outside the firm')
		: type === 'group' ? t('bettersharing', 'Firm group') : t('bettersharing', 'At your firm')
}

function add(s) {
	if (props.modelValue.some((p) => p.type === s.type && p.id === s.id)) {
		error.value = t('bettersharing', '{name} is already on the list.', { name: s.label })
		return
	}
	emit('update:modelValue', [...props.modelValue, s])
	query.value = ''
	suggestions.value = []
}

async function addTyped() {
	const typed = query.value.trim()
	if (typed === '') {
		return
	}
	const exact = suggestions.value.find((s) => s.label.toLowerCase() === typed.toLowerCase() || s.id.toLowerCase() === typed.toLowerCase())
	if (exact) {
		add(exact)
		return
	}
	try {
		const found = await api.searchRecipients(typed)
		const match = found.find((s) => s.label.toLowerCase() === typed.toLowerCase() || s.id.toLowerCase() === typed.toLowerCase())
			?? (found.length === 1 ? found[0] : null)
		if (match) {
			add(match)
		} else if (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(typed)) {
			error.value = props.outsideProblem ?? t('bettersharing', '{address} is not a valid email address.', { address: typed })
		} else {
			error.value = t('bettersharing', 'No one at your firm has that name. For someone outside the firm, enter their email address.')
		}
	} catch (e) {
		error.value = e.message
	}
}

function remove(i) {
	const next = [...props.modelValue]
	next.splice(i, 1)
	emit('update:modelValue', next)
}

/** Lets the dialog validate a name typed but not yet added. */
defineExpose({ pending: () => query.value.trim(), addTyped })
</script>

<style scoped lang="scss">
.bs-people {
	display: grid;
	gap: 10px;

	&__field {
		display: flex;
		gap: 8px;
		align-items: flex-end;

		> :first-child {
			flex: 1;
		}
	}

	&__suggestions {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: 4px;
		margin: 0;
		list-style: none;

		button {
			display: flex;
			align-items: center;
			gap: 10px;
			width: 100%;
			background: none;
			border: 0;
			padding: 8px;
			border-radius: var(--border-radius);
			cursor: pointer;
			text-align: start;
			margin: 0;
			min-height: var(--default-clickable-area);
			height: auto;
			font-weight: normal;
			color: var(--color-main-text);

			&:hover,
			&:focus-visible {
				background: var(--color-background-hover);
			}
		}
	}

	&__label {
		flex: 1;
		overflow-wrap: anywhere;
	}

	&__list {
		display: grid;
		gap: 6px;
		margin: 0;
		padding: 0;
		list-style: none;
	}
}

.bs-person {
	display: grid;
	grid-template-columns: 24px minmax(0, 1fr) auto auto;
	gap: 10px;
	align-items: center;
	padding: 4px 4px 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);

	&__name {
		overflow-wrap: anywhere;
	}

	&__how {
		display: block;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
