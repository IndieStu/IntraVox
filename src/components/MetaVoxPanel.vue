<template>
	<div class="metavox-panel">
		<div v-if="loading" class="loading">
			{{ t('intravox', 'Loading metadata …') }}
		</div>

		<div v-else-if="error" class="metavox-panel__error">
			{{ error }}
		</div>

		<p v-else-if="fields.length === 0" class="metavox-panel__empty">
			{{ t('intravox', 'No metadata fields are configured for this folder.') }}
		</p>

		<template v-else>
			<div v-for="field in fields" :key="field.field_name" class="metavox-field">
				<label :for="inputId(field)" class="metavox-field__label">
					{{ field.field_label || field.field_name }}
					<span v-if="isRequired(field)" class="metavox-field__required">*</span>
				</label>

				<!-- Known types get a real input. -->
				<select v-if="field.field_type === 'select'"
						:id="inputId(field)"
						v-model="values[field.field_name]"
						class="metavox-field__input"
						:disabled="saving">
					<option value="">{{ t('intravox', '— none —') }}</option>
					<option v-for="opt in optionsOf(field)" :key="opt" :value="opt">{{ opt }}</option>
				</select>

				<select v-else-if="field.field_type === 'multiselect'"
						:id="inputId(field)"
						v-model="multi[field.field_name]"
						class="metavox-field__input metavox-field__input--multi"
						multiple
						:disabled="saving">
					<option v-for="opt in optionsOf(field)" :key="opt" :value="opt">{{ opt }}</option>
				</select>

				<input v-else-if="field.field_type === 'date'"
					   :id="inputId(field)"
					   v-model="values[field.field_name]"
					   type="date"
					   class="metavox-field__input"
					   :disabled="saving">

				<input v-else-if="field.field_type === 'number'"
					   :id="inputId(field)"
					   v-model="values[field.field_name]"
					   type="number"
					   class="metavox-field__input"
					   :disabled="saving">

				<input v-else-if="field.field_type === 'url'"
					   :id="inputId(field)"
					   v-model="values[field.field_name]"
					   type="url"
					   class="metavox-field__input"
					   :disabled="saving">

				<textarea v-else-if="field.field_type === 'textarea'"
						  :id="inputId(field)"
						  v-model="values[field.field_name]"
						  class="metavox-field__input"
						  rows="3"
						  :disabled="saving"></textarea>

				<input v-else-if="EDITABLE_AS_TEXT.includes(field.field_type)"
					   :id="inputId(field)"
					   v-model="values[field.field_name]"
					   type="text"
					   class="metavox-field__input"
					   :disabled="saving">

				<!-- Unknown type: show the value, do not guess an editor.
				     MetaVox may add field types this app has never heard of; a
				     wrong input would write a malformed value, and refusing to
				     render would hide data. Read-only is the honest middle. -->
				<div v-else class="metavox-field__readonly">
					<span v-if="values[field.field_name]">{{ values[field.field_name] }}</span>
					<span v-else class="metavox-field__placeholder">{{ t('intravox', 'Not set') }}</span>
					<span class="metavox-field__hint">
						{{ t('intravox', 'Edit this field type in the Files app') }}
					</span>
				</div>
			</div>

			<div v-if="hasEditableFields" class="metavox-panel__actions">
				<button class="metavox-panel__save"
						:disabled="saving || !dirty"
						@click="save">
					{{ saving ? t('intravox', 'Saving …') : t('intravox', 'Save') }}
				</button>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'

/** Types this panel renders as a plain text input. */
const EDITABLE_AS_TEXT = ['text', 'person', 'user', 'filelink']

/**
 * MetaVox metadata for a page, in IntraVox's own sidebar.
 *
 * Talks to MetaVox's OCS API and nothing else — MetaVox contains no IntraVox
 * code and needs none. That matters because the previous integration relied on
 * `window.OCA.Files.Sidebar`, a Files-app global that Nextcloud REMOVED in 34,
 * which is why the tab went blank. An HTTP API of MetaVox's own is not tied to
 * a Nextcloud release and works on 32, 33 and 34 alike.
 *
 * Uses the groupfolder-scoped endpoint deliberately: the auto-detecting variant
 * (/files/{id}/metadata) returned every field of every groupfolder — 37 on dev
 * where the folder actually has 5.
 */
export default {
	name: 'MetaVoxPanel',
	props: {
		/** Nextcloud file id of the page JSON. */
		fileId: {
			type: Number,
			default: null,
		},
		/** Groupfolder holding the page; MetaVox assigns fields per folder. */
		groupfolderId: {
			type: Number,
			default: null,
		},
	},
	data() {
		return {
			EDITABLE_AS_TEXT,
			fields: [],
			values: {},
			multi: {},
			original: '',
			loading: false,
			saving: false,
			error: null,
		}
	},
	computed: {
		/** Whether anything here can be saved at all. */
		hasEditableFields() {
			return this.fields.some(f => this.isEditable(f))
		},
		/** Current state as a comparable string, to gate the Save button. */
		dirty() {
			return JSON.stringify({ v: this.values, m: this.multi }) !== this.original
		},
	},
	watch: {
		fileId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},
	methods: {
		t(app, text, vars) {
			return translate(app, text, vars)
		},
		inputId(field) {
			return `metavox-${this.fileId}-${field.field_name}`
		},
		isRequired(field) {
			return field.is_required === 1 || field.is_required === true
		},
		isEditable(field) {
			return field.field_type === 'select'
				|| field.field_type === 'multiselect'
				|| field.field_type === 'date'
				|| field.field_type === 'number'
				|| field.field_type === 'url'
				|| field.field_type === 'textarea'
				|| EDITABLE_AS_TEXT.includes(field.field_type)
		},
		/** field_options arrives as JSON, a plain array, or a newline list. */
		optionsOf(field) {
			const raw = field.field_options
			if (!raw) {
				return []
			}
			if (Array.isArray(raw)) {
				return raw
			}
			try {
				const parsed = JSON.parse(raw)
				if (Array.isArray(parsed)) {
					return parsed
				}
				if (parsed && Array.isArray(parsed.options)) {
					return parsed.options
				}
			} catch (e) {
				// Not JSON — fall through to the line/comma split below.
			}
			return String(raw).split(/[\n,]/).map(s => s.trim()).filter(Boolean)
		},
		async load() {
			if (!this.fileId || !this.groupfolderId) {
				this.fields = []
				return
			}
			this.loading = true
			this.error = null
			try {
				const url = generateOcsUrl(
					'apps/metavox/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata',
					{ groupfolderId: this.groupfolderId, fileId: this.fileId }
				)
				const response = await axios.get(url)
				const rows = response?.data?.ocs?.data ?? response?.data?.data ?? response?.data ?? []
				this.fields = Array.isArray(rows) ? rows : []

				const values = {}
				const multi = {}
				for (const field of this.fields) {
					const raw = field.value ?? ''
					if (field.field_type === 'multiselect') {
						// MetaVox joins multi-values with ";#".
						multi[field.field_name] = raw ? String(raw).split(';#').filter(Boolean) : []
					} else {
						values[field.field_name] = raw === null ? '' : String(raw)
					}
				}
				this.values = values
				this.multi = multi
				this.original = JSON.stringify({ v: values, m: multi })
			} catch (err) {
				// A missing or older MetaVox must not break the sidebar.
				console.warn('[IntraVox] Could not load MetaVox metadata:', err.message)
				this.error = this.t('intravox', 'Could not load metadata')
				this.fields = []
			} finally {
				this.loading = false
			}
		},
		async save() {
			this.saving = true
			try {
				const metadata = {}
				for (const field of this.fields) {
					if (!this.isEditable(field)) {
						continue
					}
					metadata[field.field_name] = field.field_type === 'multiselect'
						? (this.multi[field.field_name] || []).join(';#')
						: (this.values[field.field_name] ?? '')
				}

				const url = generateOcsUrl(
					'apps/metavox/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata',
					{ groupfolderId: this.groupfolderId, fileId: this.fileId }
				)
				await axios.post(url, { metadata })

				this.original = JSON.stringify({ v: this.values, m: this.multi })
				showSuccess(this.t('intravox', 'Metadata saved'))

				// MetaVox already listens for this on window, so the Files view
				// stays in sync without MetaVox knowing IntraVox exists. It is
				// also what refreshes IntraVox's own publication state when a
				// publish date changes.
				window.dispatchEvent(new CustomEvent('metavox:metadata:saved', {
					detail: { fileId: this.fileId },
				}))
				this.$emit('saved')
			} catch (err) {
				// MetaVox answers 400 with per-field validation errors.
				const data = err.response?.data?.ocs?.data ?? err.response?.data
				showError(data?.error || this.t('intravox', 'Could not save metadata'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.metavox-panel {
	padding: 8px 0;
}

.metavox-field {
	margin-bottom: 14px;
}

.metavox-field__label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.metavox-field__required {
	color: var(--color-error, #c9326b);
}

.metavox-field__input {
	width: 100%;
}

.metavox-field__input--multi {
	min-height: 88px;
}

.metavox-field__readonly {
	padding: 6px 0;
}

.metavox-field__placeholder,
.metavox-field__hint {
	color: var(--color-text-maxcontrast, #767676);
}

.metavox-field__hint {
	display: block;
	margin-top: 2px;
	font-size: 0.85em;
}

.metavox-panel__empty,
.metavox-panel__error {
	color: var(--color-text-maxcontrast, #767676);
}

.metavox-panel__actions {
	margin-top: 16px;
}

.metavox-panel__save {
	padding: 6px 16px;
	border: 1px solid var(--color-primary-element, #0082c9);
	border-radius: var(--border-radius, 8px);
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	cursor: pointer;
}

.metavox-panel__save:disabled {
	opacity: 0.5;
	cursor: default;
}
</style>
