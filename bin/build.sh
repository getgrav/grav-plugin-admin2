#!/usr/bin/env bash
#
# Build the Admin2 SPA and copy the output into the plugin's app/ directory.
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

# Base path for the SPA (must match the Grav site base + plugin route)
# Override with: ADMIN2_BASE=/my-site/admin2 ./bin/build.sh
ADMIN2_BASE="${ADMIN2_BASE:-/grav-api/admin2}"

echo "SvelteKit base path: $ADMIN2_BASE"

# Build the SvelteKit app with adapter-static
cd "$SVELTE_PROJECT"
ADMIN2_BASE="$ADMIN2_BASE" npm run build

# Find the build output (adapter-static outputs to build/)
BUILD_DIR="$SVELTE_PROJECT/build"

if [ ! -d "$BUILD_DIR" ]; then
    echo "Error: Build output not found at $BUILD_DIR"
    echo "Make sure @sveltejs/adapter-static is configured."
    exit 1
fi

# Clean and copy
rm -rf "$APP_DIR"
cp -r "$BUILD_DIR" "$APP_DIR"

echo ""
echo "Build complete. Files copied to: $APP_DIR"
echo "Contents:"
ls -la "$APP_DIR"
