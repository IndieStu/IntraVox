#!/usr/bin/env node
/**
 * Sync version between package.json, appinfo/info.xml and openapi.json
 *
 * openapi.json is in the release tarball (create-release.sh:139), so its
 * info.version is published to every installation. A spec that announces a
 * version the app is not is worse than one with no version at all: an
 * integrator uses it to decide which endpoints to expect.
 *
 * Usage:
 *   node scripts/sync-version.js           # Show current versions
 *   node scripts/sync-version.js 0.6.0     # Set version to 0.6.0 in both files
 *   node scripts/sync-version.js --check   # Check if versions are in sync (for CI/prebuild)
 *   npm run version:sync -- 0.6.0          # Same via npm
 */

const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.resolve(__dirname, '..');
const PACKAGE_JSON = path.join(ROOT_DIR, 'package.json');
const INFO_XML = path.join(ROOT_DIR, 'appinfo', 'info.xml');
const OPENAPI_JSON = path.join(ROOT_DIR, 'openapi.json');

// Read current versions
function getPackageVersion() {
    const pkg = JSON.parse(fs.readFileSync(PACKAGE_JSON, 'utf8'));
    return pkg.version;
}

function getInfoXmlVersion() {
    const xml = fs.readFileSync(INFO_XML, 'utf8');
    const match = xml.match(/<version>([^<]+)<\/version>/);
    return match ? match[1] : null;
}

// Update versions
function getOpenApiVersion() {
    const spec = JSON.parse(fs.readFileSync(OPENAPI_JSON, 'utf8'));
    return spec.info ? spec.info.version : null;
}

function setPackageVersion(version) {
    const pkg = JSON.parse(fs.readFileSync(PACKAGE_JSON, 'utf8'));
    pkg.version = version;
    fs.writeFileSync(PACKAGE_JSON, JSON.stringify(pkg, null, 2) + '\n');
}

function setInfoXmlVersion(version) {
    let xml = fs.readFileSync(INFO_XML, 'utf8');
    xml = xml.replace(/<version>[^<]+<\/version>/, `<version>${version}</version>`);
    fs.writeFileSync(INFO_XML, xml);
}

/**
 * Rewrite only the version string, not the whole document.
 *
 * A JSON round-trip would reformat 10k lines and bury a one-word change in an
 * unreviewable diff. The spec is hand-maintained; leave its formatting alone.
 */
function setOpenApiVersion(version) {
    const raw = fs.readFileSync(OPENAPI_JSON, 'utf8');
    const current = getOpenApiVersion();
    if (current === null) {
        console.error('❌ openapi.json has no info.version to update');
        process.exit(1);
    }

    const pattern = new RegExp(`("version"\\s*:\\s*)"${current.replace(/\./g, '\\.')}"`);
    if (!pattern.test(raw)) {
        console.error('❌ Could not locate info.version in openapi.json');
        process.exit(1);
    }

    fs.writeFileSync(OPENAPI_JSON, raw.replace(pattern, `$1"${version}"`));
}

// Main
const arg = process.argv[2];
const pkgVersion = getPackageVersion();
const xmlVersion = getInfoXmlVersion();
const specVersion = getOpenApiVersion();

// Check mode: verify versions are in sync (for prebuild/CI)
if (arg === '--check') {
    if (pkgVersion !== xmlVersion || pkgVersion !== specVersion) {
        console.error('❌ Version mismatch detected!');
        console.error(`   package.json:     ${pkgVersion}`);
        console.error(`   appinfo/info.xml: ${xmlVersion}`);
        console.error(`   openapi.json:     ${specVersion}`);
        console.error('\nRun "npm run version:sync -- <version>" to fix this.');
        process.exit(1);
    }
    console.log(`✅ Versions in sync: ${pkgVersion}`);
    process.exit(0);
}

console.log('IntraVox Version Sync');
console.log('=====================\n');
console.log('Current versions:');
console.log(`  package.json:     ${pkgVersion}`);
console.log(`  appinfo/info.xml: ${xmlVersion}`);
console.log(`  openapi.json:     ${specVersion}`);

if (pkgVersion !== xmlVersion || pkgVersion !== specVersion) {
    console.log('\n⚠️  Versions are out of sync!');
}

if (arg && arg !== '--check') {
    const newVersion = arg;

    // Validate semver format
    if (!/^\d+\.\d+\.\d+$/.test(newVersion)) {
        console.error('\n❌ Invalid version format. Use semantic versioning (e.g., 0.6.0)');
        process.exit(1);
    }

    console.log(`\nUpdating all three files to version ${newVersion}...`);
    setPackageVersion(newVersion);
    setInfoXmlVersion(newVersion);
    setOpenApiVersion(newVersion);
    console.log('✅ Done!\n');

    console.log('Updated versions:');
    console.log(`  package.json:     ${getPackageVersion()}`);
    console.log(`  appinfo/info.xml: ${getInfoXmlVersion()}`);
    console.log(`  openapi.json:     ${getOpenApiVersion()}`);
} else if (!arg) {
    console.log('\nUsage:');
    console.log('  npm run version:sync -- <version>   Set version in all three files');
    console.log('  npm run version:sync -- --check     Check if versions match');
    console.log('\nExample: npm run version:sync -- 0.6.0');
}
