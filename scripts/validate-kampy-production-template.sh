#!/bin/sh
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
spec="$repo_root/.do/kampy-production.template.yaml"

ruby -e 'require "yaml"; YAML.safe_load_file(ARGV.fetch(0), aliases: true)' "$spec" >/dev/null

grep -q 'repo: youlikemodernart/kampy-hi-events' "$spec"
grep -q 'branch: production/kampy-hi-events-2026-08-20' "$spec"
grep -q 'domain: tickets.kamplove.org' "$spec"
grep -q 'instance_size_slug: apps-s-1vcpu-2gb' "$spec"
grep -q 'engine: PG' "$spec"
grep -q 'engine: VALKEY' "$spec"
grep -q 'value: "3"' "$spec"
grep -q 'value: webhooks' "$spec"
grep -q 'value: stderr' "$spec"
grep -q 'value: "false"' "$spec"
grep -q 'window.hievents = ' "$repo_root/frontend/server.js"
grep -q 'clientEnv\[key\] || clientBuildEnv\[key\]' "$repo_root/frontend/src/utilites/config.ts"
grep -q 'queue:work --queue=default,webhooks' "$repo_root/docker/all-in-one/supervisor/supervisord.conf"
grep -q 'artisan schedule:run' "$repo_root/docker/all-in-one/supervisor/supervisord.conf"

placeholder_count=$(grep -c 'REPLACE_' "$spec")
if [ "$placeholder_count" -lt 9 ]; then
    echo "Expected deploy-time placeholders are missing." >&2
    exit 1
fi

if grep -Eq 'HiEventsDev/Hi.Events|demo\.hi\.events|app\.hi\.events|webhook-queue' "$spec"; then
    echo "Upstream or stale runtime settings found in Kampy production template." >&2
    exit 1
fi

echo "Kampy production template structure is valid; deploy-time placeholders remain: $placeholder_count"
