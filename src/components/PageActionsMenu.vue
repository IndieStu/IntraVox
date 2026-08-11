<template>
  <NcActions ref="actions">
    <!-- ===== Group A — Page actions (this page) ===== -->
    <NcActionButton v-if="canPerformAction('editPage')"
                    @click="emitAndClose('rename-page')">
      <template #icon>
        <RenameBox :size="20" />
      </template>
      {{ t('intravox', 'Rename page') }}
    </NcActionButton>

    <NcActionButton v-if="canPerformAction('editPage')"
                    @click="emitAndClose('page-settings')">
      <template #icon>
        <TuneVertical :size="20" />
      </template>
      {{ t('intravox', 'Page settings') }}
    </NcActionButton>

    <NcActionButton v-if="canPerformAction('createPage')"
                    @click="emitAndClose('copy-page')">
      <template #icon>
        <ContentCopy :size="20" />
      </template>
      {{ t('intravox', 'Copy page') }}
    </NcActionButton>

    <!-- Sits beside "Copy page" because that is what editors reach for when
         they want this page somewhere else. Only on multilingual intranets:
         on a single-language site the whole concept is noise. -->
    <NcActionButton v-if="isMultilingual && canPerformAction('createPage')"
                    @click="emitAndClose('translate-page')">
      <template #icon>
        <Translate :size="20" />
      </template>
      {{ t('intravox', 'Translate page') }}
    </NcActionButton>

    <!-- Version history and Translate page both open the sidebar rather than
         doing something here. They are INSPECTION, not actions — bodies of
         information about this page — so the sidebar is their home and this is
         an accelerator to it. Versions had no menu entry at all, and with four
         sidebar tabs its label was being truncated, which is why an editor
         could not find it. -->
    <NcActionButton v-if="canPerformAction('editPage')"
                    @click="emitAndClose('version-history')">
      <template #icon>
        <History :size="20" />
      </template>
      {{ t('intravox', 'Version history') }}
    </NcActionButton>

    <NcActionButton v-if="canPerformAction('saveAsTemplate')"
                    @click="emitAndClose('save-as-template')">
      <template #icon>
        <FileDocumentMultipleOutline :size="20" />
      </template>
      {{ t('intravox', 'Save as template') }}
    </NcActionButton>

    <!-- Separator A → B: only between two non-empty groups -->
    <NcActionSeparator v-if="hasPageGroup && (hasCreateGroup || hasUtilityGroup)" />

    <!-- ===== Group B — Site / structure ===== -->
    <NcActionButton v-if="canPerformAction('createPage')"
                    @click="emitAndClose('create-page')">
      <template #icon>
        <Plus :size="20" />
      </template>
      {{ t('intravox', 'New page') }}
    </NcActionButton>

    <NcActionButton v-if="canPerformAction('editNavigation')"
                    @click="emitAndClose('edit-navigation')">
      <template #icon>
        <Cog :size="20" />
      </template>
      {{ t('intravox', 'Edit navigation') }}
    </NcActionButton>

    <!-- Separator B → C -->
    <NcActionSeparator v-if="hasCreateGroup && hasUtilityGroup" />

    <!-- ===== Group C — Utility ===== -->
    <NcActionButton @click="emitAndClose('feed-settings')">
      <template #icon>
        <Rss :size="20" />
      </template>
      {{ t('intravox', 'RSS feed') }}
    </NcActionButton>

    <!-- Separator C → D (before the destructive action) -->
    <NcActionSeparator v-if="hasDeleteGroup && (hasPageGroup || hasCreateGroup || hasUtilityGroup)" />

    <!-- ===== Group D — Destructive (hidden for the homepage) ===== -->
    <NcActionButton v-if="hasDeleteGroup"
                    class="action-delete-page"
                    @click="emitAndClose('delete-page')">
      <template #icon>
        <Delete :size="20" />
      </template>
      {{ t('intravox', 'Delete page') }}
    </NcActionButton>
  </NcActions>
</template>

<script>
import { translate, translatePlural } from '@nextcloud/l10n';
import { NcActions, NcActionButton, NcActionSeparator } from '@nextcloud/vue';
import Cog from 'vue-material-design-icons/Cog.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import RenameBox from 'vue-material-design-icons/RenameBox.vue';
import TuneVertical from 'vue-material-design-icons/TuneVertical.vue';
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue';
import Rss from 'vue-material-design-icons/Rss.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import Translate from 'vue-material-design-icons/Translate.vue';
import History from 'vue-material-design-icons/History.vue';

export default {
  name: 'PageActionsMenu',
  components: {
    NcActions,
    NcActionButton,
    NcActionSeparator,
    Cog,
    Plus,
    RenameBox,
    TuneVertical,
    FileDocumentMultipleOutline,
    Rss,
    ContentCopy,
    Delete,
    Translate,
    History
  },
  props: {
    isEditMode: {
      type: Boolean,
      default: false
    },
    permissions: {
      type: Object,
      default: () => ({
        editNavigation: false,
        viewPages: true,      // Everyone can view pages
        createPage: false,
        editPage: false,
        deletePage: false
      })
    },
    isHome: {
      type: Boolean,
      default: false
    },
    /**
     * Whether this intranet holds content in more than one language. Gates the
     * Translate action: a single-language site must not carry a multilingual
     * concept it never uses.
     */
    isMultilingual: {
      type: Boolean,
      default: false
    }
  },
  emits: ['edit-navigation', 'create-page', 'rename-page', 'page-settings', 'save-as-template', 'feed-settings', 'copy-page', 'translate-page', 'version-history', 'delete-page'],
  computed: {
    // Group-presence flags drive the separators: a separator only renders
    // between two non-empty groups, so permission-gated hiding never leaves a
    // dangling or doubled divider. Expressions mirror the per-item gates.
    // Group A — page actions. createPage is folded in because "Copy page" lives
    // here (createPage implies editPage, but stated explicitly for robustness).
    hasPageGroup() {
      return this.canPerformAction('editPage')
        || this.canPerformAction('createPage')
        || this.canPerformAction('saveAsTemplate');
    },
    // Group B — site/structure.
    hasCreateGroup() {
      return this.canPerformAction('createPage')
        || this.canPerformAction('editNavigation');
    },
    // Group C — utility. RSS is ungated → always present. Named so the separator
    // logic stays correct if RSS ever becomes gated.
    hasUtilityGroup() {
      return true;
    },
    // Group D — destructive. Same gate as the Delete button.
    hasDeleteGroup() {
      return this.canPerformAction('deletePage') && !this.isHome;
    },
  },
  methods: {
    t(app, text, vars) {
      return translate(app, text, vars);
    },
    // Close the actions dropdown, then emit (the item opens a modal next).
    emitAndClose(eventName) {
      this.$refs.actions?.closeMenu?.();
      this.$emit(eventName);
    },
    /**
     * Check if user can perform a specific action
     * This method can be extended in the future to include more complex logic
     * like role-based permissions (admin, editor, viewer)
     */
    canPerformAction(action) {
      return this.permissions[action] === true;
    }
  }
};
</script>

<style scoped>
/* NcActions component handles its own styling */

/* Destructive action: tint the Delete page item red (NcActionButton has no
   danger variant). Matches the .tree-action--danger precedent. */
:deep(.action-delete-page .action-button__icon),
:deep(.action-delete-page .action-button__text) {
  color: var(--color-error);
}
</style>
