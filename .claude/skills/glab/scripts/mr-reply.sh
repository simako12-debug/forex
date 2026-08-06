#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $(basename "$0") <mr_iid> <discussion_id> <message>" >&2
  echo "  mr_iid         Merge request IID" >&2
  echo "  discussion_id  Discussion thread ID" >&2
  echo "  message        Reply message body" >&2
  exit 1
}

if [[ $# -lt 3 || "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
fi

mr_iid="$1"
discussion_id="$2"
message="$3"

response=$(glab api "projects/:fullpath/merge_requests/${mr_iid}/discussions/${discussion_id}/notes" -F "body=${message}")

echo "$response" | jq '{
  id: .id,
  body: .body,
  author: .author.username,
  created_at: .created_at
}'
