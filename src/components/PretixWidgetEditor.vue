<template>
  <div class="pretix-editor">
    <div class="form-group"><label>{{ t('intravox', 'Title') }}</label><input v-model="local.title" @input="emitUpdate" /></div>
    <NcNoteCard v-if="loadError" type="warning">{{ t('intravox', 'Pretix is not configured or cannot be reached.') }}</NcNoteCard>
    <div class="form-group"><label>{{ t('intravox', 'Organizer') }}</label>
      <select v-model="local.organizer" @change="organizerChanged"><option value="">{{ t('intravox', 'Select organizer') }}</option><option v-for="item in organizers" :key="item.slug" :value="item.slug">{{ item.name }}</option></select>
    </div>
    <div class="form-group"><label>{{ t('intravox', 'Event or event series') }}</label>
      <select v-model="local.event" @change="emitUpdate"><option value="">{{ t('intravox', 'Select event') }}</option><option v-for="item in events" :key="item.slug" :value="item.slug">{{ item.name }}</option></select>
    </div>
    <div class="form-group"><label>{{ t('intravox', 'Quota ID (optional)') }}</label><input v-model.number="local.quotaId" type="number" min="0" @input="emitUpdate" /></div>
    <div class="form-group"><label>{{ t('intravox', 'New registrations window (hours)') }}</label><input v-model.number="local.newOrdersHours" type="number" min="1" max="168" @input="emitUpdate" /></div>
    <label><input v-model="local.showLocation" type="checkbox" @change="emitUpdate" /> {{ t('intravox', 'Show location') }}</label>
    <label><input v-model="local.showCapacity" type="checkbox" @change="emitUpdate" /> {{ t('intravox', 'Show capacity') }}</label>
    <label><input v-model="local.showNewOrders" type="checkbox" @change="emitUpdate" /> {{ t('intravox', 'Show new registrations') }}</label>
    <label><input v-model="local.showBackendLink" type="checkbox" @change="emitUpdate" /> {{ t('intravox', 'Show Pretix backend link') }}</label>
  </div>
</template>
<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { translate } from '@nextcloud/l10n';
import { NcNoteCard } from '@nextcloud/vue';
export default {
  name: 'PretixWidgetEditor', components: { NcNoteCard }, props: { widget: { type: Object, required: true } }, emits: ['update'],
  data() { return { local: { title: '', organizer: '', event: '', quotaId: 0, newOrdersHours: 24, showLocation: true, showCapacity: true, showNewOrders: true, showBackendLink: false, ...structuredClone(this.widget) }, organizers: [], events: [], loadError: false }; },
  async mounted() { await this.loadOrganizers(); if (this.local.organizer) await this.loadEvents(); },
  methods: {
    t: translate, emitUpdate() { this.$emit('update', structuredClone(this.local)); },
    async loadOrganizers() { try { const { data } = await axios.get(generateUrl('/apps/intravox/api/pretix/options')); this.organizers = data.organizers || []; } catch (e) { this.loadError = true; } },
    async loadEvents() { try { const params = new URLSearchParams({ organizer: this.local.organizer }); const { data } = await axios.get(generateUrl(`/apps/intravox/api/pretix/options?${params}`)); this.events = data.events || []; } catch (e) { this.loadError = true; } },
    async organizerChanged() { this.local.event = ''; this.events = []; this.emitUpdate(); if (this.local.organizer) await this.loadEvents(); },
  },
};
</script>
<style scoped>.pretix-editor { display:grid; gap:14px }.form-group{display:grid;gap:6px}.form-group input,.form-group select{width:100%}</style>
