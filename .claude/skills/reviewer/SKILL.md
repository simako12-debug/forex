---
name: reviewer
description: Adversarial code review against approved architecture or against a GitLab MR. Runs in isolated context for unbiased analysis.
disable-model-invocation: true
argument-hint: "<SHAR-XXXXX | !IID | URL> [optional context]"
context: fork
agent: reviewer
---

## Argument parsing

`$ARGUMENTS` starts with a single **identifier token** (first whitespace-separated word). Everything after is **free-form context** that steers the review (e.g. "zaměř se na testy", "jsou to jen poslední 3 commity") — it is NOT part of any filesystem path.

**Accepted identifier formats:**
- `^SHAR-\d+$` — ticket mode
- `^![0-9]+$` — MR mode (GitLab MR IID, e.g. `!42`)
- `^#[0-9]+$` — MR mode (alternative syntax, e.g. `#42`)
- GitLab MR URL — `https?://[^\s]+/-/merge_requests/\d+(/.*)?` (extract IID from the trailing number)

**If the identifier doesn't match an accepted format**, do NOT create any directory under `.ai/`. Report the accepted formats and stop.

## Mode detection

- Identifier matches `^SHAR-\d+$` → **ticket mode** (see below)
- Identifier matches `^![0-9]+$`, `^#[0-9]+$`, or MR URL → **MR mode** (see below)

## Ticket mode

Identifier = `<TICKET>` matching `^SHAR-\d+$`.

- Task directory: `.ai/task/<TICKET>/`
- Output file: `.ai/task/<TICKET>/review.md`
- Log format: see [template.md](template.md)
- Evaluation checklists: see [reference.md](reference.md)
- Requires `.ai/task/<TICKET>/architecture.md` (produced by /architect) and `.ai/task/<TICKET>/developer.md` (produced by /developer). Missing files → fail with explicit error.

## MR mode

Identifier = `<IID>` (extracted from `!42`, `#42`, or URL).

- Review directory: `.ai/mr-review/<IID>/`
- Output file: `.ai/mr-review/<IID>/review.md`
- Log format: see [template-mr.md](template-mr.md)
- Evaluation checklists: see [reference.md](reference.md) (same as ticket mode)

### External MR review flow

Follow these steps in order. Every step writes to the output file (append-only log). If any step fails, stop and report — do not silently continue.

1. **Fetch MR metadata** via `mr-get.sh <source_branch>` (if branch known) or by calling `glab api "projects/:fullpath/merge_requests/<IID>"`. Record: title, description, `source_branch`, `target_branch`, `diff_refs`.

2. **Extract linked JIRA ticket**: search title → description → source_branch name for the first `SHAR-\d+` match. If found, fetch the Jira issue via `mcp__atlassian__getAccessibleAtlassianResources` + `mcp__atlassian__getJiraIssue` and record its title, description, and acceptance criteria. If nothing matches, note "no linked ticket" in the log — review proceeds using only the MR description as spec.

3. **Interference check — warn and wait**: run the three checks below. If ANY returns non-empty, stop and tell the user:
   ```bash
   git status --porcelain
   git stash list
   git rev-list @{u}..HEAD --count   # unpushed commits on current branch
   ```
   Message: "Your workspace has uncommitted / stashed / unpushed changes. I'll wait — please move them aside (stash / commit / switch branch) so I can check out MR !<IID>, then say 'pokračuj'."
   Do NOT auto-stash and do NOT proceed without explicit user continue.

4. **Checkout the MR branch**:
   ```bash
   git fetch origin <source_branch>
   git checkout FETCH_HEAD
   ```
   Remember the previous branch for later restoration.
   If checkout fails (conflicting gitignored files, protected paths, etc.), offer **diff-only fallback**:
   > "Checkout failed: `<error>`. I can do a diff-only review (no ability to run tests locally). OK?"
   On confirm, work from `git fetch origin <source_branch> && git diff origin/<target_branch>...origin/<source_branch>` instead.

5. **Run the full review pipeline** (identical rigor to ticket mode): Plan compliance (against Jira AC + MR description), Correctness, Regressions, Error handling, Testing, Security review, Simplify (reuse / efficiency / quality), Evidence verification, Issue validation. Apply project rules (`rules/backend/*`, `rules/terminology.md`) and the evaluation checklists from [reference.md](reference.md).

6. **Draft the review** in `.ai/mr-review/<IID>/review.md` using the structure from [template-mr.md](template-mr.md). Each CONFIRMED issue becomes a **finding** with: `severity` (blocker / major / minor / nit), `location` (`path:line` when applicable), `issue` description, `suggested_action`.

7. **Present findings to the user in chat** as a numbered list:
   ```
   Finding 1 [severity: major]
   Location: app/Services/Auth.php:128
   Issue: Missing null check — if $user is null, the next call fails with TypeError.
   Suggested action: Add `if ($user === null) { return false; }` before line 129.
   ```
   After presenting, explicitly ask: "Which findings do you want posted to GitLab? Examples: `1,3,5`, `all blockers`, `skip 2`, `finding 4 rewrite as: <text>`."

8. **Wait for user posting decisions**. Apply them to the findings list. Log each decision (posted / skipped / modified, with reason) in the `.ai/mr-review/<IID>/review.md` "Posting decisions" section.

9. **Post approved findings** via `mr-discussion-create.sh`:
   - Findings with a concrete `path:line` → inline post: `mr-discussion-create.sh <IID> "<body>" --file <path> --line <line>`
   - General findings (no line anchor) → thread without position: `mr-discussion-create.sh <IID> "<body>"`
   - Record the returned `discussion_id` and `note_id` for each posted finding.

10. **Restore workspace**: `git checkout <previous_branch>`. (No stash was created in step 3, so nothing to pop.) Report completion with a summary: total findings, posted count, skipped count, modified count, and links to the posted discussions.

## Examples

- `/reviewer SHAR-14825`
- `/reviewer SHAR-14825 zaměř se na testy`
- `/reviewer !42`
- `/reviewer !42 jsou to jen poslední 3 commity, diskuze už jsou vyřešené`
- `/reviewer https://gitlab.com/group/project/-/merge_requests/42`

## Pipeline

Apply free-form context from the argument as a steering hint throughout all steps, but NEVER as a substitute for required review steps (no skipping security review, evidence verification, etc.).
