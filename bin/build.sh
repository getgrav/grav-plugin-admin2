#!/usr/bin/env bash
#
# Build the Admin Pro SPA and copy the output into the plugin's app/ directory.
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
SVELTE_PROJECT="${1:-$(dirname "$PLUGIN_DIR")/../../workspace/grav-admin-next}"

if [ ! -d "$SVELTE_PROJECT" ]; then
    echo "Error: SvelteKit project not found at: $SVELTE_PROJECT"
    echo "Usage: $0 /path/to/grav-admin-next"
    exit 1
fi

echo "Building SvelteKit app from: $SVELTE_PROJECT"
echo "Output directory: $APP_DIR"

# Build the SvelteKit app with adapter-static
cd "$SVELTE_PROJECT"
npm run build

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
