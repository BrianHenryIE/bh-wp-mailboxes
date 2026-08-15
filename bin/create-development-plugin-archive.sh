#!/usr/bin/env bash
#
# Builds the development plugin as a self-contained, installable plugin zip.
#
# The project is a library, so the installable artifact is its development plugin. The plugin
# directory is copied outside the project along with the project composer.json, and a Composer
# `path` repository pointing back at the project is added, so `composer install` places the
# library and its dependencies inside the plugin's own vendor directory (see the matching
# autoloader branch in development-plugin.php).
#
# The copied composer.json is the library's own, so the root package is renamed, and
# require/require-dev are replaced with only what the development plugin needs at runtime
# (`bh-wp-logger` also satisfies the library's `psr/log-implementation` requirement via
# monolog). The project's autoload, autoload-dev and post-autoload-dump scripts reference
# paths that do not exist in the build directory, so they are removed. The version is pinned
# via the repository `versions` option because a CI checkout is a detached HEAD.
#
# Usage:
#   composer create-development-plugin-archive
#
#   BUILD_PARENT   Directory to build in; defaults to a new temporary directory.
#                  The plugin is built in $BUILD_PARENT/development-plugin and zipped to
#                  $BUILD_PARENT/development-plugin.zip.
#
# Requires jq and zip.
#
# @author BrianHenryIE

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

BUILD_PARENT="${BUILD_PARENT:-$(mktemp -d -t bh-wp-mailboxes-development-plugin)}"
BUILD_DIR="${BUILD_PARENT}/development-plugin"

echo "Building the development plugin in ${BUILD_DIR}"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
cp -R "$PROJECT_DIR/development-plugin/." "$BUILD_DIR/"
cp "$PROJECT_DIR/composer.json" "$BUILD_DIR/"

jq --arg url "$PROJECT_DIR" '
  .name = "brianhenryie/bh-wp-mailboxes-development-plugin"
  | .repositories = {
      "brianhenryie/bh-wp-mailboxes": {
        "type": "path",
        "url": $url,
        "options": {
          "symlink": false,
          "versions": { "brianhenryie/bh-wp-mailboxes": "dev-master" }
        }
      }
    }
  | .require = {
      "php": .require.php,
      "brianhenryie/bh-wp-mailboxes": "dev-master",
      "alleyinteractive/wordpress-autoloader": .["require-dev"]["alleyinteractive/wordpress-autoloader"],
      "brianhenryie/bh-wp-logger": .["require-dev"]["brianhenryie/bh-wp-logger"],
      "google/apiclient": .["require-dev"]["google/apiclient"],
      "vlucas/phpdotenv": .["require-dev"]["vlucas/phpdotenv"]
    }
  | .scripts = { "pre-autoload-dump": [ "Google\\Task\\Composer::cleanup" ] }
  | del(.["require-dev"], .autoload, .["autoload-dev"], .suggest)
' "$BUILD_DIR/composer.json" > "$BUILD_DIR/composer.tmp.json"
mv "$BUILD_DIR/composer.tmp.json" "$BUILD_DIR/composer.json"

composer install --working-dir="$BUILD_DIR" --no-dev --no-interaction

# The top-level `development-plugin/` directory in the zip becomes the plugin's directory name
# in wp-content/plugins, which the plugin's logger basename fallback and Mappings rely on.
rm -f "$BUILD_PARENT/development-plugin.zip"
(cd "$BUILD_PARENT" && zip -qr development-plugin.zip development-plugin -x "*.DS_Store")

echo "Built ${BUILD_PARENT}/development-plugin.zip"
