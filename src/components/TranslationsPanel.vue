<template>
	<div class="translations-panel">
		<div v-if="loading" class="loading">
			{{ t('intravox', 'Loading translations …') }}
		</div>

		<template v-else>
			<!-- What this page is linked to today. -->
			<div v-if="translations.length > 0" class="translations-list">
				<p class="translations-intro">
					{{ t('intravox', 'This page also exists in:') }}
				</p>
				<div v-for="item in translations"
					 :key="item.uniqueId"
					 class="translation-row">
					<span class="translation-language">{{ languageName(item.language) }}</span>
					<button class="translation-title"
							:title="t('intravox', 'Open this translation')"
							@click="$emit('navigate', item.uniqueId)">
						{{ item.title }}
					</button>
					<span v-if="item.status === 'draft'" class="translation-draft">
						{{ t('intravox', 'Draft') }}
					</span>
					<button class="translation-unlink"
							:disabled="working"
							:title="t('intravox', 'Unlink this translation')"
							@click="unlink">
						{{ t('intravox', 'Unlink') }}
					</button>
				</div>
			</div>

			<p v-else class="translations-empty">
				{{ t('intravox', 'This page is not linked to a version in another language yet.') }}
			</p>

			<!-- Link an existing page in another language. -->
			<div class="translations-add">
				<label class="translations-add__label" :for="selectId">
					{{ t('intravox', 'Link an existing page as a translation') }}
				</label>
				<select :id="selectId"
						v-model="selected"
						class="translations-add__select"
						:disabled="working || candidates.length === 0">
					<option value="">
						{{ candidates.length === 0
							? t('intravox', 'No pages available to link')
							: t('intravox', 'Choose a page …') }}
					</option>
					<option v-for="c in candidates"
							:key="c.uniqueId"
							:value="c.uniqueId">
						{{ languageName(c.language) }} — {{ c.title }}
					</option>
				</select>
				<button class="translations-add__button"
						:disabled="working || !selected"
						@click="link">
					{{ t('intravox', 'Link') }}
				</button>
			</div>

			<p class="translations-hint">
				{{ t('intravox', 'Linked pages stay separate: each language keeps its own content and layout. Linking only tells readers where else this page exists.') }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'

/**
 * Manage which pages are language versions of each other.
 *
 * The model is symmetric — no source page, no derived translations — so this
 * panel reads the same whichever language version it is opened from, and
 * unlinking never orphans the other side.
 */
export default {
	name: 'TranslationsPanel',
	props: {
		pageId: {
			type: String,
			required: true,
		},
		/** Translations as returned with the page, so the first paint is instant. */
		initialTranslations: {
			type: Array,
			default: () => [],
		},
		/** language code => display name, from the content-status endpoint. */
		languageNames: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['navigate', 'changed'],
	data() {
		return {
			translations: [...this.initialTranslations],
			candidates: [],
			selected: '',
			loading: false,
			working: false,
		}
	},
	computed: {
		selectId() {
			return `translation-target-${this.pageId}`
		},
	},
	watch: {
		pageId: {
			immediate: true,
			handler() {
				this.translations = [...this.initialTranslations]
				this.loadCandidates()
			},
		},
	},
	methods: {
		t(app, text, vars) {
			return translate(app, text, vars)
		},
		languageName(code) {
			return this.languageNames[code] || String(code).toUpperCase()
		},
		async loadCandidates() {
			this.loading = true
			try {
				const url = generateUrl(
					`/apps/intravox/api/pages/${encodeURIComponent(this.pageId)}/translation-candidates`
				)
				const response = await axios.get(url)
				this.candidates = response?.data?.candidates || []
			} catch (err) {
				// A failing picker must not break the sidebar it lives in.
				console.warn('[IntraVox] Could not load translation candidates:', err.message)
				this.candidates = []
			} finally {
				this.loading = false
			}
		},
		async link() {
			if (!this.selected) {
				return
			}
			this.working = true
			try {
				const url = generateUrl(
					`/apps/intravox/api/pages/${encodeURIComponent(this.pageId)}/translations`
				)
				const response = await axios.post(url, { targetUniqueId: this.selected })
				this.translations = response?.data?.translations || []
				this.selected = ''
				await this.loadCandidates()
				this.$emit('changed', this.translations)
				showSuccess(this.t('intravox', 'Translation linked'))
			} catch (err) {
				showError(err.response?.data?.error
					|| this.t('intravox', 'Could not link the translation'))
			} finally {
				this.working = false
			}
		},
		async unlink() {
			this.working = true
			try {
				const url = generateUrl(
					`/apps/intravox/api/pages/${encodeURIComponent(this.pageId)}/translations`
				)
				await axios.delete(url)
				this.translations = []
				await this.loadCandidates()
				this.$emit('changed', [])
				showSuccess(this.t('intravox', 'Translation unlinked'))
			} catch (err) {
				showError(err.response?.data?.error
					|| this.t('intravox', 'Could not unlink the translation'))
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.translations-panel {
	padding: 8px 0;
}

.translations-intro,
.translations-empty,
.translations-hint {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast, #767676);
}

.translations-hint {
	margin-top: 16px;
	font-size: 0.9em;
}

.translation-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border, #dbdbdb);
}

.translation-language {
	flex: 0 0 auto;
	font-weight: 600;
}

.translation-title {
	flex: 1 1 auto;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	text-align: left;
	font-size: inherit;
}

.translation-title:hover {
	text-decoration: underline;
}

.translation-draft {
	flex: 0 0 auto;
	padding: 1px 6px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-warning-light, #fff3cd);
	color: var(--color-warning-text, #6d5003);
	font-size: 0.8em;
}

.translation-unlink,
.translations-add__button {
	flex: 0 0 auto;
	padding: 4px 10px;
	border: 1px solid var(--color-border-dark, #c9c9c9);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	cursor: pointer;
}

.translation-unlink:disabled,
.translations-add__button:disabled {
	opacity: 0.5;
	cursor: default;
}

.translations-add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-top: 16px;
	flex-wrap: wrap;
}

.translations-add__label {
	flex: 1 0 100%;
	margin-bottom: 4px;
}

.translations-add__select {
	flex: 1 1 auto;
	min-width: 0;
}
</style>
