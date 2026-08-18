import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import App from './App.vue';
import PublicPageView from './components/PublicPageView.vue';
import { parseFragment } from './utils/headingAnchors.js';

// Get the root element
const rootElement = document.getElementById('app-intravox');

// Check if this is a public page view (token-based sharing of internal pages)
// Can be indicated by:
// 1. data-is-public="true" attribute on root element (set by PHP)
// 2. URL path /s/{token} (share access route)
// 3. ?share= query parameter (legacy, may not work due to NC routing)
const urlParams = new URLSearchParams(window.location.search);
const pathMatch = window.location.pathname.match(/\/apps\/intravox\/s\/([a-zA-Z0-9]+)/);
const shareToken = rootElement?.dataset?.token || (pathMatch ? pathMatch[1] : null) || urlParams.get('share') || '';
const isPublic = rootElement?.dataset?.isPublic === 'true' || (shareToken && !document.querySelector('#user-menu'));

let app;

if (isPublic && shareToken) {
	// Public page mode - render simplified view (token-based sharing)
	// Priority: ?page= query param (survives chat apps/email) > #hash (legacy)
	const pageFromQuery = urlParams.get('page') || '';
	// Een sectielink draagt de pagina bij zich: `#<pageId>#h-<slug>`. Zonder
	// splitsen werd die hele string als pagina-id doorgegeven en meldde de
	// share "pagina niet beschikbaar".
	const pageFromHash = parseFragment(window.location.hash).pageId || '';
	const pageUniqueId = pageFromQuery || pageFromHash;

	app = createApp(PublicPageView, {
		token: shareToken,
		pageUniqueId,
	});
} else {
	// Normal authenticated mode
	app = createApp(App);
}

// Make translation functions globally available
app.config.globalProperties.$t = (text, vars = {}) => translate('intravox', text, vars);
app.config.globalProperties.$n = (singular, plural, count, vars = {}) => translatePlural('intravox', singular, plural, count, vars);

app.mount('#app-intravox');
