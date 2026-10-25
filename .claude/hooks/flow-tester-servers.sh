#!/usr/bin/env bash
# PreToolUse hook for the flow-tester subagent: servers.sh may only be used for status, errors and `cleanup e2e-ft-`.
# The caller owns the servers, and other agents' e2e-* accounts must survive. Exit 2 blocks the call and tells the agent why.
input="$(cat)"
blocked="$(printf '%s' "$input" | /usr/bin/python3 -c '
import json, re, sys
command = json.load(sys.stdin).get("tool_input", {}).get("command") or ""
for match in re.finditer(r"servers\.sh\b([^;&|\n]*)", command):
    args = match.group(1).split()
    if args not in (["status"], ["errors"], ["cleanup", "e2e-ft-"]):
        print("servers.sh " + " ".join(args))
        break
')"

if [ -n "$blocked" ]; then
  echo "flow-tester may only run servers.sh status, errors or cleanup e2e-ft- (blocked: $blocked). Ask the caller to start, stop or clean up anything else." >&2
  exit 2
fi
exit 0
