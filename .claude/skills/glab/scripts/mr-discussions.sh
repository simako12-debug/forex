#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $(basename "$0") <mr_iid> [--unresolved]" >&2
  echo "  mr_iid       Merge request IID" >&2
  echo "  --unresolved  Show only unresolved discussions" >&2
  exit 1
}

if [[ $# -lt 1 || "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
fi

mr_iid="$1"
shift
unresolved_only=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --unresolved) unresolved_only=true; shift ;;
    *) echo "Error: Unknown argument '$1'" >&2; usage ;;
  esac
done

response=$(glab api "projects/:fullpath/merge_requests/${mr_iid}/discussions" --paginate)

filter='
  [.[] | select(.notes | length > 0)
    | select(.notes[0].system != true)
    | {
        discussion_id: .id,
        resolved: (if .notes[0].resolvable then ([.notes[] | select(.resolvable)] | all(.resolved)) else null end),
        resolvable: (.notes[0].resolvable // false),
        file_path: (.notes[0].position.new_path // null),
        new_line: (.notes[0].position.new_line // null),
        old_line: (.notes[0].position.old_line // null),
        notes: [.notes[] | select(.system != true) | {
          id: .id,
          author: .author.username,
          body: .body,
          created_at: .created_at
        }]
      }
    | select(.notes | length > 0)
  ]'

if [[ "$unresolved_only" == "true" ]]; then
  filter="${filter} | [.[] | select(.resolved == false)]"
fi

echo "$response" | jq "$filter"
