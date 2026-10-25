#!/usr/bin/env bash
# Runs every check a task needs before it's called done.
# Usage: verify.sh [api|web|all]   (default: all)
set -uo pipefail

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
target="${1:-all}"
failed=()

logs="$(mktemp -d "${TMPDIR:-/tmp}/verify.XXXXXX")"

# Quiet on success (prints the last output line, e.g. the test count), full tail on failure.
step() {
  local name="$1"; shift
  local log="$logs/$name.log"
  if "$@" >"$log" 2>&1; then
    echo "ok      $name  $(grep -v '^[[:space:]]*$' "$log" | tail -1 | cut -c1-140)"
  else
    echo "FAILED  $name (last 40 lines; full log: $log)"
    tail -40 "$log" | sed 's/^/        /'
    failed+=("$name")
  fi
}

# PHP 8.4+ (Pest 5). Some shells still resolve `php` to an older Homebrew version.
find_php() {
  local candidate
  for candidate in php /opt/homebrew/opt/php/bin/php /usr/local/opt/php/bin/php; do
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
      echo "$candidate"; return 0
    fi
  done
  return 1
}

# Node from web/.nvmrc via nvm, when available.
use_node() {
  export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
  if [ -s "$NVM_DIR/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$NVM_DIR/nvm.sh"
    cd "$root/web" && nvm use --silent >/dev/null
  fi
  echo "node    $(node --version)"
}

# A side that doesn't exist yet (api/ before plan 02, web/ before plan 03) is skipped, not failed.
if [ "$target" = api ] || [ "$target" = all ]; then
  if [ ! -f "$root/api/artisan" ]; then
    echo "skip    api (no api/ yet)"
  elif PHP="$(find_php)"; then
    echo "php     $("$PHP" -r 'echo PHP_VERSION;')"
    cd "$root/api"
    step "pint" "$PHP" vendor/bin/pint --test app routes tests database config bootstrap/app.php bootstrap/providers.php
    step "pest" "$PHP" artisan test
  else
    echo "PHP 8.4+ not found"; failed+=("php 8.4")
  fi
fi

if [ "$target" = web ] || [ "$target" = all ]; then
  if [ ! -f "$root/web/package.json" ]; then
    echo "skip    web (no web/ yet)"
  else
    use_node
    cd "$root/web"
    step "lint" npm run lint --silent
    step "typecheck" npm run typecheck --silent
    if npm pkg get scripts.test | grep -q '"'; then
      step "test" npm test --silent
    fi
    step "build" npm run build --silent
  fi
fi

echo
if [ ${#failed[@]} -eq 0 ]; then
  echo "ALL PASSED ($target)"
else
  echo "FAILED: ${failed[*]}"
  exit 1
fi
