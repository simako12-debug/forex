---
name: glab
description: GitLab MR workflow – creating, viewing, and managing merge requests and discussions

---

# GitLab MR Workflow

This skill provides tools for working with GitLab merge requests and their discussion threads via CLI scripts.

## Available Scripts

All scripts live in the skill's `scripts/` directory. Use `${CLAUDE_SKILL_DIR}` to reference them — Claude substitutes this with the skill's install path, so the commands work regardless of the current working directory. They output JSON and use exit code 0 for success, 1 for errors.

### 1. Get MR info — `mr-get.sh [branch]`

Finds the open MR for a branch (defaults to current branch).

```bash
bash ${CLAUDE_SKILL_DIR}/scripts/mr-get.sh              # current branch
bash ${CLAUDE_SKILL_DIR}/scripts/mr-get.sh feature/foo  # specific branch
```

Returns: `{ iid, title, description, state, web_url, author, reviewers, user_notes_count }`

### 2. Create MR — `mr-create.sh [-t title] [-d description] [-b target_branch] [--draft]`

Creates a new merge request from the current branch.

```bash
bash ${CLAUDE_SKILL_DIR}/scripts/mr-create.sh                                    # title from last commit, target: main
bash ${CLAUDE_SKILL_DIR}/scripts/mr-create.sh -t "Fix auth bug" -b develop
bash ${CLAUDE_SKILL_DIR}/scripts/mr-create.sh -t "WIP: new feature" --draft      # draft MR
```

Returns: `{ iid, web_url, title }`

### 3. List discussions — `mr-discussions.sh <mr_iid> [--unresolved]`

Lists discussion threads on an MR, filtering out system notes.

```bash
bash ${CLAUDE_SKILL_DIR}/scripts/mr-discussions.sh 42              # all discussions
bash ${CLAUDE_SKILL_DIR}/scripts/mr-discussions.sh 42 --unresolved # only unresolved
```

Returns: Array of `{ discussion_id, resolved, resolvable, file_path, new_line, old_line, notes[] }`. For inline threads `new_line`/`old_line` reflect the position on each side of the diff (one may be `null` when the comment anchors only to added or removed code); both are `null` for general (non-inline) threads.

### 4. Reply to discussion — `mr-reply.sh <mr_iid> <discussion_id> <message>`

Posts a reply to a specific discussion thread.

```bash
bash ${CLAUDE_SKILL_DIR}/scripts/mr-reply.sh 42 "abc123def" "Fixed in latest commit"
```

Returns: `{ id, body, author, created_at }`

### 5. Resolve/unresolve discussion — `mr-resolve.sh <mr_iid> <discussion_id> [--unresolve]`

Resolves or unresolves a discussion thread.

```bash
bash ${CLAUDE_SKILL_DIR}/scripts/mr-resolve.sh 42 "abc123def"              # resolve
bash ${CLAUDE_SKILL_DIR}/scripts/mr-resolve.sh 42 "abc123def" --unresolve   # unresolve
```

Returns: `{ id, resolved, resolvable, notes_count }`

### 6. Create discussion — `mr-discussion-create.sh <mr_iid> <body> [--file <path> --line <N>]`

Creates a new discussion thread on an MR. Without `--file`/`--line` the thread is general (not anchored to any line). With both flags the thread is inline, anchored to `<path>:<line>` on the new side of the MR diff. Useful for external review workflows that post per-finding comments.

```bash
bash ${CLAUDE_SKILL_DIR}/scripts/mr-discussion-create.sh 42 "General concern about error handling"
bash ${CLAUDE_SKILL_DIR}/scripts/mr-discussion-create.sh 42 "Null check missing here" --file app/Services/Auth.php --line 128
```

When inline, the script fetches the MR's `diff_refs` (`base_sha`, `head_sha`, `start_sha`) automatically and constructs the `position` payload. The payload is sent as JSON (not form-encoded) because `glab api -F "position[...]"` silently drops nested objects and the API would then create a general thread instead of an inline one.

The script verifies that the returned note has `position.new_line` matching the requested line; if not (line outside the diff, wrong file path, etc.), it exits with code 1 and a descriptive error rather than silently succeeding while the thread is general. The orphan general thread that GitLab creates in this case is reported with its discussion ID — the caller is expected to delete it manually.

Returns: `{ discussion_id, note_id, body_preview, author, position }` — `position` is the full position object for inline threads or `null` for general ones.

## Typical Workflows

### Respond to MR review comments

1. Get the MR: `mr-get.sh` → note the `iid`
2. List unresolved discussions: `mr-discussions.sh <iid> --unresolved`
3. For each discussion, read the reviewer's comment and decide:
   - If code change needed → make the fix, then reply explaining what was done
   - If no change needed → reply with explanation
4. Reply: `mr-reply.sh <iid> <discussion_id> "<message>"`
5. Resolve: `mr-resolve.sh <iid> <discussion_id>`

### Create a new MR

1. Ensure current branch has been pushed: `git push -u origin HEAD`
2. Create MR: `mr-create.sh -t "Feature: Add user export" -d "Implements CSV export for user data"`
3. Share the `web_url` from the response with the user

### Post review findings on someone else's MR

1. Get the MR: `mr-get.sh <branch>` or use a known iid
2. For each reviewer finding, decide whether it's anchored to a specific line:
   - **Inline** (has `path:line`): `mr-discussion-create.sh <iid> "<finding body>" --file <path> --line <N>`
   - **General** (overall concern): `mr-discussion-create.sh <iid> "<finding body>"`
3. After posting all findings, share the MR `web_url` with the user and list the `discussion_id`s created.

## Instructions for Claude

- Always get the MR iid first using `mr-get.sh` before other operations
- Present discussions to the user in a readable format: show file path, author, and comment body
- When replying to discussions, be specific about what was changed (reference commits or line numbers)
- After replying and resolving, confirm the action to the user
- If a script fails, show the error message to the user and suggest next steps
- Quote the `message` argument in `mr-reply.sh` carefully — escape special characters for bash
