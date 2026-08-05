<template>
	<div class="fw-shell" :class="`fw-shell--${layout}`">
		<!-- Mobile: the panel collapses behind a button so it never pushes
		     the results off-screen on a phone. -->
		<div class="fw-shell__mobile-bar">
			<NcButton type="secondary" @click="drawerOpen = true">
				<template #icon>
					<FilterVariant :size="20" />
				</template>
				{{ activeCount > 0
					? t('intravox', 'Filters ({count})', { count: activeCount })
					: t('intravox', 'Filters') }}
			</NcButton>
		</div>

		<aside class="fw-shell__panel">
			<slot name="panel" />
		</aside>

		<div class="fw-shell__main">
			<slot name="chips" />
			<slot name="results" />
		</div>

		<NcModal
			v-if="drawerOpen"
			size="normal"
			:name="t('intravox', 'Filters')"
			@close="drawerOpen = false">
			<div class="fw-shell__drawer">
				<slot name="panel" />
				<NcButton type="primary" class="fw-shell__drawer-done" @click="drawerOpen = false">
					{{ t('intravox', 'Show results') }}
				</NcButton>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import { translate } from '@nextcloud/l10n'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'

export default {
	name: 'FilterableWidgetShell',

	components: {
		FilterVariant,
		NcButton,
		NcModal,
	},

	props: {
		/** 'sidebar' puts the panel beside the results, 'top' above them. */
		layout: {
			type: String,
			default: 'sidebar',
		},
		activeCount: {
			type: Number,
			default: 0,
		},
	},

	data() {
		return {
			drawerOpen: false,
		}
	},

	methods: {
		t: translate,
	},
}
</script>

<style scoped>
.fw-shell {
	display: grid;
	gap: 20px;
}

.fw-shell--sidebar {
	grid-template-columns: minmax(200px, 240px) minmax(0, 1fr);
	align-items: start;
}

.fw-shell--top {
	grid-template-columns: minmax(0, 1fr);
}

.fw-shell__panel {
	min-width: 0;
}

.fw-shell__main {
	min-width: 0;
}

.fw-shell__mobile-bar {
	display: none;
}

.fw-shell__drawer {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.fw-shell__drawer-done {
	align-self: flex-end;
}

/* The widget can sit in a narrow page column, so this deliberately keys off
   the container rather than the viewport where supported. */
@container (max-width: 700px) {
	.fw-shell--sidebar {
		grid-template-columns: minmax(0, 1fr);
	}
}

@media (max-width: 768px) {
	.fw-shell--sidebar,
	.fw-shell--top {
		grid-template-columns: minmax(0, 1fr);
	}

	.fw-shell__panel {
		display: none;
	}

	.fw-shell__mobile-bar {
		display: block;
		margin-bottom: 12px;
	}
}
</style>
