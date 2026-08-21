#!/usr/bin/env node
/**
 * Line-ending guard (QG-1).
 *
 * This repo is historically mixed: 131 tracked source files use CRLF and ~289
 * use LF, the result of years of editing from different machines. Converting
 * them in one sweep was considered and rejected on purpose — EOL is not
 * whitespace to git, so `git diff -w` does NOT hide it. A normalisation commit
 * would rewrite ~15k lines, point every `git blame` at itself, and collide with
 * each of the ~22 open feature branches on nearly every line they touch.
 *
 * So the rule is: leave history alone, but stop the drift growing. A NEW file
 * must use LF. Existing files keep whatever they have and are only reported
 * when they become *mixed*, which is almost always an editor writing part of a
 * file with the wrong ending — the bug that actually corrupts diffs.
 *
 * A `.gitattributes` rule cannot express this: `text eol=lf` and `text=auto`
 * both renormalise the existing 131 files the moment they are added (verified),
 * and `-text` disables conversion without improving anything. Hence a guard.
 *
 * Run standalone or via `npm run lint:eol`.
 */

const { execFileSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

// Source we control. Build output (js/), generated l10n and vendored code are
// excluded: their endings are decided by tooling, not by a human editor.
const CHECKED_EXTENSIONS = ['.php', '.vue', '.js', '.css', '.json', '.md']
const EXCLUDED_PREFIXES = ['js/', 'l10n/', 'vendor/', 'node_modules/', 'demo-data/']

function trackedFiles() {
	const out = execFileSync('git', ['ls-files', '-z'], { cwd: REPO_ROOT, maxBuffer: 32 * 1024 * 1024 })
	return out.toString('utf8').split('\0').filter(Boolean)
}

/**
 * Files that are "new": staged additions in the index, plus anything added
 * relative to the upstream branch. The index matters most — it catches a CRLF
 * file at the moment it is being committed, which is the whole point. Comparing
 * only against upstream misses it whenever HEAD already equals upstream.
 */
function newFiles() {
	const added = new Set()

	// Staged additions (git add of a file not previously tracked).
	try {
		const out = execFileSync('git', ['diff', '--cached', '--name-only', '--diff-filter=A'], {
			cwd: REPO_ROOT, stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 32 * 1024 * 1024,
		})
		for (const f of out.toString('utf8').split('\n')) if (f) added.add(f)
	} catch { /* not a git repo / no index */ }

	// Additions this branch carries relative to its upstream.
	for (const base of ['gitea/main', 'origin/main', 'github/main']) {
		try {
			const out = execFileSync('git', ['diff', '--name-only', '--diff-filter=A', `${base}...HEAD`], {
				cwd: REPO_ROOT, stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 32 * 1024 * 1024,
			})
			for (const f of out.toString('utf8').split('\n')) if (f) added.add(f)
			break
		} catch { /* base ref not present; try the next one */ }
	}

	return added
}

function classify(absolutePath) {
	const raw = fs.readFileSync(absolutePath)
	let crlf = 0
	let lf = 0
	for (let i = 0; i < raw.length; i++) {
		if (raw[i] !== 0x0a) continue
		if (i > 0 && raw[i - 1] === 0x0d) crlf++
		else lf++
	}
	if (crlf && lf) return 'mixed'
	if (crlf) return 'crlf'
	return 'lf'
}

function main() {
	const added = newFiles()
	const problems = []

	for (const file of trackedFiles()) {
		if (!CHECKED_EXTENSIONS.includes(path.extname(file))) continue
		if (EXCLUDED_PREFIXES.some((p) => file.startsWith(p))) continue

		const absolutePath = path.join(REPO_ROOT, file)
		if (!fs.existsSync(absolutePath)) continue

		const kind = classify(absolutePath)

		if (kind === 'mixed') {
			problems.push(`${file}: mixed line endings (an editor wrote part of this file with the wrong ending)`)
		} else if (kind === 'crlf' && added.has(file)) {
			problems.push(`${file}: new file uses CRLF — new files must use LF`)
		}
	}

	if (problems.length > 0) {
		console.error('✗ Line-ending check failed:\n')
		for (const p of problems) console.error(`  ${p}`)
		console.error('\nFix with:  perl -pi -e \'s/\\r\\n/\\n/g\' <file>')
		console.error('Existing CRLF files are deliberately left alone — do not mass-convert them.')
		process.exit(1)
	}

	console.log('✓ Line endings: no mixed files, no new CRLF files')
}

main()
