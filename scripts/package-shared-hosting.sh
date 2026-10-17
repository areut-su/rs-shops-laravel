#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
app_dir="$repo_root/shops/laravel"
output_dir="${1:-$repo_root/build/storefront-laravel}"
temporary_dir="$(mktemp -d)"
temporary_repo="$temporary_dir/repo"
temporary_app="$temporary_repo/shops/laravel"

cleanup() {
    rm -rf -- "$temporary_dir"
}
trap cleanup EXIT

if [[ -e "$output_dir" ]]; then
    printf 'Output path already exists: %s\n' "$output_dir" >&2
    exit 1
fi
if [[ ! -f "$repo_root/packages/storefront-price-widget/dist/rs-storefront-widget.js" ]]; then
    printf 'Build the shared widget first: npm run build --prefix packages/storefront-price-widget\n' >&2
    exit 1
fi

mkdir -p "$temporary_app" "$temporary_repo/packages/api-client-php" "$output_dir"
tar --exclude=.git --exclude='.env*' --exclude=vendor --exclude=storage --exclude='.phpstan*cache*' --exclude='.phpunit*cache*' --exclude='bootstrap/cache/*.php' \
    -C "$app_dir" -cf - . | tar -C "$temporary_app" -xf -
cp "$app_dir/.env.example" "$temporary_app/.env.example"
tar --exclude=.git --exclude=vendor -C "$repo_root/packages/api-client-php" -cf - . \
    | tar -C "$temporary_repo/packages/api-client-php" -xf -
composer install --working-dir="$temporary_app" --no-dev --no-interaction --prefer-dist --optimize-autoloader
mkdir -p "$temporary_app/public/assets" "$temporary_app/storage/app/private" \
    "$temporary_app/storage/app/public" "$temporary_app/storage/framework/cache/data" \
    "$temporary_app/storage/framework/sessions" "$temporary_app/storage/framework/views" \
    "$temporary_app/storage/logs" "$temporary_app/bootstrap/cache"
cp "$repo_root/packages/storefront-price-widget/dist/rs-storefront-widget.js" "$temporary_app/public/assets/rs-storefront-widget.js"
cp -a "$temporary_app/." "$output_dir/"

printf 'Created standalone shared-hosting artifact at %s\n' "$output_dir"
