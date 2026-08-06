#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $(basename "$0") [-t title] [-d description] [-b target_branch] [--draft]" >&2
  echo "  -t  MR title (default: last commit message)" >&2
  echo "  -d  MR description" >&2
  echo "  -b  Target branch (default: main)" >&2
  echo "  --draft  Create as draft MR" >&2
  exit 1
}

title=""
description=""
target_branch="main"
draft=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    -t) title="$2"; shift 2 ;;
    -d) description="$2"; shift 2 ;;
    -b) target_branch="$2"; shift 2 ;;
    --draft) draft=true; shift ;;
    -h|--help) usage ;;
    *) echo "Error: Unknown argument '$1'" >&2; usage ;;
  esac
done

if [[ -z "$title" ]]; then
  title=$(git log -1 --format='%s')
fi

args=(--target-branch "$target_branch" --title "$title" --no-editor)

if [[ -n "$description" ]]; then
  args+=(--description "$description")
fi

if [[ "$draft" == "true" ]]; then
  args+=(--draft)
fi

output=$(glab mr create "${args[@]}" 2>&1)

# Extract MR URL from glab output
mr_url=$(echo "$output" | grep -oP 'https://\S+merge_requests/\d+' | head -1)

if [[ -z "$mr_url" ]]; then
  echo "Error: Failed to create MR" >&2
  echo "$output" >&2
  exit 1
fi

mr_iid=$(echo "$mr_url" | grep -oP '\d+$')

glab api "projects/:fullpath/merge_requests/${mr_iid}" | jq '{
  iid,
  web_url,
  title
}'
