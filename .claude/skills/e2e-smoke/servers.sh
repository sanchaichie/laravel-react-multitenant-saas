#!/usr/bin/env bash
# Runs the API and the built web app on spare ports for end-to-end checks.
# Never uses :8100/:5173 (the developer's own servers) or saas-starter's :8000/:3000 and :8011/:3011.
#
# Usage: servers.sh start [--mail] | stop | status | cleanup [prefix] | errors
#   start    build web into web/dist-e2e with the API URL baked in (skip with SKIP_BUILD=1), start API :8112 (4 workers,
#            for streaming) and `vite preview` on :5112, wait until both answer.
#            Records where laravel.log ends, so errors and mail.sh only read this run.
#            --mail: send emails straight away (sync queue) to the log, with links to :5112; read them with mail.sh
#   stop     stop both
#   cleanup  delete <prefix>*@example.com users (prefix starts with e2e-, default e2e-), their tokens and organizations only they belong to
#   errors   ERROR and worse entries in laravel.log since start (not from Pest runs), or "none"
#
# Ports are also in mail.sh (WEB_PORT); change both together.
set -uo pipefail

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
state="${TMPDIR:-/tmp}/react-saas-e2e"
log="$root/api/storage/logs/laravel.log"
API_PORT=8112
WEB_PORT=5112
mkdir -p "$state"

find_php() {
  local candidate
  for candidate in php /opt/homebrew/opt/php/bin/php /usr/local/opt/php/bin/php; do
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
      echo "$candidate"; return 0
    fi
  done
  echo "PHP 8.4+ not found" >&2; return 1
}

use_node() {
  export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
  if [ -s "$NVM_DIR/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$NVM_DIR/nvm.sh"
    cd "$root/web" && nvm use --silent >/dev/null
  fi
}

port_busy() { lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; }

wait_for() {
  local url="$1" i
  for i in $(seq 1 60); do
    curl -s -o /dev/null "$url" && return 0
    sleep 0.5
  done
  echo "Timed out waiting for $url" >&2; return 1
}

# Stops whatever php/node process listens on our ports ($! points at wrappers, not the servers).
# The ( ... & ) wrappers get /dev/null as stdio so a caller piping our output (| tail) isn't held open.
stop() {
  local port pid
  rm -f "$state/mail"
  for port in $API_PORT $WEB_PORT; do
    for pid in $(lsof -nP -t -iTCP:"$port" -sTCP:LISTEN 2>/dev/null); do
      case "$(ps -o comm= -p "$pid")" in
        *php*|*node*) kill "$pid" && echo "stopped :$port ($pid)" ;;
        *) echo "left :$port alone: pid $pid is not php/node" >&2 ;;
      esac
    done
  done
}

start() {
  local php mail_env=()
  case "${1:-}" in
    --mail) mail_env=(QUEUE_CONNECTION=sync MAIL_MAILER=log) ;;  # every queued job runs inline, not only mail
    "") ;;
    *) echo "Unknown option: $1 (only --mail)" >&2; exit 1 ;;
  esac
  php="$(find_php)" || exit 1
  for port in $API_PORT $WEB_PORT; do
    if port_busy "$port"; then
      echo "Port $port is in use. Run '$0 stop' or check what is listening (lsof -iTCP:$port)." >&2
      exit 1
    fi
  done

  use_node
  if [ "${SKIP_BUILD:-0}" != 1 ]; then
    echo "building web..."
    # Vite bakes VITE_* into the bundle at build time, so the e2e build gets its own folder (npm run build keeps dist/).
    (cd "$root/web" && VITE_API_BASE="http://localhost:$API_PORT" npx vite build --outDir dist-e2e --emptyOutDir >"$state/build.log" 2>&1) \
      || { echo "web build failed, see $state/build.log" >&2; exit 1; }
  fi

  # mail.sh refuses to run without this marker, so a run started without --mail fails loudly instead of finding no emails.
  if [ ${#mail_env[@]} -gt 0 ]; then touch "$state/mail"; else rm -f "$state/mail"; fi
  if [ -f "$log" ]; then wc -l <"$log" | tr -d ' ' >"$state/log.start"; else echo 0 >"$state/log.start"; fi
  (cd "$root/api/public" && env ${mail_env[@]+"${mail_env[@]}"} FRONTEND_URL="http://localhost:$WEB_PORT" PHP_CLI_SERVER_WORKERS=4 nohup "$php" -S "127.0.0.1:$API_PORT" \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >"$state/api.log" 2>&1 &) </dev/null >/dev/null 2>&1
  (cd "$root/web" && nohup npx vite preview --outDir dist-e2e --host 127.0.0.1 --port "$WEB_PORT" --strictPort \
    >"$state/web.log" 2>&1 &) </dev/null >/dev/null 2>&1

  wait_for "http://127.0.0.1:$API_PORT/up" && wait_for "http://127.0.0.1:$WEB_PORT/login" || { stop; exit 1; }
  echo "API  http://localhost:$API_PORT   (log: $state/api.log)"
  echo "Web  http://localhost:$WEB_PORT   (log: $state/web.log)"
  [ ${#mail_env[@]} -gt 0 ] && echo "Mail sent straight to $log (read with mail.sh)"
  return 0
}

# Prints the log lines written since start. If the log got shorter (truncated or rotated), reads it all and warns.
since_start() {
  local start lines
  start="$(cat "$state/log.start" 2>/dev/null)"
  [[ "$start" =~ ^[0-9]+$ ]] || { echo "No recorded start. Run '$0 start' first." >&2; return 1; }
  [ -f "$log" ] || return 0
  lines="$(wc -l <"$log" | tr -d ' ')"
  if [ "$lines" -lt "$start" ]; then
    echo "laravel.log is shorter than at start (truncated or rotated); reading all of it" >&2
    start=0
  fi
  tail -n +"$((start + 1))" "$log"
}

errors() {
  local out
  out="$(since_start)" || exit 1
  printf '%s\n' "$out" | grep -E '^\[[^]]+\] [a-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | grep -vE '^\[[^]]+\] testing\.' | cut -c1-300 | grep . || echo none
}

cleanup() {
  local php prefix="${1:-e2e-}"
  # Only test accounts: the prefix must start with e2e- and contain nothing a LIKE pattern treats specially.
  [[ "$prefix" =~ ^e2e-[a-z0-9-]*$ ]] || { echo "cleanup prefix must match e2e-[a-z0-9-]*, got: $prefix" >&2; exit 1; }
  php="$(find_php)" || exit 1
  cd "$root/api" && E2E_PREFIX="$prefix" "$php" artisan tinker --execute='
    $prefix = getenv("E2E_PREFIX");
    $users = App\Models\User::where("email", "like", $prefix."%@example.com")->get();
    $ids = $users->pluck("id");
    $orgIds = DB::table("organization_user")->whereIn("user_id", $ids)->pluck("organization_id")->unique();
    $shared = DB::table("organization_user")->whereIn("organization_id", $orgIds)->whereNotIn("user_id", $ids)->pluck("organization_id");
    $users->each(fn ($user) => $user->tokens()->delete());
    App\Models\User::whereIn("id", $ids)->delete();
    $orgs = App\Models\Organization::whereIn("id", $orgIds->diff($shared))->delete();
    echo "deleted {$ids->count()} {$prefix}* users and {$orgs} organizations\n";
  '
}

case "${1:-}" in
  start) start "${2:-}" ;;
  stop) stop ;;
  status) for p in $API_PORT $WEB_PORT; do port_busy "$p" && echo ":$p up" || echo ":$p down"; done ;;
  cleanup) cleanup "${2:-}" ;;
  errors) errors ;;
  *) echo "Usage: $0 start [--mail]|stop|status|cleanup [prefix]|errors" >&2; exit 1 ;;
esac
