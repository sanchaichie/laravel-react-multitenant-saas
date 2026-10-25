#!/usr/bin/env bash
# Reads emails the test API wrote to laravel.log since `servers.sh start --mail`.
# The log is shared with the developer's own server, so only lines after the recorded start are read.
#
# Usage: mail.sh list | link <path> [recipient] | count <recipient>
#   list    one line per email to an e2e-* address: recipient, then subject (or "none")
#   link    the newest link to http://localhost:5112/<path> (verify-email, reset-password, join, ...), optionally only from emails to <recipient>
#   count   how many emails went to <recipient>
set -uo pipefail

WEB_PORT=5112  # as in servers.sh

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
state="${TMPDIR:-/tmp}/react-saas-e2e"
log="$root/api/storage/logs/laravel.log"

# servers.sh writes the marker only for start --mail. Without it, emails go to the queue (or nowhere) and links point elsewhere.
[ -f "$state/mail" ] || { echo "The servers weren't started with --mail. Ask the caller to restart them with servers.sh start --mail." >&2; exit 1; }
[ -f "$log" ] || { echo "No laravel.log yet." >&2; exit 1; }
start="$(cat "$state/log.start" 2>/dev/null)"
[[ "$start" =~ ^[0-9]+$ ]] || { echo "Bad or missing $state/log.start. Restart with servers.sh start --mail." >&2; exit 1; }
if [ "$(wc -l <"$log" | tr -d ' ')" -lt "$start" ]; then
  echo "laravel.log is shorter than at start (truncated or rotated); reading all of it" >&2
  start=0
fi

# One tab-separated record per email: recipient, subject, links to the web app (space-separated, in order).
# A log entry starts with "[date] env.LEVEL: "; the mail log transport writes "From:" as the first line of its entry.
emails() {
  # Email headers and bodies end lines with CRLF.
  tail -n +"$((start + 1))" "$log" | tr -d '\r' | awk -v web="http://localhost:$WEB_PORT/" '
    function flush() { if (in_mail) printf "%s\t%s\t%s\n", to, subject, links; in_mail = 0 }
    /^\[[^]]+\] [a-z]+\.[A-Z]+: / {
      flush()
      if ($0 ~ /: From: /) { in_mail = 1; to = ""; subject = ""; links = "" }
      next
    }
    !in_mail { next }
    to == "" && /^To: / { to = $0; sub(/^To: /, "", to); if (match(to, /<[^>]+>/)) to = substr(to, RSTART + 1, RLENGTH - 2); next }
    subject == "" && /^Subject: / { subject = substr($0, 10); next }
    {
      line = $0
      gsub(/&amp;/, "\\&", line)
      while ((i = index(line, web)) > 0) {
        line = substr(line, i)
        match(line, /^[^] )"<>]+/)
        links = links (links == "" ? "" : " ") substr(line, 1, RLENGTH)
        line = substr(line, RLENGTH + 1)
      }
    }
    END { flush() }'
}

case "${1:-}" in
  list)
    # Only test accounts: the developer's own server may log emails in the same window.
    emails | awk -F'\t' '$1 ~ /^e2e-/ { printf "%s  %s\n", $1, $2; n++ } END { if (!n) print "none" }'
    ;;
  link)
    [ -n "${2:-}" ] || { echo "Usage: $0 link <path> [recipient]" >&2; exit 1; }
    path="${2#/}"
    # The path is matched literally, followed by the end of the link or ?, # or /.
    found="$(emails | awk -F'\t' -v to="${3:-}" 'to == "" || $1 == to { print $3 }' | tr ' ' '\n' \
      | awk -v prefix="http://localhost:$WEB_PORT/$path" 'index($0, prefix) == 1 && (length($0) == length(prefix) || index("?#/", substr($0, length(prefix) + 1, 1)))' \
      | tail -1)"
    [ -n "$found" ] || { echo "No email${3:+ to $3} with a link to /$path since start." >&2; exit 1; }
    echo "$found"
    ;;
  count)
    [ -n "${2:-}" ] || { echo "Usage: $0 count <recipient>" >&2; exit 1; }
    emails | awk -F'\t' -v to="$2" '$1 == to { n++ } END { print n + 0 }'
    ;;
  *) echo "Usage: $0 list | link <path> [recipient] | count <recipient>" >&2; exit 1 ;;
esac
