#!/usr/bin/env node
/**
 * Security-marker guard (MARK-1).
 *
 * Every rate limit in this app was dead. 28 controller methods carried
 * #[AnonRateThrottle] / #[UserRateThrottle] attributes naming classes that do
 * not exist — Nextcloud's are AnonRateLimit and UserRateLimit. PHP instantiates
 * attributes lazily, so a bogus attribute class is never resolved and never
 * errors: RateLimitingMiddleware filters with getAttributes(AnonRateLimit::class,
 * IS_INSTANCEOF) and simply never matched. The code read as protected for years
 * while being wide open.
 *
 * The trap is in Nextcloud itself: the legacy *annotation* really is spelled
 * @AnonRateThrottle, while the *attribute class* is AnonRateLimit. Copying the
 * annotation name into an attribute produces silent, invisible failure.
 *
 * A second instance of the same class of bug: @BruteForceProtection(action="x")
 * with quotes. ControllerMethodReflector splits annotation parameters on '='
 * and never strips quotes, so the action became "x" (21 chars, quotes included)
 * while registerAttempt() used x (19 chars). BruteForceMiddleware prefers the
 * annotation over the attribute, so the broken one won.
 *
 * This guard fails on any security marker that cannot work:
 *   1. attribute classes that do not exist in Nextcloud;
 *   2. quoted annotation parameters;
 *   3. attributes imported from a namespace that does not provide them.
 *
 * Run standalone or via `npm run lint:security`.
 */

const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

const REPO_ROOT = path.resolve(__dirname, '..')

/**
 * Attribute names that exist under OCP\AppFramework\Http\Attribute.
 * Checked against a real Nextcloud 34 tree.
 */
const REAL_ATTRIBUTES = new Set([
	'AnonRateLimit',
	'UserRateLimit',
	'ARateLimit',
	'BruteForceProtection',
	'NoAdminRequired',
	'NoCSRFRequired',
	'PublicPage',
	'PasswordConfirmationRequired',
	'StrictCookieRequired',
	'UseSession',
	'SubAdminRequired',
	'AuthorizedAdminSetting',
	'ExAppRequired',
	'AppApiAdminAccessWithoutUser',
	'OpenAPI',
	'Route',
	'FrontpageRoute',
	'ApiRoute',
	'IgnoreOpenAPI',
	'RequestHeader',
	'ExemptFromSameSiteCookieProtection',
])

/**
 * Names that look like security markers but are not attribute classes.
 * Using one as an attribute is the exact bug this guard exists for.
 */
const ANNOTATION_ONLY = new Map([
	['AnonRateThrottle', 'AnonRateLimit'],
	['UserRateThrottle', 'UserRateLimit'],
])

function controllerFiles() {
	const out = execFileSync('git', ['ls-files', '-z', 'lib/'], { cwd: REPO_ROOT, maxBuffer: 32 * 1024 * 1024 })
	return out.toString('utf8').split('\0').filter((f) => f.endsWith('.php'))
}

function main() {
	const problems = []

	for (const file of controllerFiles()) {
		const absolutePath = path.join(REPO_ROOT, file)
		if (!fs.existsSync(absolutePath)) continue
		const source = fs.readFileSync(absolutePath, 'utf8')
		const lines = source.split(/\r?\n/)

		lines.forEach((line, index) => {
			const lineNo = index + 1

			// 1. #[Something(...)] naming a class that cannot exist.
			const attr = line.match(/^\s*#\[(\w+)/)
			if (attr) {
				const name = attr[1]
				if (ANNOTATION_ONLY.has(name)) {
					problems.push(
						`${file}:${lineNo}: #[${name}] is an ANNOTATION name, not an attribute class. `
						+ `PHP resolves attributes lazily so this fails silently. Use #[${ANNOTATION_ONLY.get(name)}] instead.`
					)
				} else if (/RateLimit|RateThrottle|BruteForce/.test(name) && !REAL_ATTRIBUTES.has(name)) {
					problems.push(`${file}:${lineNo}: #[${name}] is not a known Nextcloud attribute class.`)
				}
			}

			// 2. Quoted annotation parameters. ControllerMethodReflector splits on
			//    '=' and keeps the quotes, so the value never matches the action
			//    string passed to registerAttempt().
			const annotation = line.match(/^\s*\*\s*@(BruteForceProtection|AnonRateThrottle|UserRateThrottle)\((.*)\)/)
			if (annotation && /["']/.test(annotation[2])) {
				problems.push(
					`${file}:${lineNo}: @${annotation[1]}(${annotation[2]}) contains quotes. `
					+ 'The annotation parser does not strip them, so the value silently differs from the registered action.'
				)
			}

			// 3. Imports of attribute classes that do not exist.
			const use = line.match(/^use\s+OCP\\AppFramework\\Http\\Attribute\\(\w+);/)
			if (use && !REAL_ATTRIBUTES.has(use[1])) {
				problems.push(`${file}:${lineNo}: imports OCP\\AppFramework\\Http\\Attribute\\${use[1]}, which does not exist.`)
			}
		})
	}

	if (problems.length > 0) {
		console.error('✗ Security-marker check failed:\n')
		for (const p of problems) console.error(`  ${p}`)
		console.error('\nA marker that cannot fire is worse than no marker: it reads as protection that is not there.')
		process.exit(1)
	}

	console.log('✓ Security markers: all rate-limit and brute-force markers can actually fire')
}

main()
