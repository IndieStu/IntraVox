<template>
	<div id="intravox-public-page">
		<a href="#intravox-main-content" class="skip-link">{{ t('intravox', 'Skip to main content') }}</a>

		<!-- Loading state -->
		<div v-if="loading" class="public-loading" role="status" aria-live="polite">
			<div class="loading-spinner" />
			<span>{{ t('intravox', 'Loading …') }}</span>
		</div>

		<!-- Password required state -->
		<div v-else-if="passwordRequired" class="public-password-challenge">
			<div class="password-container">
				<div class="lock-icon">&#128274;</div>
				<h2>{{ t('intravox', 'Password required') }}</h2>
				<p>{{ t('intravox', 'This shared page is password protected. Enter the password to continue.') }}</p>
				<form @submit.prevent="submitPassword">
					<label for="intravox-public-password" class="visually-hidden">{{ t('intravox', 'Password') }}</label>
					<input
						id="intravox-public-password"
						ref="passwordInput"
						v-model="password"
						type="password"
						:placeholder="t('intravox', 'Password')"
						autocomplete="off"
						required>
					<p v-if="passwordError" class="password-error" role="alert">
						{{ t('intravox', 'Incorrect password. Please try again.') }}
					</p>
					<button type="submit" :disabled="submittingPassword">
						{{ t('intravox', 'Unlock') }}
					</button>
				</form>
			</div>
		</div>

		<!-- Error state -->
		<div v-else-if="error" class="public-error" role="alert">
			<h1>{{ t('intravox', 'Page not available') }}</h1>
			<p>{{ error }}</p>
		</div>

		<!-- Page content -->
		<template v-else-if="pageData">
			<!-- Header -->
			<header class="public-header">
				<h1 class="page-title">{{ pageData.title }}</h1>
			</header>

			<!-- Navigation (if available) -->
			<div v-if="navigation && navigation.items && navigation.items.length > 0" class="public-nav-bar">
				<Navigation
					:items="navigation.items"
					:type="navigation.type"
					:is-public="true"
					@navigate="handleNavigation" />
			</div>

			<!-- Content area met het structuur-paneel ernaast, zoals in de
			     ingelogde weergave. De Details-sidebar hoort hier bewust niet:
			     metadata en versiegeschiedenis zijn niet publiek (er bestaan
			     geen share-endpoints voor). -->
			<div class="public-content-wrapper">
				<PageTreeModal
					v-if="showPageTree"
					ref="pageTreePanel"
					variant="panel"
					:share-token="token"
					:current-page-id="currentPageUniqueId"
					:toc-page-key="tocPageKey"
					@navigate="handleTreeNavigate"
					@close="setPageTreeOpen(false)" />

				<div class="public-main-column">
					<!-- Breadcrumb met de structuur-toggle ervoor -->
					<div class="public-breadcrumb">
						<button class="structure-btn"
							:class="{ 'structure-btn-active': showPageTree }"
							:aria-expanded="showPageTree ? 'true' : 'false'"
							:aria-label="t('intravox', 'Page structure')"
							:title="t('intravox', 'Page structure')"
							@click="setPageTreeOpen(!showPageTree)">
							<FileTree :size="20" />
						</button>
						<Breadcrumb
							v-if="breadcrumb.length > 0"
							:breadcrumb="breadcrumb"
							@navigate="handleBreadcrumbNavigate" />
					</div>

					<!-- Main content -->
					<main class="public-content" id="intravox-main-content">
						<PageViewer
							:page="pageData"
							:is-public="true"
							:share-token="token"
							:engagement-settings="engagementSettings"
							@navigate="handleNavigation"
							@rows-changed="tocRevision++" />
					</main>
				</div>
			</div>

			<!-- Footer -->
			<div v-if="footerContent" class="public-footer">
				<Footer
					:footer-content="footerContent"
					:can-edit="false"
					:is-public="true" />
			</div>
		</template>
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { isSectionAnchor, scrollToHashAnchor, parseFragment } from '../utils/headingAnchors.js'
import PageViewer from './PageViewer.vue'
import Navigation from './Navigation.vue'
import Footer from './Footer.vue'
import Breadcrumb from './Breadcrumb.vue'
import FileTree from 'vue-material-design-icons/FileTree.vue'

export default {
	name: 'PublicPageView',
	components: {
		PageViewer,
		Navigation,
		Footer,
		Breadcrumb,
		FileTree,
		// Async to match App.vue's strategy. See scripts/check-import-consistency.js.
		PageTreeModal: defineAsyncComponent(() => import('./PageTreeModal.vue')),
	},
	props: {
		initialPageData: {
			type: Object,
			default: null,
		},
		token: {
			type: String,
			required: true,
		},
		pageUniqueId: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			pageData: null,
			navigation: null,
			footerContent: '',
			breadcrumb: [],
			// Structuur-paneel: open/dicht wordt onthouden, zoals in de app
			showPageTree: window.localStorage?.getItem('intravox:page-tree-open') === '1',
			// Hoogt op als secties open/dicht klappen, zodat de inhoudsopgave herscant
			tocRevision: 0,
			loading: true,
			error: null,
			passwordRequired: false,
			password: '',
			passwordError: false,
			submittingPassword: false,
			// Disabled engagement settings for public pages
			engagementSettings: {
				allowPageReactions: false,
				allowComments: false,
				allowCommentReactions: false,
			},
		}
	},
	computed: {
		currentPageUniqueId() {
			return this.pageData?.uniqueId || this.pageUniqueId || ''
		},
		/**
		 * Verandert zodra de inhoudsopgave opnieuw moet scannen: andere pagina,
		 * of een sectie die open/dicht klapt.
		 */
		tocPageKey() {
			return `${this.currentPageUniqueId}::${this.tocRevision}`
		},
	},
	async mounted() {
		// Use initial data if provided (from PHP template)
		if (this.initialPageData) {
			this.pageData = this.initialPageData
			this.loading = false
		}

		// Load page data from API (or refresh)
		await this.loadPage()

		// Load navigation filtered by share scope
		if (this.pageData && this.token) {
			await this.loadNavigation()
		}
	},
	methods: {
		t(app, key, vars = {}) {
			return t(app, key, vars)
		},
		async loadPage() {
			try {
				// Determine page to load: prop > query param > hash > homepage fallback
				let uniqueId = this.pageUniqueId || this.getPageIdFromUrl()
				if (!uniqueId) {
					// No page specified — try to load the homepage from the page tree
					uniqueId = await this.getHomepageUniqueId()
					if (!uniqueId) {
						this.error = t('intravox', 'No page specified.')
						this.loading = false
						return
					}
				}

				const response = await axios.get(
					generateUrl('/apps/intravox/api/share/{token}/page/{uniqueId}', {
						token: this.token,
						uniqueId,
					}),
				)
				this.pageData = response.data
				this.breadcrumb = response.data.breadcrumb || []
				this.error = null
				// Update URL hash for back/forward navigation
				this.updateUrl(uniqueId)
			} catch (err) {
				if (err.response?.status === 401 && err.response?.data?.passwordRequired) {
					this.passwordRequired = true
					this.$nextTick(() => this.$refs.passwordInput?.focus())
				} else if (!this.pageData) {
					this.error = t('intravox', 'This page is not available or the share link has expired.')
				}
			} finally {
				this.loading = false
			}
		},
		getPageIdFromUrl() {
			// Check ?page= query parameter first (survives chat apps, email clients)
			const params = new URLSearchParams(window.location.search)
			const pageFromQuery = params.get('page')
			if (pageFromQuery) {
				return pageFromQuery
			}
			// Fall back to hash. Een sectielink draagt de pagina bij zich
			// (`#<pageId>#h-<slug>`); zonder splitsen zou de hele string als
			// pagina-id gelden en de pagina niet gevonden worden.
			const { pageId } = parseFragment(window.location.hash)
			return pageId || ''
		},
		async getHomepageUniqueId() {
			try {
				const response = await axios.get(
					generateUrl('/apps/intravox/api/share/{token}/tree', { token: this.token }),
				)
				const tree = response.data?.tree || []
				// First item in the tree is typically the homepage
				if (tree.length > 0 && tree[0].uniqueId) {
					return tree[0].uniqueId
				}
			} catch (err) {
				// Page tree is optional, fail silently
			}
			return ''
		},
		updateUrl(uniqueId) {
			if (uniqueId) {
				// Keep ?page= as the canonical format (survives sharing via chat/email).
				// Preserve a section anchor (#h-…) if present; only clear legacy
				// page hashes (#page-…) so a deep link to a section keeps working.
				const url = new URL(window.location.href)
				url.searchParams.set('page', uniqueId)
				if (!isSectionAnchor(url.hash)) {
					url.hash = ''
				}
				window.history.replaceState(null, '', url.toString())
			}
		},
		async loadNavigation() {
			try {
				const response = await axios.get(
					generateUrl('/apps/intravox/api/share/{token}/navigation', { token: this.token }),
				)
				this.navigation = response.data.navigation
			} catch (err) {
				// Navigation is optional, fail silently
				this.navigation = null
			}
		},
		async loadFooter() {
			try {
				const response = await axios.get(
					generateUrl('/apps/intravox/public/api/footer/{token}', { token: this.token }),
				)
				this.footerContent = response.data.content || ''
			} catch (err) {
				// Footer is optional, fail silently
				this.footerContent = ''
			}
		},
		handleNavigation(item) {
			// This handler is fed by two kinds of emitter:
			//  - Navigation.vue emits the nav *item* (an object with uniqueId/url).
			//  - The widget chain (Links/News/Widget → PageViewer) emits a bare
			//    pageId *string*. Without the string branch, internal links inside
			//    a shared page silently did nothing (item.uniqueId is undefined on
			//    a string), so folder-share sub-page links appeared broken.
			if (typeof item === 'string') {
				if (item) {
					this.loadPageByUniqueId(item)
				}
				return
			}
			if (item.uniqueId) {
				this.loadPageByUniqueId(item.uniqueId)
			} else if (item.url) {
				if (item.url.startsWith('http') || item.url.startsWith('//')) {
					window.open(item.url, item.target || '_blank')
				} else {
					window.location.href = item.url
				}
			}
		},
		handleBreadcrumbNavigate(pageId) {
			if (pageId) {
				this.loadPageByUniqueId(pageId)
			}
		},
		setPageTreeOpen(open) {
			this.showPageTree = open
			try {
				window.localStorage.setItem('intravox:page-tree-open', open ? '1' : '0')
			} catch (e) {
				// Zonder storage (private mode) geldt de voorkeur alleen deze sessie
			}
		},
		handleTreeNavigate(uniqueId) {
			// Het paneel blijft open tijdens navigeren — het is een inhoudsopgave,
			// geen eenmalige keuzedialoog.
			if (uniqueId) {
				this.loadPageByUniqueId(uniqueId)
			}
		},
		async loadPageByUniqueId(uniqueId) {
			try {
				this.loading = true
				const response = await axios.get(
					generateUrl('/apps/intravox/api/share/{token}/page/{uniqueId}', {
						token: this.token,
						uniqueId,
					}),
				)
				this.pageData = response.data
				this.breadcrumb = response.data.breadcrumb || []
				this.error = null
				this.updateUrl(uniqueId)
				// Scroll to the top, unless a section anchor asks for a specific spot.
				this.$nextTick(() => {
					if (!scrollToHashAnchor()) {
						window.scrollTo(0, 0)
					}
				})
			} catch (err) {
				if (err.response?.status === 401 && err.response?.data?.passwordRequired) {
					this.passwordRequired = true
					this.$nextTick(() => this.$refs.passwordInput?.focus())
				} else {
					this.error = t('intravox', 'This page is not available or the share link has expired.')
				}
			} finally {
				this.loading = false
			}
		},
		async submitPassword() {
			this.submittingPassword = true
			this.passwordError = false
			try {
				const authenticateUrl = generateUrl('/apps/intravox/s/{shareToken}/authenticate', {
					shareToken: this.token,
				})
				await axios.post(authenticateUrl, {
					password: this.password,
				}, {
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					maxRedirects: 0,
					validateStatus: (status) => status < 400,
				})
				// Password accepted — reload the page to pick up the session cookie
				window.location.reload()
			} catch (err) {
				this.passwordError = true
				this.password = ''
				this.$nextTick(() => this.$refs.passwordInput?.focus())
			} finally {
				this.submittingPassword = false
			}
		},
	},
}
</script>

<style scoped>
#intravox-public-page {
	min-height: 100vh;
	background: var(--color-main-background, #fff);
	display: flex;
	flex-direction: column;
}

.public-header {
	padding: 24px 20px;
	background: var(--color-main-background, #fff);
	border-bottom: 1px solid var(--color-border, #eee);
}

.page-title {
	margin: 0;
	font-size: 28px;
	font-weight: 600;
	color: var(--color-main-text, #333);
	max-width: min(1600px, 95vw);
	margin: 0 auto;
}

.public-nav-bar {
	background: var(--color-main-background, #fff);
	border-bottom: 1px solid var(--color-border, #eee);
	padding: 0 20px;
}

/* Content-zone met het structuur-paneel links, zoals in de ingelogde weergave */
.public-content-wrapper {
	display: flex;
	position: relative;
	width: 100%;
	flex: 1;
	min-height: 0;
}

.public-main-column {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
}

.public-breadcrumb {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 8px 20px 0;
	max-width: min(1600px, 95vw);
	margin: 0 auto;
	width: 100%;
	box-sizing: border-box;
}

/* Structuur-toggle, gelijk aan de knop in de ingelogde weergave */
.structure-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	padding: 0;
	background: none;
	border: none;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	cursor: pointer;
	flex-shrink: 0;
	transition: background-color 0.1s ease;
}

.structure-btn:hover {
	background-color: var(--color-background-hover);
}

.structure-btn-active {
	background-color: var(--color-primary-element-light);
}

.public-content {
	flex: 1;
	padding: 20px;
	padding-bottom: 60px;
	max-width: min(1600px, 95vw);
	margin: 0 auto;
	width: 100%;
	box-sizing: border-box;
}

.public-footer {
	border-top: 1px solid var(--color-border, #eee);
	padding: 20px;
	background: var(--color-background-dark, #f5f5f5);
}

.public-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-height: 100vh;
	gap: 16px;
	color: var(--color-text-maxcontrast, #666);
}

.loading-spinner {
	width: 32px;
	height: 32px;
	border: 3px solid var(--color-border, #eee);
	border-top-color: var(--color-primary, #0082c9);
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

.public-error {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-height: 100vh;
	text-align: center;
	padding: 20px;
}

.public-error h1 {
	font-size: 24px;
	color: var(--color-main-text, #333);
	margin-bottom: 8px;
}

.public-error p {
	color: var(--color-text-maxcontrast, #666);
}

.public-password-challenge {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 100vh;
}

.password-container {
	text-align: center;
	padding: 40px;
	background: var(--color-main-background, #fff);
	border-radius: 8px;
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
	max-width: 400px;
	width: 90%;
}

.password-container .lock-icon {
	font-size: 48px;
	margin-bottom: 10px;
	color: var(--color-text-maxcontrast, #999);
}

.password-container h2 {
	font-size: 22px;
	margin: 10px 0 8px;
	color: var(--color-main-text, #333);
}

.password-container p {
	color: var(--color-text-maxcontrast, #666);
	line-height: 1.5;
	margin: 0 0 24px;
	font-size: 14px;
}

.password-container form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.password-container input[type="password"] {
	padding: 10px 14px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 6px;
	font-size: 14px;
	outline: none;
	transition: border-color 0.2s;
}

.password-container input[type="password"]:focus {
	border-color: var(--color-primary, #0082c9);
}

.password-container button[type="submit"] {
	padding: 10px 14px;
	background: var(--color-primary, #0082c9);
	color: #fff;
	border: none;
	border-radius: 6px;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.2s;
}

.password-container button[type="submit"]:hover {
	background: var(--color-primary-hover, #006ba7);
}

.password-container button[type="submit"]:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.password-error {
	color: var(--color-error, #e9322d) !important;
	font-size: 13px !important;
	margin: -4px 0 0 !important;
}

@media (max-width: 768px) {
	.public-header {
		padding: 16px 12px;
	}

	.page-title {
		font-size: 22px;
	}

	.public-content {
		padding: 16px 12px;
	}

}
</style>
