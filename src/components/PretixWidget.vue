<template>
  <section class="pretix-widget" :style="backgroundStyle">
    <h3 v-if="widget.title">{{ widget.title }}</h3>
    <NcLoadingIcon v-if="loading" :size="36" />
    <NcNoteCard v-else-if="error" type="warning">{{ t('intravox', 'Event data is currently unavailable.') }}</NcNoteCard>
    <NcNoteCard v-else-if="!data || data.status === 'empty'" type="info">{{ t('intravox', 'No upcoming event found.') }}</NcNoteCard>
    <div v-else class="event-card">
      <div class="event-main">
        <h4>{{ data.name }}</h4>
        <p class="event-date">{{ formattedDate }}</p>
        <p v-if="widget.showLocation !== false && data.location" class="event-location">{{ data.location }}</p>
      </div>
      <div v-if="widget.showCapacity !== false" class="metrics">
        <div><strong>{{ capacityText }}</strong><span>{{ t('intravox', 'Capacity') }}</span></div>
        <div><strong>{{ valueOrDash(data.registered) }}</strong><span>{{ t('intravox', 'Registered') }}</span></div>
        <div><strong>{{ availabilityText }}</strong><span>{{ t('intravox', 'Available') }}</span></div>
      </div>
      <p v-if="widget.showNewOrders !== false" class="orders">
        {{ n('intravox', '%n new registration in the last {hours} hours', '%n new registrations in the last {hours} hours', data.newOrders || 0, { hours: data.newOrdersHours }) }}
      </p>
      <a v-if="widget.showBackendLink && data.backendUrl" :href="data.backendUrl" target="_blank" rel="noopener noreferrer">
        {{ t('intravox', 'Open in Pretix') }}
      </a>
    </div>
  </section>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { translate, translatePlural } from '@nextcloud/l10n';
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue';

export default {
  name: 'PretixWidget',
  components: { NcLoadingIcon, NcNoteCard },
  props: {
    widget: { type: Object, required: true },
    pageId: { type: String, required: true },
  },
  data: () => ({ data: null, loading: true, error: false }),
  computed: {
    backgroundStyle() { return this.widget.backgroundColor ? { backgroundColor: this.widget.backgroundColor } : {}; },
    formattedDate() {
      if (!this.data?.dateFrom) return '';
      const start = new Date(this.data.dateFrom);
      const options = { dateStyle: 'full', timeStyle: 'short' };
      const from = new Intl.DateTimeFormat(undefined, options).format(start);
      if (!this.data.dateTo) return from;
      return `${from} – ${new Intl.DateTimeFormat(undefined, options).format(new Date(this.data.dateTo))}`;
    },
    capacityText() { if (!this.data.hasQuota) return '–'; return this.data.capacity === null ? this.t('intravox', 'Unlimited') : this.valueOrDash(this.data.capacity); },
    availabilityText() {
      if (!this.data.hasQuota) return '–';
      if (this.data.soldOut) return this.t('intravox', 'Sold out');
      return this.data.available === null ? this.t('intravox', 'Unlimited') : this.valueOrDash(this.data.available);
    },
  },
  mounted() { this.load(); },
  methods: {
    t: translate,
    n: translatePlural,
    valueOrDash(value) { return value === null || value === undefined ? '–' : String(value); },
    async load() {
      this.loading = true; this.error = false;
      try {
        const params = new URLSearchParams({
          pageId: this.pageId,
          organizer: this.widget.organizer || '', event: this.widget.event || '',
          quotaId: String(this.widget.quotaId || 0), newOrdersHours: String(this.widget.newOrdersHours || 24),
          showBackendLink: this.widget.showBackendLink ? '1' : '0',
        });
        const response = await axios.get(generateUrl(`/apps/intravox/api/pretix/widget-data?${params}`));
        this.data = response.data;
      } catch (e) { this.error = true; }
      finally { this.loading = false; }
    },
  },
};
</script>

<style scoped>
.pretix-widget { padding: 20px; border-radius: var(--border-radius-large); }
.event-card { display: grid; gap: 16px; }
.event-main h4 { margin: 0 0 6px; font-size: 1.35rem; }
.event-date, .event-location, .orders { margin: 4px 0; }
.metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.metrics div { padding: 12px; border-radius: var(--border-radius); background: var(--color-background-hover); }
.metrics strong, .metrics span { display: block; }
.metrics strong { font-size: 1.3rem; }
.metrics span { color: var(--color-text-maxcontrast); font-size: .85rem; }
@media (max-width: 600px) { .metrics { grid-template-columns: 1fr; } }
</style>
