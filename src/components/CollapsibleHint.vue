<template>
  <details class="collapsible-hint">
    <summary class="collapsible-hint__summary">
      <InformationOutline :size="18" class="collapsible-hint__icon" />
      <span class="collapsible-hint__label">{{ summary || t('intravox', 'How does this work?') }}</span>
      <ChevronDown :size="18" class="collapsible-hint__chevron" />
    </summary>
    <div class="collapsible-hint__body">
      <slot>{{ text }}</slot>
    </div>
  </details>
</template>

<script>
import { translate } from '@nextcloud/l10n';
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';

/**
 * A compact, collapsed-by-default help hint. Shows a single summary line with an
 * info icon; click to expand the full explanation. Replaces always-on NcNoteCard
 * help blocks that dominated modals every time they opened. Native <details> —
 * accessible, keyboard-operable, no JS state.
 */
export default {
  name: 'CollapsibleHint',
  components: { InformationOutline, ChevronDown },
  props: {
    // Short one-line summary shown when collapsed. Defaults to a generic prompt.
    summary: {
      type: String,
      default: '',
    },
    // Full text shown when expanded (alternative to the default slot).
    text: {
      type: String,
      default: '',
    },
  },
  methods: {
    t(app, textKey, vars) {
      return translate(app, textKey, vars);
    },
  },
};
</script>

<style scoped>
.collapsible-hint {
  /* Reserve room on the right so the card (and its chevron) never sits under
     the modal's absolutely-positioned close (×) button, which overlaps the top
     of the dialog body. 44px = NcModal's close-button clearance. */
  margin: 4px 44px 12px 0;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-background-hover);
}

.collapsible-hint__summary {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  cursor: pointer;
  list-style: none;
  color: var(--color-text-maxcontrast);
  font-size: 13px;
  user-select: none;
}

/* Hide the native disclosure triangle across browsers */
.collapsible-hint__summary::-webkit-details-marker {
  display: none;
}

.collapsible-hint__icon {
  flex: 0 0 auto;
  color: var(--color-primary-element);
}

.collapsible-hint__label {
  flex: 1 1 auto;
}

.collapsible-hint__chevron {
  flex: 0 0 auto;
  transition: transform 0.15s ease;
}

.collapsible-hint[open] .collapsible-hint__chevron {
  transform: rotate(180deg);
}

.collapsible-hint__body {
  padding: 0 12px 12px 38px;
  color: var(--color-main-text);
  font-size: 13px;
  line-height: 1.5;
}
</style>
