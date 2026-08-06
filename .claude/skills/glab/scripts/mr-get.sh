#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $(basename "$0") [branch]" >&2
  echo "  branch  Source branch name (default: current git branch)" >&2
  exit 1
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
fi

branch="${1:-$(git branch --show-current)}"

if [[ -z "$branch" ]]; then
  echo "Error: Could not determine branch name" >&2
  exit 1
fi

response=$(glab api "projects/:fullpath/merge_requests?source_branch=${branch}&state=opened")

count=$(echo "$response" | jq 'length')

if [[ "$count" -eq 0 ]]; then
  echo "Error: No open MR found for branch '${branch}'" >&2
  exit 1
fi

echo "$response" | jq '.[0] | {
  iid,
  title,
  description,
  state,
  web_url,
  author: .author.username,
  reviewers: [.reviewers[]?.username],
  user_notes_count
}'
