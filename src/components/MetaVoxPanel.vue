<template>
	<div class="metavox-panel">
		<div v-if="loading" class="loading">
			{{ t('intravox', 'Loading metadata …') }}
		</div>

		<div v-else-if="error" class="error-message">
			{{ error }}
		</div>

		<p v-else-if="fields.length === 0" class="metavox-panel__empty">
			{{ t('intravox', 'No metadata fields are configured for this folder.') }}
		</p>

		<div v-else class="metadata-form">
			<div v-for="field in fields" :key="field.id" class="field-container">
				<label :for="`field-${field.id}`" class="field-label">
					{{ field.field_label }}
					<span v-if="field.is_required" class="required-indicator">*</span>
				</label>

				<p v-if="field.field_description" class="field-description">
					{{ field.field_description }}
				</p>

				<!-- Text -->
				<NcTextField v-if="field.field_type === 'text'"
					:id="`field-${field.id}`"
					:model-value="values[field.field_name] || ''"
					:disabled="saving"
					:placeholder="field.field_label"
					@update:model-value="update(field.field_name, $event)" />

				<!-- Number -->
				<NcTextField v-else-if="field.field_type === 'number'"
					:id="`field-${field.id}`"
					:model-value="values[field.field_name] || ''"
					:disabled="saving"
					:placeholder="field.field_label"
					type="number"
					@update:model-value="update(field.field_name, $event !== '' && $event !== null ? String($event) : '')" />

				<!-- Textarea -->
				<textarea v-else-if="field.field_type === 'textarea'"
					:id="`field-${field.id}`"
					:value="values[field.field_name] || ''"
					:disabled="saving"
					:placeholder="field.field_label"
					class="textarea-field"
					rows="6"
					@input="update(field.field_name, $event.target.value)"></textarea>

				<!-- Date, optionally with time -->
				<input v-else-if="field.field_type === 'date'"
					:id="`field-${field.id}`"
					:type="includesTime(field) ? 'datetime-local' : 'date'"
					:step="includesTime(field) ? 1 : undefined"
					:value="values[field.field_name] || ''"
					:disabled="saving"
					:required="field.is_required"
					class="date-input"
					@input="update(field.field_name, includesTime(field) ? padDatetime($event.target.value) : $event.target.value)">

				<!-- Select -->
				<NcSelect v-else-if="field.field_type === 'select'"
					:id="`field-${field.id}`"
					v-model="selectValues[field.field_name]"
					:options="fieldOptions(field)"
					:disabled="saving"
					:placeholder="field.field_label"
					:reduce="option => option.value"
					label="label"
					@update:model-value="update(field.field_name, $event || '')" />

				<!-- Multi-select. Stored as a ";#"-joined string, same as MetaVox. -->
				<NcSelect v-else-if="field.field_type === 'multiselect' || field.field_type === 'multi_select'"
					:id="`field-${field.id}`"
					v-model="multiSelectValues[field.field_name]"
					:options="fieldOptions(field)"
					:disabled="saving"
					:multiple="true"
					:placeholder="field.field_label"
					:reduce="option => option.value"
					label="label"
					@update:model-value="update(field.field_name, Array.isArray($event) ? $event.join(';#') : '')" />

				<!-- Checkbox -->
				<NcCheckboxRadioSwitch v-else-if="field.field_type === 'checkbox'"
					:id="`field-${field.id}`"
					:model-value="values[field.field_name] === '1' || values[field.field_name] === true"
					:disabled="saving"
					@update:model-value="update(field.field_name, $event ? '1' : '0')">
					{{ field.field_label }}
				</NcCheckboxRadioSwitch>

				<!-- url / user / filelink render through MetaVox's own components,
				     which live inside its Files bundle and cannot be imported from
				     here. A plain text field still round-trips the stored value
				     correctly — it is the same ";#"-joined string underneath — but
				     without the picker. Editing those with their real widget is a
				     reason to reach for MetaVox's own sidebar in the Files app. -->
				<NcTextField v-else-if="TEXT_FALLBACK_TYPES.includes(field.field_type)"
					:id="`field-${field.id}`"
					:model-value="values[field.field_name] || ''"
					:disabled="saving"
					:placeholder="field.field_label"
					@update:model-value="update(field.field_name, $event)" />

				<!-- Unknown type. MetaVox does not validate field_type on write, so
				     values it does not know can exist in the database; it shows them
				     with this same placeholder rather than guessing an editor. -->
				<NcTextField v-else
					:id="`field-${field.id}`"
					:model-value="values[field.field_name] || ''"
					:disabled="saving"
					:placeholder="`Unknown field type: ${field.field_type}`"
					@update:model-value="update(field.field_name, $event)" />
			</div>

			<!-- Hidden until something changes, matching MetaVox: a Save button on
			     an untouched form invites a write that has nothing to write. -->
			<div v-if="hasChanges" class="metadata-actions">
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ saving ? t('intravox', 'Saving …') : t('intravox', 'Save') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'
import { NcTextField, NcSelect, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'

/**
 * Types MetaVox renders with a dedicated picker component that this panel
 * cannot import. They fall back to a text field here — deliberately, and
 * distinct from the unknown-type fallback, which is about types MetaVox itself
 * does not recognise.
 */
const TEXT_FALLBACK_TYPES = ['url', 'user', 'filelink']

/**
 * MetaVox metadata for a page, in IntraVox's own sidebar.
 *
 * Mirrors MetaVox's MetadataForm.vue field-by-field — same components, same
 * value encoding, same dirty-state rule — so the panel behaves the way it does
 * in the Files app. It has to be a copy rather than a reuse: MetaVox ships no
 * library build, exports nothing on window, and its sidebar custom element is
 * only defined from inside the Files sidebar's own onInit(). Exporting the
 * component from MetaVox is the better long-term answer.
 *
 * Talks only to MetaVox's OCS API, so MetaVox needs no knowledge of IntraVox.
 * That API is a MetaVox API rather than a Nextcloud one, which is why it keeps
 * working on 32, 33 and 34 where the old Files-sidebar route broke.
 */
export default {
	name: 'MetaVoxPanel',
	components: {
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcButton,
	},
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
			TEXT_FALLBACK_TYPES,
			fields: [],
			values: {},
			originalValues: {},
			selectValues: {},
			multiSelectValues: {},
			loading: false,
			saving: false,
			error: null,
		}
	},
	computed: {
		/**
		 * Whether anything differs from what was loaded. Same shape as MetaVox's
		 * check so the Save button appears at the same moments.
		 */
		hasChanges() {
			return JSON.stringify(this.values) !== JSON.stringify(this.originalValues)
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
		/** A date field carries time when its options object says so. */
		includesTime(field) {
			const opts = field?.field_options
			return !!(opts && typeof opts === 'object' && !Array.isArray(opts) && opts.includeTime)
		},
		/** datetime-local omits seconds; MetaVox stores them. */
		padDatetime(value) {
			if (!value) {
				return ''
			}
			return value.length === 16 ? `${value}:00` : value
		},
		/** field_options arrives as a newline string, an array of strings, or objects. */
		fieldOptions(field) {
			let options = field.field_options || []
			if (typeof options === 'string') {
				options = options.split('\n').filter(o => o.trim() !== '')
			}
			if (!Array.isArray(options)) {
				options = []
			}
			return options.map(option => {
				if (typeof option === 'string') {
					return { value: option.trim(), label: option.trim() }
				}
				return {
					value: option.value || option.id || option,
					label: option.label || option.value || option.id || option,
				}
			})
		},
		update(fieldName, value) {
			// Replace the object so the dirty computed re-evaluates.
			this.values = { ...this.values, [fieldName]: value }
		},
		async load() {
			if (!this.fileId || !this.groupfolderId) {
				this.fields = []
				return
			}
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(this.metadataUrl())
				const rows = response?.data?.ocs?.data ?? response?.data?.data ?? response?.data ?? []
				this.fields = Array.isArray(rows) ? rows : []

				const values = {}
				const selectValues = {}
				const multiSelectValues = {}
				for (const field of this.fields) {
					const raw = field.value ?? ''
					values[field.field_name] = raw === null ? '' : String(raw)

					if (field.field_type === 'select') {
						selectValues[field.field_name] = values[field.field_name] || null
					} else if (field.field_type === 'multiselect' || field.field_type === 'multi_select') {
						multiSelectValues[field.field_name] = values[field.field_name]
							? values[field.field_name].split(';#').filter(Boolean)
							: []
					}
				}
				this.values = values
				this.originalValues = { ...values }
				this.selectValues = selectValues
				this.multiSelectValues = multiSelectValues
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
			if (!this.hasChanges) {
				return
			}
			this.saving = true
			try {
				await axios.post(this.metadataUrl(), { metadata: this.values })
				this.originalValues = { ...this.values }
				showSuccess(this.t('intravox', 'Metadata saved'))

				// MetaVox already listens for this on window, so the Files view
				// stays in sync without MetaVox knowing IntraVox exists. It also
				// refreshes IntraVox's own publication state when a publish or
				// expiry date changes.
				window.dispatchEvent(new CustomEvent('metavox:metadata:saved', {
					detail: { fileId: this.fileId, metadata: { ...this.values } },
				}))
				this.$emit('saved')
			} catch (err) {
				const data = err.response?.data?.ocs?.data ?? err.response?.data
				showError(data?.error || this.t('intravox', 'Could not save metadata'))
			} finally {
				this.saving = false
			}
		},
		metadataUrl() {
			// The groupfolder-scoped endpoint deliberately: the auto-detecting
			// variant returns every field of every groupfolder (37 on dev where
			// the folder has 5).
			return generateOcsUrl(
				'apps/metavox/api/v1/groupfolders/{groupfolderId}/files/{fileId}/metadata',
				{ groupfolderId: this.groupfolderId, fileId: this.fileId },
			)
		},
	},
}
</script>

<style scoped>
/* Mirrors MetaVox's MetadataForm.vue so the panel reads the same as the one in
   the Files sidebar. Its styles are scoped inside its own bundle and cannot be
   imported. */
.metadata-form {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 8px 0;
}

.field-container {
	display: flex;
	flex-direction: column;
}

.field-label {
	margin-bottom: 4px;
	font-weight: bold;
}

.required-indicator {
	color: var(--color-error);
	margin-inline-start: 2px;
}

.field-description {
	margin: 0 0 4px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.textarea-field,
.date-input {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, var(--border-radius));
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
	font-size: inherit;
}

.textarea-field:hover,
.date-input:hover,
.textarea-field:focus,
.date-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.textarea-field {
	resize: vertical;
	min-height: 80px;
}

.metadata-actions {
	display: flex;
	justify-content: flex-end;
}

.metavox-panel__empty {
	color: var(--color-text-maxcontrast);
}

.error-message {
	color: var(--color-error);
}
</style>
