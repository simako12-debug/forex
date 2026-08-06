#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $(basename "$0") <mr_iid> <body> [--file <path> --line <N>]" >&2
  echo "  mr_iid      Merge request IID" >&2
  echo "  body        Discussion body (markdown supported)" >&2
  echo "  --file      File path for inline discussion (requires --line)" >&2
  echo "  --line      Line number (new side) for inline discussion (requires --file)" >&2
  echo "" >&2
  echo "Without --file/--line: creates a general (non-positional) discussion thread." >&2
  echo "With --file/--line: creates an inline thread anchored to that file and line" >&2
  echo "on the new side of the MR diff." >&2
  exit 1
}

if [[ $# -lt 2 || "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
fi

mr_iid="$1"
body="$2"
shift 2

file=""
line=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --file) file="$2"; shift 2 ;;
    --line) line="$2"; shift 2 ;;
    -h|--help) usage ;;
    *) echo "Error: Unknown argument '$1'" >&2; usage ;;
  esac
done

# --file and --line must be used together
if [[ -n "$file" && -z "$line" ]] || [[ -z "$file" && -n "$line" ]]; then
  echo "Error: --file and --line must be used together" >&2
  exit 1
fi

# --line must be a positive integer (otherwise jq --argjson errors cryptically)
if [[ -n "$line" ]] && ! [[ "$line" =~ ^[0-9]+$ ]]; then
  echo "Error: --line must be a positive integer (got: $line)" >&2
  exit 1
fi

if [[ -n "$file" && -n "$line" ]]; then
  # Fetch diff_refs (base_sha, head_sha, start_sha) from MR
  diff_refs=$(glab api "projects/:fullpath/merge_requests/${mr_iid}" | jq -r '.diff_refs')
  base_sha=$(echo "$diff_refs" | jq -r '.base_sha')
  head_sha=$(echo "$diff_refs" | jq -r '.head_sha')
  start_sha=$(echo "$diff_refs" | jq -r '.start_sha')

  if [[ -z "$base_sha" || "$base_sha" == "null" ]]; then
    echo "Error: Could not fetch diff_refs for MR !${mr_iid}" >&2
    exit 1
  fi

  # NOTE: must send JSON with Content-Type. `glab api -F "position[...]"` form
  # encoding silently drops the nested `position` object, and the API then
  # creates a general (non-anchored) thread instead of an inline one.
  payload=$(jq -n \
    --arg body "$body" \
    --arg base_sha "$base_sha" \
    --arg head_sha "$head_sha" \
    --arg start_sha "$start_sha" \
    --arg new_path "$file" \
    --argjson new_line "$line" \
    '{
      body: $body,
      position: {
        base_sha: $base_sha,
        head_sha: $head_sha,
        start_sha: $start_sha,
        position_type: "text",
        new_path: $new_path,
        new_line: $new_line,
        old_path: $new_path
      }
    }')
  tmp_payload=$(mktemp)
  trap 'rm -f "$tmp_payload"' EXIT
  printf '%s' "$payload" > "$tmp_payload"
  response=$(glab api "projects/:fullpath/merge_requests/${mr_iid}/discussions" \
    --method POST \
    --header "Content-Type: application/json" \
    --input "$tmp_payload")
else
  response=$(glab api "projects/:fullpath/merge_requests/${mr_iid}/discussions" -F "body=${body}")
fi

# Verify the position actually stuck — GitLab silently drops bad positions and
# returns a general thread. If we asked for inline and got general, fail loud.
if [[ -n "$file" && -n "$line" ]]; then
  got_line=$(echo "$response" | jq -r '.notes[0].position.new_line // "null"')
  if [[ "$got_line" != "$line" ]]; then
    echo "Error: Inline post failed — GitLab returned position=null for ${file}:${line}." >&2
    echo "Likely cause: line is not in the MR diff on the new side, or the file path is wrong." >&2
    echo "Created discussion was general (not anchored); consider deleting it: $(echo "$response" | jq -r '.id')" >&2
    exit 1
  fi
fi

echo "$response" | jq '{
  discussion_id: .id,
  note_id: .notes[0].id,
  body_preview: (.notes[0].body | .[0:80]),
  author: .notes[0].author.username,
  position: (.notes[0].position // null)
}'
