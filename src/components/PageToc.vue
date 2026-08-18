<template>
  <div class="page-toc">
    <p v-if="items.length === 0" class="toc-empty">
      {{ t('intravox', 'This page has no headings.') }}
    </p>
    <ul v-else class="toc-list">
      <li v-for="item in items" :key="item.key">
        <button
          class="toc-item"
          :class="{ 'is-active': item.key === activeKey }"
          :style="{ paddingLeft: (24 + item.depth * 16) + 'px' }"
          :title="item.text"
          @click="goTo(item)"
        >
          {{ item.text }}
        </button>
      </li>
    </ul>
  </div>
</template>

<script>
import { translate } from '@nextcloud/l10n';
import { buildSectionFragment } from '../utils/headingAnchors.js';

/**
 * Inhoudsopgave van de HUIDIGE pagina: de koppen van de gerenderde pagina, als
 * navigatielijst.
 *
 * De koppen komen uit de DOM, niet uit page.layout. Dat is bewust: koppen
 * bestaan in twee smaken — losse heading-widgets én markdown-koppen binnen een
 * tekstwidget — en alleen de gerenderde pagina heeft ze allebei, in leesvolgorde.
 * Beide dragen een `h-…`-id (zie utils/headingAnchors.js en utils/markdownSerializer.js),
 * dus één query vindt ze. Het leest bovendien altijd wat er écht staat, dus een
 * versievoorbeeld en ingeklapte secties kloppen vanzelf.
 */
export default {
  name: 'PageToc',
  props: {
    /**
     * Verandert deze waarde, dan wordt opnieuw gescand. Bevat de pagina-id plus
     * een teller die ophoogt als secties open/dicht klappen.
     */
    pageKey: {
      type: String,
      default: ''
    },
    // Pagina waartoe deze koppen behoren; hoort in de sectielink thuis
    currentPageId: {
      type: String,
      default: ''
    },
    contentSelector: {
      type: String,
      default: '#intravox-main-content'
    }
  },
  emits: ['navigate-heading'],
  data() {
    return {
      items: [],
      activeKey: null,
      scanTimer: null,
      // Het element dat werkelijk scrollt (verschilt per weergave)
      scroller: null,
      scrollRaf: null,
      // Tijdstempel tot wanneer een klikkeuze voorrang heeft op de scrollpositie
      lockActiveUntil: 0
    };
  },
  mounted() {
    this.scheduleScan();
  },
  beforeUnmount() {
    this.stopSpy();
    if (this.scanTimer) {
      clearTimeout(this.scanTimer);
    }
  },
  watch: {
    pageKey() {
      this.scheduleScan();
    }
  },
  methods: {
    t(app, text, vars) {
      return translate(app, text, vars);
    },
    scheduleScan() {
      // Widget.vue decoreert koppen in mounted()/updated(); één tick is soms te
      // vroeg, dus na de tick nog een macrotask wachten.
      if (this.scanTimer) {
        clearTimeout(this.scanTimer);
      }
      this.$nextTick(() => {
        this.scanTimer = setTimeout(this.scanHeadings, 0);
      });
    },
    scanHeadings() {
      const root = document.querySelector(this.contentSelector);
      if (!root) {
        this.items = [];
        return;
      }

      const nodes = root.querySelectorAll(
        'h1[id^="h-"], h2[id^="h-"], h3[id^="h-"], h4[id^="h-"], h5[id^="h-"], h6[id^="h-"]'
      );

      const found = [];
      nodes.forEach((el, index) => {
        // offsetParent is null voor koppen in een ingeklapte sectie: die staan
        // wel in de DOM maar zijn display:none. Zo'n kop hoort niet in een
        // inhoudsopgave — je kunt er niet heen springen.
        if (el.offsetParent === null) return;
        const text = (el.textContent || '').trim();
        if (!text) return;
        found.push({
          // Positie-gebaseerde sleutel: de ids zelf zijn alleen per tekstwidget
          // uniek gemaakt, dus over een hele pagina kunnen ze dubbel voorkomen.
          key: `${index}-${el.id}`,
          id: el.id,
          level: Number(el.tagName.slice(1)) || 1,
          text,
          el
        });
      });

      const minLevel = found.reduce((min, h) => Math.min(min, h.level), 6);
      this.items = found.map((h) => ({
        ...h,
        // Relatief inspringen: een pagina die met H2 begint start niet ingesprongen
        depth: Math.min(h.level - minLevel, 3)
      }));

      this.startSpy();
    },
    goTo(item) {
      item.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      this.activeKey = item.key;
      // Tijdens de smooth-scroll passeert de leeslijn tussenliggende koppen; de
      // spy zou de markering dan weghalen bij de kop waarop je klikte. Even
      // stilzetten tot de scroll is uitgelopen.
      this.lockActiveUntil = Date.now() + 900;
      // replaceState i.p.v. location.hash: een hashwijziging triggert
      // handleHashChange in App.vue en zou een tweede scroll veroorzaken.
      try {
        const url = new URL(window.location.href);
        // Pagina-id meenemen, zodat de URL in de adresbalk deelbaar blijft
        url.hash = buildSectionFragment(this.currentPageId, item.id);
        window.history.replaceState(null, '', url.toString());
      } catch (e) {
        // Geen geldige URL-context: de scroll is al gebeurd, dit is bijzaak
      }
      this.$emit('navigate-heading', item.id);
    },
    /**
     * Zoek het element dat werkelijk scrollt. Dat is per weergave verschillend
     * (#app-intravox in de app, het document in andere contexten), en een
     * IntersectionObserver met root: null gaat daar de mist in: zijn rootMargin
     * rekent dan met de viewport terwijl de content in een andere doos schuift,
     * waardoor de selectie koppen oversloeg.
     */
    findScroller() {
      let node = this.items[0]?.el?.parentElement;
      while (node && node !== document.body) {
        const cs = window.getComputedStyle(node);
        if (/(auto|scroll)/.test(cs.overflowY) && node.scrollHeight > node.clientHeight + 4) {
          return node;
        }
        node = node.parentElement;
      }
      return window;
    },
    startSpy() {
      this.stopSpy();
      if (this.items.length === 0) return;

      this.scroller = this.findScroller();
      this.scroller.addEventListener('scroll', this.onScroll, { passive: true });
      window.addEventListener('resize', this.onScroll, { passive: true });
      this.onScroll();
    },
    onScroll() {
      if (this.scrollRaf) return;
      this.scrollRaf = window.requestAnimationFrame(() => {
        this.scrollRaf = null;
        this.updateActiveHeading();
      });
    },
    updateActiveHeading() {
      if (!this.items.length) return;
      // Zojuist ergens op geklikt: die keuze wint van de scrollpositie
      if (this.lockActiveUntil && Date.now() < this.lockActiveUntil) return;

      // Leeslijn net onder de sticky topbar: de laatste kop die daarboven staat,
      // is de sectie waarin je leest.
      const topbar = parseInt(
        window.getComputedStyle(document.documentElement)
          .getPropertyValue('--intravox-topbar-height'), 10
      ) || 0;
      const leeslijn = topbar + 24;

      let gevonden = null;
      for (const item of this.items) {
        if (item.el.getBoundingClientRect().top <= leeslijn) {
          gevonden = item;
        } else {
          break;
        }
      }

      // Nog boven de eerste kop: markeer die, zodat de lijst nooit leeg oogt.
      this.activeKey = (gevonden || this.items[0]).key;
    },
    stopSpy() {
      if (this.scroller) {
        this.scroller.removeEventListener('scroll', this.onScroll);
        this.scroller = null;
      }
      window.removeEventListener('resize', this.onScroll);
      if (this.scrollRaf) {
        window.cancelAnimationFrame(this.scrollRaf);
        this.scrollRaf = null;
      }
    }
  }
};
</script>

<style scoped>
.page-toc {
  padding-bottom: 4px;
}

.toc-empty {
  margin: 8px 0;
  color: var(--color-text-maxcontrast);
  font-size: 13px;
}

.toc-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

/* Uitgelijnd op .tree-item-row/.tree-item-content in PageTreeItem.vue, zodat
   beide weergaven van het paneel dezelfde regelopmaak hebben: gelijke hoogte,
   hover-vlak, afronding en actieve markering. De basis-inspringing van 24px
   houdt de tekst op één lijn met de titels in de boom, die daar de chevron-
   kolom hebben staan. */
.toc-item {
  display: block;
  width: 100%;
  padding: 8px;
  background: none;
  border: none;
  border-radius: 4px;
  color: var(--color-main-text);
  font-size: 15px;
  /* Nextcloud maakt élke button bold; een navigatielijst hoort normale
     tekstdikte te hebben, met vet alleen voor de actieve regel. */
  font-weight: normal;
  text-align: left;
  line-height: 1.35;
  cursor: pointer;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Hover blijft bewust subtiel: alleen de tekst reageert. Een gevuld hover-vlak
   leest in deze lijst als een tweede selectie, omdat het naast de actieve regel
   komt te staan zodra de scroll-spy verspringt.
   De !important is nodig omdat Nextcloud globaal élke button bij hover
   --color-primary-element-light-hover geeft — bijna dezelfde tint als onze
   actieve markering. */
.toc-item:hover,
.toc-item:focus {
  background-color: transparent !important;
  color: var(--color-primary-element);
}

/* Alleen de actieve regel draagt een vlak, ongeacht muis of focus */
.toc-item.is-active,
.toc-item.is-active:hover,
.toc-item.is-active:focus {
  background-color: var(--color-primary-element-light) !important;
}

/* Toetsenbordgebruikers houden een zichtbare focusring */
.toc-item:focus-visible {
  outline: 2px solid var(--color-primary-element);
  outline-offset: -2px;
}

/* Actief moet duidelijk verschillen van hover: die twee tinten (#f5f5f5 en
   #e5eff5) liggen te dicht bij elkaar, waardoor het leek alsof twee items
   tegelijk actief waren. Een gekleurde balk en tekstkleur maken het ondubbelzinnig. */
.toc-item.is-active {
  background-color: var(--color-primary-element-light);
  box-shadow: inset 3px 0 0 var(--color-primary-element);
  color: var(--color-primary-element);
  font-weight: 600;
}

</style>
