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
import { showError, showSuccess } from '@nextcloud/dialogs';
import { NcModal, NcButton, NcLoadingIcon } from '@nextcloud/vue';

export default {
  name: 'RenamePageModal',
  components: {
    NcModal,
    NcButton,
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
      saving: false
    };
  },
  computed: {
    canRename() {
      const trimmed = this.newTitle.trim();
      return !this.saving && trimmed.length > 0 && trimmed !== this.currentTitle.trim();
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
  },
  methods: {
    t(app, text, vars = {}) {
      return translate(app, text, vars);
    },
    async rename() {
      if (!this.canRename) {
        return;
      }
      const title = this.newTitle.trim();
      this.saving = true;
      try {
        const url = generateUrl(`/apps/intravox/api/pages/${this.pageId}/metadata`);
        await axios.put(url, { title });
        showSuccess(this.t('intravox', 'Page renamed'));
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

.modal-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
</style>
