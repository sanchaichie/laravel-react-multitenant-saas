#!/usr/bin/env bash
# PreToolUse hook (all Bash calls, subagents included): never run commands that wipe or
# drop database data. `migrate:fresh --env=testing` once wiped the dev database, because
# without api/.env.testing it uses .env. Pest rebuilds its own test database
# (RefreshDatabase); ask the user to run anything like this themselves.
# Exit 2 blocks the tool call and shows the message to the agent.
input="$(cat)"
command="$(printf '%s' "$input" | /usr/bin/python3 -c 'import json,sys; print(json.load(sys.stdin).get("tool_input",{}).get("command",""))')"

pattern='migrate:(fresh|refresh|reset|rollback)|db:wipe|DB_ALLOW_DESTRUCTIVE_COMMANDS|dropdb|drop[[:space:]]+(database|schema|table)|truncate[[:space:]]'

if printf '%s' "$command" | grep -Eiq "$pattern"; then
  echo "Blocked: this command can wipe or drop database data (dev and test share the same Postgres). Tests rebuild their own database; for anything else, ask the user to run it themselves." >&2
  exit 2
fi
exit 0
