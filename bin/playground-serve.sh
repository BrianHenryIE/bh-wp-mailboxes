#!/usr/bin/env bash
#
# Build the development plugin (see create-development-plugin-archive.sh) and serve it in
# WordPress Playground locally — the same self-contained layout the CI Playground Preview
# workflow boot-checks and publishes, which differs from wp-env's mapped layout (e.g. the
# library lives nested in the plugin's own vendor directory).
#
# Usage:
#   composer playground-serve
#
# Then browse http://127.0.0.1:9400/wp-admin/ (admin/password; the blueprint logs in and
# activates the plugin). Ctrl+C to stop.
#
# @author BrianHenryIE

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

BUILD_PARENT="${BUILD_PARENT:-$(mktemp -d -t bh-wp-mailboxes-playground)}"
export BUILD_PARENT

"$PROJECT_DIR/bin/create-development-plugin-archive.sh"

# NB: the port answers 502 "WordPress is not ready yet" while Playground boots.
npx @wp-playground/cli@latest server \
  --php=8.4 \
  --wp=latest \
  --port="${PLAYGROUND_PORT:-9400}" \
  --mount="$BUILD_PARENT/development-plugin:/wordpress/wp-content/plugins/development-plugin" \
  --blueprint="$PROJECT_DIR/.github/playground-blueprint.json"
