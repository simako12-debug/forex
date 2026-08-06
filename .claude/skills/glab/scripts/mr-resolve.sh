#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $(basename "$0") <mr_iid> <discussion_id> [--unresolve]" >&2
  echo "  mr_iid         Merge request IID" >&2
  echo "  discussion_id  Discussion thread ID" >&2
  echo "  --unresolve    Unresolve instead of resolve" >&2
  exit 1
}

if [[ $# -lt 2 || "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
fi

mr_iid="$1"
discussion_id="$2"
shift 2
resolved="true"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --unresolve) resolved="false"; shift ;;
    *) echo "Error: Unknown argument '$1'" >&2; usage ;;
  esac
done

response=$(glab api "projects/:fullpath/merge_requests/${mr_iid}/discussions/${discussion_id}" -X PUT -F "resolved=${resolved}")

echo "$response" | jq '{
  id: .id,
  resolved: (.notes[0].resolved // null),
  resolvable: (.notes[0].resolvable // false),
  notes_count: (.notes | length)
}'
