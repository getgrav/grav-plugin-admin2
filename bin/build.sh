#!/usr/bin/env bash
#
# Build the Admin2 SPA directly into the plugin's app/ directory.
#
# The SvelteKit build emits a token placeholder as its base path; the PHP
# plugin substitutes the token for the configured route at serve time, so
# the build is route-agnostic and does not need rebuilding when the route
# changes.
#
# Usage:
#   ./bin/build.sh                          # Build from default location
#   ./bin/build.sh /path/to/grav-admin-next # Build from custom location
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
APP_DIR="${PLUGIN_DIR}/app"

# Default SvelteKit project location (sibling repo)
SVELTE_PROJECT="${1:-$(dirname "$PLUGIN_DIR")/grav-admin-next}"

if [ ! -d "$SVELTE_PROJECT" ]; then
    echo "Error: SvelteKit project not found at: $SVELTE_PROJECT"
    echo "Usage: $0 /path/to/grav-admin-next"
    exit 1
fi

echo "Building SvelteKit app from: $SVELTE_PROJECT"
echo "Output directory: $APP_DIR"

# Clean the previous output so stale chunks do not linger.
rm -rf "$APP_DIR"

# Build: ADMIN2_PLUGIN_BUILD=1 tells svelte.config.js to write adapter-static
# output directly into this plugin's app/ directory.
cd "$SVELTE_PROJECT"
ADMIN2_PLUGIN_BUILD=1 npm run build

if [ ! -d "$APP_DIR" ]; then
    echo "Error: Build output not found at $APP_DIR"
    echo "Make sure svelte.config.js routes output into the plugin when ADMIN2_PLUGIN_BUILD is set."
    exit 1
fi

echo ""
echo "Build complete. Files written to: $APP_DIR"
echo "Contents:"
ls -la "$APP_DIR"
