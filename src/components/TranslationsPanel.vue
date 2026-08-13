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
					<!-- lang: this title is written in the TARGET language, not the
					     UI language — WCAG 3.1.2 wants that asserted so screen
					     readers switch pronunciation. -->
					<button class="translation-title"
							:lang="item.language"
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

			<!-- Create this page in another language. The primary action: the
			     other version usually does not exist yet, and making an editor
			     first create a blank page elsewhere, find it and then link it
			     is the workflow every mature CMS avoids. -->
			<div class="translations-create">
				<label class="translations-create__label" :for="createId">
					{{ t('intravox', 'Create this page in another language') }}
				</label>
				<div class="translations-create__row">
					<select :id="createId"
							v-model="createLanguage"
							class="translations-create__select"
							:disabled="working || availableLanguages.length === 0">
						<option value="">
							{{ availableLanguages.length === 0
								? t('intravox', 'Already in every language')
								: t('intravox', 'Choose a language …') }}
						</option>
						<option v-for="lang in availableLanguages"
								:key="lang.code"
								:value="lang.code">
							{{ lang.name }}
						</option>
					</select>
					<button class="translations-create__button"
							:disabled="working || !createLanguage"
							@click="create">
						{{ t('intravox', 'Create') }}
					</button>
				</div>
				<!-- A deep page translated before its ancestors lands mirrored,
				     with the missing levels shown as non-clickable folders in the
				     tree. Say so BEFORE creating — grey levels should never be a
				     surprise. -->
				<!-- Kept on ONE line deliberately: Nextcloud's translation bot
				     extracts strings with a regex that misses multi-line
				     t()/n() calls. This exact string was absent from Transifex
				     while every single-line sibling in this file made it. -->
				<!-- eslint-disable-next-line max-len -->
				<p v-if="missingAncestorCount > 0" class="translations-create__ancestors">
					{{ n('intravox', '%n parent page does not exist in this language yet — the new page will appear in the same place, with a non-clickable level until you translate that too.', '%n parent pages do not exist in this language yet — the new page will appear in the same place, with non-clickable levels until you translate those too.', missingAncestorCount) }}
				</p>
				<p class="translations-create__hint">
					{{ t('intravox', 'The content is copied as a starting point and saved as a draft. From then on both pages are independent — translating one never changes the other.') }}
				</p>
			</div>

			<!-- Link a page that already exists in another language. -->
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
					<!-- lang: name and title are both in the candidate's own
					     language (language names are autonyms: "Français"). -->
					<option v-for="c in candidates"
							:key="c.uniqueId"
							:value="c.uniqueId"
							:lang="c.language">
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
import { translate, translatePlural } from '@nextcloud/l10n'

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
			availableLanguages: [],
			selected: '',
			createLanguage: '',
			loading: false,
			working: false,
		}
	},
	computed: {
		/**
		 * Ancestors the selected target language is missing, from the
		 * translatable-languages response. 0 when nothing is selected.
		 */
		missingAncestorCount() {
			const lang = this.availableLanguages.find(l => l.code === this.createLanguage)
			return lang?.missingAncestors ?? 0
		},
		selectId() {
			return `translation-target-${this.pageId}`
		},
		createId() {
			return `translation-create-${this.pageId}`
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
		n(app, singular, plural, count, vars) {
			return translatePlural(app, singular, plural, count, vars)
		},
		/**
		 * Display name for a CONTENT language.
		 *
		 * Nextcloud's names describe INTERFACE translations and carry variant
		 * suffixes ('English (US)', 'Deutsch (Persönlich: Du)'). Content folders
		 * are plain codes, so the parenthesised part is dropped — an editor
		 * picking a translation should see 'Deutsch', not a UI-variant label.
		 */
		languageName(code) {
			const full = this.languageNames[code]
			if (!full) {
				return String(code).toUpperCase()
			}
			const base = String(full).split('(')[0].trim()
			return base || String(full)
		},
		async loadCandidates() {
			this.loading = true
			const base = `/apps/intravox/api/pages/${encodeURIComponent(this.pageId)}`
			try {
				const [candidates, languages] = await Promise.all([
					axios.get(generateUrl(`${base}/translation-candidates`)),
					axios.get(generateUrl(`${base}/translatable-languages`)),
				])
				this.candidates = candidates?.data?.candidates || []
				this.availableLanguages = languages?.data?.languages || []
			} catch (err) {
				// A failing picker must not break the sidebar it lives in.
				console.warn('[IntraVox] Could not load translation options:', err.message)
				this.candidates = []
				this.availableLanguages = []
			} finally {
				this.loading = false
			}
		},
		async create() {
			if (!this.createLanguage) {
				return
			}
			this.working = true
			try {
				const url = generateUrl(
					`/apps/intravox/api/pages/${encodeURIComponent(this.pageId)}/translations/create`
				)
				const response = await axios.post(url, { language: this.createLanguage })
				this.translations = response?.data?.translations || []
				const created = response?.data?.page
				this.createLanguage = ''
				await this.loadCandidates()
				this.$emit('changed', this.translations)
				showSuccess(this.t('intravox', 'Translation created as a draft'))
				// Open it straight away: the editor asked to make this page in
				// another language, so the next thing they want is to edit it.
				if (created?.uniqueId) {
					this.$emit('navigate', created.uniqueId)
				}
			} catch (err) {
				showError(err.response?.data?.error
					|| this.t('intravox', 'Could not create the translation'))
			} finally {
				this.working = false
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

.translations-create {
	margin-top: 16px;
	padding: 12px;
	border: 1px solid var(--color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover, #f5f5f5);
}

.translations-create__label {
	display: block;
	margin-bottom: 6px;
	font-weight: 600;
}

.translations-create__row {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.translations-create__select {
	flex: 1 1 auto;
	min-width: 0;
}

.translations-create__button {
	flex: 0 0 auto;
	padding: 4px 12px;
	border: 1px solid var(--color-primary-element, #0082c9);
	border-radius: var(--border-radius, 8px);
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	cursor: pointer;
}

.translations-create__button:disabled {
	opacity: 0.5;
	cursor: default;
}

.translations-create__hint {
	margin: 8px 0 0;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast, #767676);
}

/* Informative, not alarming: missing ancestors are a normal state the tree
   handles, the editor just deserves to know before creating. */
.translations-create__ancestors {
	margin: 8px 0 0;
	font-size: 0.9em;
	color: var(--color-warning-text, var(--color-main-text));
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
