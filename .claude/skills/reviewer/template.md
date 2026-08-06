# Review: TICKET_ID

<!-- INSTRUCTIONS: Copy this entire template to .ai/task/TICKET_ID/review.md at the start. Replace TICKET_ID with actual ticket. Work top-to-bottom. Replace [placeholders] with findings. Do NOT remove any section. -->

## Review log (v1)

### Rules loaded
<!-- Read all files in .claude/rules/. List each one. -->
- [filename] — [brief description]

### Pre-check
<!-- Check: Is this a draft MR? Trivial change? Already reviewed? If any → skip review with note. -->
- Draft: [Yes/No]
- Trivial: [Yes/No — reason]
- Previously reviewed: [Yes/No — if yes, only changes since vN]

### Record
<!-- Read developer.md + architecture.md from task dir. Determine target branch from architecture.md, MR, or git tracking — do NOT assume main. Run git diff <target>...HEAD. -->
- Developer log: [summary of what developer claims]
- Architecture: [key plan decisions]
- Target branch: [how determined + which branch]
- Diff scope: [N files, M insertions, K deletions]

### Plan compliance
<!-- Compare implementation against architecture.md. Log every checkpoint. -->
- Architecture specifies: [key decisions]
- Implementation matches: [Yes/No + deviations]
- Scope creep: [None / describe]

### Quality gate
<!-- Read CLAUDE.md for required tools. Check developer.md for evidence each was run AFTER last change. Do NOT run them yourself. Missing = challenge. -->
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
<!-- For every claim in developer.md, verify evidence (file:line, test, output). -->
- Claim: "[text]" — [checked FILE:LINE] → [OK / challenge]

### Issue validation
<!-- Validate each issue. CONFIRMED = flag, DROPPED = skip. Only flag definite problems. -->
- Issue: [description] → [CONFIRMED / DROPPED (reason)]

---

## Reviewer challenges (v1)

<!-- Only CONFIRMED issues. If none, write "No challenges." -->

1. **[What]** [HIGH/MEDIUM]
   - Why: [impact]
   - Required: [evidence needed]

---

## Review Summary

**Quality gate:**
- [tool]: [PASS/FAIL]

**Automated checks:**
- Security: [PASS / N findings]
- Reuse: [N findings, M escalated]
- Efficiency: [N findings, M escalated]
- Quality: [N findings, M escalated]

**Issues:** N total → N validated → N challenges

## Review Status

**[PASS/FAIL]** - [reason]
