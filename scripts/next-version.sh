#!/usr/bin/env bash
# Ermittelt die Version für das nächste Release.
#
#   scripts/next-version.sh <header-version> [letztes-tag]
#
# - Kein Tag vorhanden            → Header-Version
# - Header-Version > letztes Tag  → Header-Version (für Minor-/Major-Sprünge)
# - sonst                         → letztes Tag mit erhöhter Patch-Stelle
set -euo pipefail
HEADER="${1:?Header-Version fehlt}"
LAST="${2:-}"
LAST="${LAST#v}"

if [ -z "$LAST" ]; then
  echo "$HEADER"
  exit 0
fi

HIGHER="$(printf '%s\n%s\n' "$HEADER" "$LAST" | sort -V | tail -n1)"
if [ "$HIGHER" = "$HEADER" ] && [ "$HEADER" != "$LAST" ]; then
  echo "$HEADER"
else
  IFS=. read -r MAJ MIN PAT <<< "$LAST"
  echo "$MAJ.$MIN.$((PAT + 1))"
fi
