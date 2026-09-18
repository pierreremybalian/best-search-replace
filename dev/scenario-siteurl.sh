#!/usr/bin/env bash
# Exercise the deferred siteurl/home path: change the rig's own address via the CLI,
# verify the options were written last, then undo.
#
#   cd dev && ./scenario-siteurl.sh
set -euo pipefail
cd "$(dirname "$0")"
if docker compose version >/dev/null 2>&1; then COMPOSE="docker compose"; else COMPOSE="docker-compose"; fi
wp() { $COMPOSE run --rm -T wpcli "$@" 2>&1 | grep -a -v -E "Container|Creating|Created|Running|Waiting|Healthy"; }

OLD="http://besr.localhost:8081"
NEW="http://besr2.localhost:8081"

echo "» home before: $(wp option get home)"
echo "» Applying $OLD -> $NEW"
wp besr apply "$OLD" "$NEW" --tables=all --yes --porcelain | tee /tmp/besr-run-id.txt
RUN="$(tail -n1 /tmp/besr-run-id.txt | tr -d '[:space:]')"
echo "» run: $RUN"
echo "» home after:  $(wp option get home)"
wp option get home | grep -q "besr2.localhost" || { echo "FAIL: home was not updated"; exit 1; }
echo "» Undoing"
wp besr undo "$RUN" --yes
echo "» home restored: $(wp option get home)"
wp option get home | grep -q "^$OLD" || { echo "FAIL: home was not restored"; exit 1; }
echo "PASS siteurl scenario"
