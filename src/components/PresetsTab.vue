<template>
	<div class="bs-presets">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="problem" type="warning" :text="problem" />
		<template v-else>
			<p class="bs-note">
				{{ intro }}
			</p>
			<NcTextField v-model="query"
				:label="t('bettersharing', 'Search presets')"
				:placeholder="t('bettersharing', 'Search by name or message')"
				trailing-button-icon="close"
				:show-trailing-button="query !== ''"
				@trailing-button-click="query = ''" />

			<p v-if="rows.length === 0" class="bs-note">
				{{ t('bettersharing', 'No presets match "{query}".', { query: query.trim() }) }}
			</p>
			<div v-else class="bs-tablewrap">
				<table class="bs-table">
					<thead>
						<tr>
							<th v-for="c in columns"
								:key="c.id"
								scope="col"
								:class="{ num: c.id !== 'name' }"
								:style="c.width ? { width: c.width } : null"
								:aria-sort="sortKey === c.id ? (sortDir > 0 ? 'ascending' : 'descending') : 'none'">
								<button type="button"
									class="bs-sort"
									:title="sortKey === c.id ? t('bettersharing', 'Sorted {order}. Select to reverse.', { order: sortWords(c.id) }) : t('bettersharing', 'Sort by {column}', { column: c.label.toLowerCase() })"
									@click="sortBy(c.id)">
									{{ c.label }}
									<NcIconSvgWrapper v-if="sortKey === c.id" :path="sortDir > 0 ? mdiMenuUp : mdiMenuDown" :size="20" />
									<span v-if="sortKey === c.id" class="hidden-visually">{{ sortWords(c.id) }}</span>
								</button>
							</th>
						</tr>
					</thead>
					<tbody v-for="g in groups" :key="g.label">
						<tr class="bs-group">
							<th colspan="4" scope="rowgroup">
								{{ g.label }}
							</th>
						</tr>
						<template v-for="p in g.rows" :key="p.id">
							<tr class="bs-row" :class="{ open: open === p.id || editing === p.id }">
								<td>
									<button type="button" class="bs-rowbtn" :aria-expanded="open === p.id ? 'true' : 'false'" @click="toggle(p.id)">
										<span>
											<strong>{{ p.name }}</strong>
											<span v-if="p.pinned" class="bs-pinmark" :title="t('bettersharing', 'Pinned')">
												<NcIconSvgWrapper :path="mdiPin" :size="15" />
												<span class="hidden-visually">{{ t('bettersharing', 'Pinned') }}</span>
											</span>
										</span>
									</button>
								</td>
								<td class="num">
									{{ p.useCount }}
								</td>
								<td class="num">
									{{ p.useCount ? formatDate(p.lastUsedAt) : t('bettersharing', 'Never') }}
								</td>
								<td class="num">
									{{ formatDate(p.updatedAt) }}
								</td>
							</tr>
							<tr v-if="open === p.id || editing === p.id" class="bs-detail">
								<td colspan="4">
									<PresetForm v-if="editing === p.id"
										:preset="p"
										@saved="onSaved"
										@cancel="editing = null" />
									<div v-else class="bs-detail__body">
										<span>{{ presetSummary(p) }}</span>
										<span class="bs-note">{{ p.message ? t('bettersharing', 'Message: {message}', { message: p.message }) : t('bettersharing', 'No message.') }}</span>
										<span class="bs-links">
											<NcButton variant="tertiary" @click="newShare(p)">
												{{ t('bettersharing', 'New Share') }}
											</NcButton>
											<NcButton v-if="isAdmin" variant="tertiary" @click="editing = p.id">
												{{ t('bettersharing', 'Edit Share') }}
											</NcButton>
										</span>
									</div>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>

			<PresetForm v-if="creating" @saved="onSaved" @cancel="creating = false" />
			<div v-else-if="isAdmin">
				<NcButton variant="tertiary" @click="creating = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('bettersharing', 'New preset') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script setup>
import { mdiMenuDown, mdiMenuUp, mdiPin, mdiPlus } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { openShareDialog } from '../dialog.js'
import { formatDate, matchesPreset, presetSummary } from '../util.js'
import PresetForm from './PresetForm.vue'

const props = defineProps({
	node: { type: Object, default: null },
	active: { type: Boolean, default: false },
})

const loading = ref(true)
const problem = ref('')
const presets = ref([])
const firmName = ref('')
const isAdmin = ref(false)
const query = ref('')
const sortKey = ref('name')
const sortDir = ref(1)
const open = ref(null)
const editing = ref(null)
const creating = ref(false)

const columns = [
	{ id: 'name', label: t('bettersharing', 'Preset') },
	{ id: 'uses', label: t('bettersharing', 'Uses'), width: '52px' },
	{ id: 'last', label: t('bettersharing', 'Last used'), width: '72px' },
	{ id: 'changed', label: t('bettersharing', 'Changed'), width: '68px' },
]
const compare = {
	name: (a, b) => a.name.localeCompare(b.name),
	uses: (a, b) => a.useCount - b.useCount,
	last: (a, b) => (a.lastUsedAt ?? 0) - (b.lastUsedAt ?? 0),
	changed: (a, b) => a.updatedAt - b.updatedAt,
}

const intro = computed(() => {
	const base = n('bettersharing', '%n preset for {firm}. Everyone at the firm shares with the same presets.', '%n presets for {firm}. Everyone at the firm shares with the same presets.', presets.value.length, { firm: firmName.value })
	return base + ' ' + (isAdmin.value
		? t('bettersharing', 'You can change them because you are a firm administrator. Select a column heading to sort.')
		: t('bettersharing', 'Your firm’s administrators can change them. Select a column heading to sort.'))
})
const rows = computed(() => presets.value
	.filter((p) => matchesPreset(p, query.value))
	.sort((a, b) => sortDir.value * compare[sortKey.value](a, b) || a.name.localeCompare(b.name)))
const groups = computed(() => {
	const pinned = rows.value.filter((p) => p.pinned)
	const rest = rows.value.filter((p) => !p.pinned)
	return [
		{ label: t('bettersharing', 'Pinned, shown first when sharing'), rows: pinned },
		{ label: pinned.length ? t('bettersharing', 'Other presets') : t('bettersharing', 'Presets'), rows: rest },
	].filter((g) => g.rows.length > 0)
})

async function load() {
	try {
		const status = await api.status()
		problem.value = status.problem ?? ''
		if (!problem.value) {
			firmName.value = status.firm.name
			isAdmin.value = status.isFirmAdmin
			presets.value = await api.presets()
		}
	} catch (e) {
		problem.value = e.message
	} finally {
		loading.value = false
	}
}
onMounted(load)

function sortBy(id) {
	if (sortKey.value === id) {
		sortDir.value = -sortDir.value
	} else {
		sortKey.value = id
		sortDir.value = id === 'name' ? 1 : -1
	}
}

function sortWords(id) {
	if (id === 'name') {
		return sortDir.value > 0 ? t('bettersharing', 'A to Z') : t('bettersharing', 'Z to A')
	}
	if (id === 'uses') {
		return sortDir.value > 0 ? t('bettersharing', 'lowest first') : t('bettersharing', 'highest first')
	}
	return sortDir.value > 0 ? t('bettersharing', 'oldest first') : t('bettersharing', 'newest first')
}

function toggle(id) {
	open.value = open.value === id ? null : id
	if (editing.value && editing.value !== id) {
		editing.value = null
	}
}

async function newShare(p) {
	if (!props.node) {
		return
	}
	await openShareDialog({ fileid: props.node.fileid, basename: props.node.basename, type: props.node.type }, p.id)
	presets.value = await api.presets()
}

async function onSaved() {
	editing.value = null
	creating.value = false
	presets.value = await api.presets()
}
</script>

<style scoped lang="scss">
.bs-presets {
	display: grid;
	gap: 10px;
	padding: 10px 0;
}

.bs-tablewrap {
	overflow-x: auto;
}

.bs-table {
	width: 100%;
	border-collapse: collapse;
	table-layout: fixed;
	font-size: 13px;

	th,
	td {
		padding: 7px 6px;
		text-align: start;
		vertical-align: top;
		border-bottom: 1px solid var(--color-border);
	}

	.num {
		text-align: end;
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}

	thead th {
		font-weight: bold;
		color: var(--color-text-maxcontrast);
		vertical-align: bottom;

		&[aria-sort='ascending'],
		&[aria-sort='descending'] {
			color: var(--color-main-text);
		}
	}
}

.bs-table .bs-sort {
	all: unset;
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	white-space: nowrap;
	background: none;

	:deep(.icon-vue) {
		min-width: 20px;
		min-height: 20px;
	}

	&:hover,
	&:focus-visible {
		color: var(--color-primary-element);
		background: none;
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
	}
}

.bs-group th {
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: .05em;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
}

.bs-table .bs-rowbtn {
	all: unset;
	cursor: pointer;
	display: grid;
	gap: 2px;
	width: 100%;
	white-space: normal;
	overflow-wrap: anywhere;
	background: none;

	.bs-pinmark :deep(.icon-vue) {
		min-width: 15px;
		min-height: 15px;
	}

	&:hover,
	&:focus-visible {
		background: none;
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
	}
}

.bs-row:hover td {
	background: var(--color-background-hover);
}

.bs-row.open td,
.bs-detail td {
	background: var(--color-primary-element-light);
}

.bs-row.open td {
	border-bottom: 0;
}

.bs-detail td {
	white-space: normal;
}

.bs-detail__body {
	display: grid;
	gap: 6px;
	overflow-wrap: anywhere;
}

.bs-links {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
}
</style>
