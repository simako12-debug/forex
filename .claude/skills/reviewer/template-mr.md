# Review: MR !IID

<!-- INSTRUCTIONS: Copy this entire template to .ai/mr-review/IID/review.md at the start. Replace IID with actual MR iid. Work top-to-bottom. Replace [placeholders] with findings. Do NOT remove any section. -->

## Review log (v1)

### MR metadata
<!-- From mr-get.sh or glab api. -->
- IID: [number]
- Title: [MR title]
- Source branch: [source_branch]
- Target branch: [target_branch]
- Author: [username]
- MR URL: [web_url]

### Linked JIRA ticket
<!-- First SHAR-\d+ match from title → description → source_branch. If none, write "no linked ticket" and proceed using MR description only. -->
- Ticket: [SHAR-XXXXX or "no linked ticket"]
- Ticket title: [text]
- Acceptance criteria: [bulleted summary from Jira]

### MR description summary
<!-- Condense the MR description to its intended outcomes. -->
- Purpose: [what the MR claims to do]
- Notable decisions: [anything author flagged]
- Out-of-scope note from author: [if any]

### Rules loaded
<!-- Read all files in .claude/rules/. List each one. -->
- [filename] — [brief description]

### Pre-check
<!-- Check: Is this a draft MR? Trivial change? Already reviewed? If any → skip review with note. -->
- Draft: [Yes/No]
- Trivial: [Yes/No — reason]
- Previously reviewed: [Yes/No — if yes, only changes since vN]

### Interference check
<!-- Log the three checks from SKILL.md step 3 and whether user confirmed continuation. -->
- `git status --porcelain`: [empty / changes listed]
- `git stash list`: [empty / N stashes]
- Unpushed commits on current branch: [0 / N]
- User confirmation to proceed: [received at TIMESTAMP]

### Checkout status
<!-- Record whether full checkout succeeded or diff-only fallback was used. -->
- Mode: [full checkout FETCH_HEAD / diff-only fallback]
- Previous branch (for restore): [branch name]
- If fallback: reason `<error message>` and user confirmation

### Diff scope
<!-- git diff target...source (or origin/target...origin/source in diff-only mode). -->
- Scope: [N files, M insertions, K deletions]
- Touched areas: [brief grouping]

### Plan compliance
<!-- Compare implementation against Jira AC + MR description. Log every checkpoint. -->
- Spec (from Jira + MR description): [key requirements]
- Implementation matches: [Yes/No + deviations]
- Scope creep: [None / describe]

### Quality gate
<!-- Read CLAUDE.md of the target repo for required tools. Check commits / CI evidence each was run AFTER last change. Do NOT run them yourself unless full checkout succeeded and user permits. Missing = challenge. -->
- [tool]: [Evidence dated TIMESTAMP — PASS/FAIL] OR [NO EVIDENCE → challenge]

### /security-review
<!-- Run /security-review on branch changes. Log full result. Findings → challenges. -->
- Result: [clean / N findings]
- Action: [escalated / no action]

### /simplify — code reuse
<!-- Run: /simplify focus on code reuse. Log findings. Do NOT apply fixes. -->
- Findings: [N]
- Per finding: [description] — [Escalated / Dropped (reason)]

### /simplify — efficiency
<!-- Run: /simplify focus on efficiency. Log findings. Do NOT apply fixes. -->
- Findings: [N]
- Per finding: [description] — [Escalated / Dropped (reason)]

### /simplify — quality
<!-- Run: /simplify focus on code quality. Log findings. Do NOT apply fixes. -->
- Findings: [N]
- Per finding: [description] — [Escalated / Dropped (reason)]

### Evidence verification
<!-- For every claim in MR description / commit messages, verify evidence (file:line, test, output). -->
- Claim: "[text]" — [checked FILE:LINE] → [OK / challenge]

### Issue validation
<!-- Validate each issue. CONFIRMED = flag, DROPPED = skip. Only flag definite problems. -->
- Issue: [description] → [CONFIRMED / DROPPED (reason)]

---

## Findings (v1)

<!-- Only CONFIRMED issues, shaped for GitLab posting. If none, write "No findings." -->

### Finding 1
- **Severity:** [blocker / major / minor / nit]
- **Location:** [path:line] OR "general (no line anchor)"
- **Issue:** [what is wrong and why it matters]
- **Suggested action:** [concrete fix or question]
- **Post as:** [inline / general]

### Finding 2
- **Severity:** [...]
- **Location:** [...]
- **Issue:** [...]
- **Suggested action:** [...]
- **Post as:** [...]

---

## Posting decisions (v1)

<!-- Populated after user validates findings in chat. One line per finding. -->

| # | Decision | Reason / modification |
|---|----------|------------------------|
| 1 | [posted / skipped / modified] | [if modified: revised text; if skipped: reason] |
| 2 | [...] | [...] |

---

## Posted discussions (v1)

<!-- Populated after mr-discussion-create.sh runs. Empty if nothing was posted. -->

| Finding # | Discussion ID | Note ID | Mode | Location |
|-----------|---------------|---------|------|----------|
| 1 | [abc123…] | [12345] | [inline / general] | [path:line or —] |

---

## Review Summary

**Quality gate:**
- [tool]: [PASS/FAIL / NO EVIDENCE]

**Automated checks:**
- Security: [PASS / N findings]
- Reuse: [N findings, M escalated]
- Efficiency: [N findings, M escalated]
- Quality: [N findings, M escalated]

**Findings:** N confirmed → N posted → N skipped → N modified

## Review Status

**[PASS / FAIL / CHANGES_REQUESTED]** — [reason]
