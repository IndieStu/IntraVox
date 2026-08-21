#!/bin/bash
#
# check-package-contents.sh — validate a release tarball before it ships.
#
# Usage: scripts/check-package-contents.sh <tarball>
#
# Why this exists: lib/AppInfo/Application.php loads vendor/autoload.php behind a
# file_exists() guard. A tarball built without vendor/ therefore fails SILENTLY —
# enshrined/svg-sanitize and lsolesen/pel are simply absent, and the first SVG
# upload fatals on a missing class (MediaSanitizer catches \Exception, but a
# missing class throws \Error). intravox-2.2.0.tar.gz shipped exactly this way:
# 906 entries, 0 under vendor/.
#
# The mirror-image failure is just as bad: shipping the dev tree, which drops
# phpunit and mockery into every end-user install. Build vendor/ with
# `composer install --no-dev` and this script proves both properties.

set -euo pipefail

TARBALL="${1:-}"

if [ -z "$TARBALL" ]; then
    echo "Usage: $0 <tarball>" >&2
    exit 2
fi

if [ ! -f "$TARBALL" ]; then
    echo "✗ Not a file: $TARBALL" >&2
    exit 2
fi

# The package root is the single top-level directory inside the tarball.
LISTING=$(tar -tzf "$TARBALL")
ROOT=$(printf '%s\n' "$LISTING" | head -1 | cut -d/ -f1)

if [ -z "$ROOT" ]; then
    echo "✗ Could not determine package root in $TARBALL" >&2
    exit 2
fi

FAILURES=0

fail() {
    echo "  ✗ $1" >&2
    FAILURES=$((FAILURES + 1))
}

pass() {
    echo "  ✓ $1"
}

# require_exact <path-inside-package> <description>
require_exact() {
    if printf '%s\n' "$LISTING" | grep -qx "${ROOT}/$1"; then
        pass "$2"
    else
        fail "missing $2 (${ROOT}/$1)"
    fi
}

# require_prefix <path-prefix-inside-package> <description>
require_prefix() {
    if printf '%s\n' "$LISTING" | grep -q "^${ROOT}/$1"; then
        pass "$2"
    else
        fail "missing $2 (${ROOT}/$1...)"
    fi
}

# forbid_prefix <path-prefix-inside-package> <description>
forbid_prefix() {
    if printf '%s\n' "$LISTING" | grep -q "^${ROOT}/$1"; then
        fail "$2 must not ship (${ROOT}/$1...)"
        printf '%s\n' "$LISTING" | grep "^${ROOT}/$1" | head -3 | sed 's/^/      /' >&2
    else
        pass "no $2"
    fi
}

echo "Checking $TARBALL (package root: $ROOT)"

echo "App structure:"
require_exact "appinfo/info.xml"              "app metadata"
require_exact "lib/AppInfo/Application.php"   "application bootstrap"

echo "Composer dependencies:"
require_exact "vendor/autoload.php"                          "composer autoloader"
require_prefix "vendor/enshrined/svg-sanitize/"              "enshrined/svg-sanitize (SVG upload)"
require_prefix "vendor/lsolesen/pel/"                        "lsolesen/pel (EXIF)"

echo "Dev dependencies (must be absent):"
forbid_prefix "vendor/phpunit/"  "phpunit"
forbid_prefix "vendor/mockery/"  "mockery"
forbid_prefix "vendor/bin/"      "vendor binaries"

echo "Secrets (must be absent):"
if printf '%s\n' "$LISTING" | grep -qE "\.(key|pem|csr|crt)$"; then
    fail "key/certificate material present in tarball"
    printf '%s\n' "$LISTING" | grep -E "\.(key|pem|csr|crt)$" | head -3 | sed 's/^/      /' >&2
else
    pass "no key/certificate material"
fi

echo
if [ "$FAILURES" -gt 0 ]; then
    echo "✗ Package check FAILED ($FAILURES problem(s))" >&2
    exit 1
fi

echo "✓ Package check passed"
