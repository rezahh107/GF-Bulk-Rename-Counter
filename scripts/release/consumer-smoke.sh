#!/usr/bin/env bash
# Consumer-side smoke: public release ZIP, not a source checkout or rebuilt package.
set -euo pipefail
zip="${1:?exact downloaded ZIP required}"
version="${2:?expected release version required}"
test -f "$zip"
php -r 'exit((PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 2) ? 0 : 1);'
wp_path="${RUNNER_TEMP:?}/brc-consumer-wordpress"
wp_cli="$RUNNER_TEMP/wp-cli.phar"
mkdir -p "$wp_path"
curl -fsSL --retry 3 -o "$wp_cli" https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
echo "ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  $wp_cli" | sha256sum -c -
php "$wp_cli" core download --path="$wp_path" --version=6.8.3 --force
php "$wp_cli" config create --path="$wp_path" --dbname=wordpress --dbuser=root --dbpass=brc-consumer-ci-only --dbhost=127.0.0.1:3306 --skip-check
php "$wp_cli" core install --path="$wp_path" --url=http://127.0.0.1:8080 --title="BRC Consumer Verification" --admin_user=bootstrap_admin --admin_password=brc-ci-only-2026 --admin_email=bootstrap@example.invalid --skip-email
gf="$RUNNER_TEMP/gf-authentic.zip"
bulk="$RUNNER_TEMP/bulk-authentic.zip"
curl -fsSL --retry 3 -o "$gf" https://raw.githubusercontent.com/rezahh107/Gravity-Presentation-Profiles/fd661f562e52b68542ea12126842709ea8916114/tests/fixtures/wu21-packages/gravityforms-3.1.1.1-owner-supplied-source-package.zip
echo "542f56ae0747f3661d1474996527298027db3fb8ed3e6469a6391aaabf61069b  $gf" | sha256sum -c -
curl -fsSL --retry 3 -o "$bulk" https://downloads.wordpress.org/plugin/bulk-download-for-gravity-forms.4.2.0.zip
echo "f42355c61535c9408c2668081f86e542912752b98ea1f301aa967f91c18592a4  $bulk" | sha256sum -c -
unzip -tq "$gf"
unzip -tq "$bulk"
unzip -q "$gf" -d "$wp_path/wp-content/plugins"
unzip -q "$bulk" -d "$wp_path/wp-content/plugins"
php "$wp_cli" --path="$wp_path" plugin activate gravityforms bulk-download-for-gravity-forms
php "$wp_cli" --path="$wp_path" plugin install "$zip" --activate
test "$(php "$wp_cli" --path="$wp_path" plugin get gf-bulk-rename-counter --field=version)" = "$version"
test "$(php "$wp_cli" --path="$wp_path" plugin get gf-bulk-rename-counter --field=status)" = active
php "$wp_cli" --path="$wp_path" eval 'if (!class_exists("GFAPI") || !defined("BDFGF_VERSION") || !has_filter("bdfgf_entry_filename") || !defined("GFBULKRENAME_VERSION")) { exit(1); } echo "Real dependencies and release plugin hook active\n";'
