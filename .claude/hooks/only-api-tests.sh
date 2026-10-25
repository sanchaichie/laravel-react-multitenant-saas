#!/usr/bin/env bash
# PreToolUse hook for the laravel-test-writer subagent: allow file edits only under api/tests/.
# Exit 2 blocks the tool call and shows the message to the agent.
input="$(cat)"
path="$(printf '%s' "$input" | /usr/bin/python3 -c 'import json,sys; t=json.load(sys.stdin).get("tool_input",{}); print(t.get("file_path") or t.get("notebook_path") or "")')"
root="${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel)}"

case "$path" in
  /*) abs="$path" ;;
  *) abs="$root/$path" ;;
esac

# Resolve .. segments without requiring the file to exist.
abs="$(/usr/bin/python3 -c 'import os,sys; print(os.path.normpath(sys.argv[1]))' "$abs")"

case "$abs" in
  "$root"/api/tests/*) exit 0 ;;
  *)
    echo "laravel-test-writer may only edit files under api/tests/ (blocked: $path). Report app-code problems to the caller instead." >&2
    exit 2
    ;;
esac
