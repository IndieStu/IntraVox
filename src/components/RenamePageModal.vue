<template>
  <NcModal @close="$emit('close')"
           :name="t('intravox', 'Rename page')"
           size="small">
    <div class="rename-page-modal-content">
      <label for="rename-page-title" class="modal-description">{{ t('intravox', 'Enter a new title for the page') }}</label>
      <input
        id="rename-page-title"
        ref="titleInput"
        v-model="newTitle"
        type="text"
        class="page-title-input"
        :placeholder="t('intravox', 'Page title')"
        :disabled="saving"
        @keyup.enter="rename"
        @keyup.esc="$emit('close')"
      />

      <!-- Folder rename (#95). Only offered when the backend reports a
           renamable folder/JSON pair (not the homepage, not a loose legacy
           file) and the new title actually yields a different folder name. -->
      <div v-if="showFolderOption" class="folder-rename">
        <NcCheckboxRadioSwitch
          :model-value="renameFolder"
          :disabled="saving"
          @update:model-value="renameFolder = $event">
          {{ t('intravox', 'Also rename the page\'s folder') }}
        </NcCheckboxRadioSwitch>
        <p class="folder-rename__preview">{{ folderName }} → {{ newFolderName }}</p>
        <p v-if="renameFolder" class="folder-rename__warning">
          {{ t('intravox', 'Old links that use the folder name will stop working. Links that use the page ID keep working.') }}
        </p>
      </div>

      <div class="modal-buttons">
        <NcButton type="secondary" :disabled="saving" @click="$emit('close')">
          {{ t('intravox', 'Cancel') }}
        </NcButton>
        <NcButton type="primary" :disabled="!canRename" @click="rename">
          <template v-if="saving" #icon>
            <NcLoadingIcon :size="20" />
          </template>
          {{ t('intravox', 'Rename') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { translate } from '@nextcloud/l10n';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs';
import { NcModal, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue';
import { generateSlug } from '../utils/slug';

export default {
  name: 'RenamePageModal',
  components: {
    NcModal,
    NcButton,
    NcCheckboxRadioSwitch,
    NcLoadingIcon
  },
  props: {
    pageId: {
      type: String,
      required: true
    },
    currentTitle: {
      type: String,
      default: ''
    }
  },
  emits: ['close', 'renamed'],
  data() {
    return {
      newTitle: this.currentTitle,
      folderName: null,
      renameFolder: false,
      saving: false
    };
  },
  computed: {
    canRename() {
      const trimmed = this.newTitle.trim();
      return !this.saving && trimmed.length > 0 && trimmed !== this.currentTitle.trim();
    },
    newFolderName() {
      return generateSlug(this.newTitle.trim());
    },
    showFolderOption() {
      return !!this.folderName
        && !!this.newFolderName
        && this.newFolderName !== this.folderName;
    }
  },
  mounted() {
    this.$nextTick(() => {
      const input = this.$refs.titleInput;
      if (input) {
        input.focus();
        input.select();
      }
    });
    this.loadFolderName();
  },
  methods: {
    t(app, text, vars = {}) {
      return translate(app, text, vars);
    },
    async loadFolderName() {
      try {
        const url = generateUrl(`/apps/intravox/api/pages/${encodeURIComponent(this.pageId)}/metadata`);
        const response = await axios.get(url);
        this.folderName = response.data?.folderName || null;
        // Default the checkbox ON only while the folder still carries the
        // title-derived name (allowing the collision suffix) — a folder that
        // was deliberately named otherwise stays as it is unless asked.
        const original = generateSlug(this.currentTitle.trim());
        this.renameFolder = !!this.folderName && !!original
          && (this.folderName === original
            || new RegExp(`^${original}-\\d+$`).test(this.folderName));
      } catch (error) {
        // No folder option, the title rename works as before.
        this.folderName = null;
      }
    },
    async rename() {
      if (!this.canRename) {
        return;
      }
      const title = this.newTitle.trim();
      this.saving = true;
      try {
        const url = generateUrl(`/apps/intravox/api/pages/${this.pageId}/metadata`);
        const body = { title };
        if (this.showFolderOption && this.renameFolder) {
          body.folderName = this.newFolderName;
        }
        const response = await axios.put(url, body);
        const folderRename = response.data?.folderRename;
        if (folderRename?.status === 'renamed') {
          showSuccess(this.t('intravox', 'Page and folder renamed'));
        } else if (folderRename && folderRename.status === 'failed') {
          showWarning(this.t('intravox', 'The page was renamed, but its folder could not be renamed.'));
        } else {
          showSuccess(this.t('intravox', 'Page renamed'));
        }
        this.$emit('renamed', title);
        this.$emit('close');
      } catch (error) {
        console.error('Failed to rename page:', error);
        showError(this.t('intravox', 'Failed to rename page: {error}', {
          error: error.response?.data?.error || error.message
        }));
      } finally {
        this.saving = false;
      }
    }
  }
};
</script>

<style scoped>
.rename-page-modal-content {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.modal-description {
  margin: 0;
  color: var(--color-text-maxcontrast);
  font-size: 14px;
}

.page-title-input {
  width: 100%;
  padding: 10px;
  font-size: 16px;
  color: var(--color-main-text);
  background: var(--color-main-background);
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius-large);
  box-sizing: border-box;
}

.page-title-input:focus {
  outline: none;
  border-color: var(--color-primary);
}

.folder-rename {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.folder-rename__preview {
  margin: 0;
  padding-inline-start: 10px;
  font-family: monospace;
  font-size: 13px;
  color: var(--color-text-maxcontrast);
  overflow-wrap: anywhere;
}

.folder-rename__warning {
  margin: 0;
  padding-inline-start: 10px;
  font-size: 13px;
  color: var(--color-text-maxcontrast);
}

.modal-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
</style>
