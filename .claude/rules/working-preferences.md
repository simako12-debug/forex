# Working Preferences

This document contains user-specific preferences for working with Claude Code across all projects.

## Communication Style

### Asking Clarifying Questions

**IMPORTANT**: When receiving prompts from the user, always ask clarifying questions if you have any doubts or need more context.

**Rules**:
- ✅ **DO** ask follow-up questions to better understand requirements
- ✅ **DO** especially ask clarifying questions during brainstorming sessions
- ✅ **DO** seek clarification before making assumptions about implementation details
- ❌ **DON'T** assume you fully understand vague or incomplete requirements
- ❌ **DON'T** proceed with implementation if critical details are missing

**Example scenarios where clarifying questions are expected**:
- User provides a feature request without specifying all requirements
- Multiple implementation approaches are possible
- Business logic or behavior is ambiguous
- Technical decisions need user input (e.g., library choice, architecture pattern)
- Brainstorming new features or improvements

### Surfacing Assumptions & Pushback

**IMPORTANT**: State assumptions explicitly. Push back on overcomplication.

**Rules**:
- ✅ **DO** state assumptions before implementing
- ✅ **DO** present multiple interpretations when they exist — don't pick silently
- ✅ **DO** name what is specifically unclear instead of guessing
- ✅ **DO** push back when a simpler approach would solve the actual problem
- ❌ **DON'T** silently choose between interpretations
- ❌ **DON'T** implement "bulletproof" / "robust" / "neprůstřelné" without first asking which scenarios it must withstand

---

## Code Discipline

Cross-cutting principles for *all* code changes. Adapted from [Karpathy LLM coding guidelines](https://github.com/multica-ai/andrej-karpathy-skills/blob/main/skills/karpathy-guidelines/SKILL.md) (MIT license).

### Simplicity First

**Minimum code that solves the actual problem. Nothing speculative.**

- ❌ **DON'T** handle scenarios that cannot occur
- ❌ **DON'T** add abstractions for single-use code
- ❌ **DON'T** add configurability, fallbacks, or backward-compat shims unless a transition window or external consumer is documented
- ❌ **DON'T** anticipate future requirements that haven't been stated
- ✅ **DO** ask: "Would a senior engineer say this is overcomplicated?" — if yes, simplify
- ✅ **DO** push back on requests that imply unnecessary complexity

### Surgical Changes

**Touch only what the task requires.**

- ❌ **DON'T** improve adjacent code, comments, or formatting
- ❌ **DON'T** refactor code that isn't broken
- ❌ **DON'T** delete pre-existing dead code — mention it instead
- ✅ **DO** match existing style even if you'd write it differently
- ✅ **DO** mention unrelated issues you notice
- ✅ **DO** remove orphans (imports, variables, functions) that *your changes* made unused

**Test**: every changed line should trace directly to the user's request.

---

## Environment Awareness

**Apply only when code will run in an environment that cannot be faithfully reproduced locally** — AWS Lambda, S3, SQS, external APIs, managed cloud services. **Does NOT apply** when a local equivalent exists (local DB, local Redis, local S3 mock matching prod).

**IMPORTANT**: A green local test ≠ green production behavior in such environment. Never act as if it does.

**Rules**:
- ✅ **DO** identify the target environment explicitly before claiming a change is verified
- ✅ **DO** announce: "This will run in [ENV]. I can verify [what locally]. I cannot verify [what only in ENV]."
- ✅ **DO** request **explicit confirmation** from the user — wait for "yes, I understand the risk and will test in [ENV] before merge" (or equivalent) before continuing
- ✅ **DO** propose a concrete verification path (sandbox deploy, staging smoke test, canary, …)
- ❌ **DON'T** treat passing local tests as proof the change is safe in the target environment
- ❌ **DON'T** silently proceed (or merge) for changes in non-reproducible environments without explicit user acknowledgment
- ❌ **DON'T** apply this rule where a local equivalent makes verification faithful (local DB schema change → no gate)

**Why**: Incident — Node 20 → Node 24 upgrade in Lambda. Local tests passed; AWS Lambda runtime ships different built-in modules across Node versions; mail forwarder broke in production. Discipline ("I don't know if this is safe here, please confirm") would have caught it even without AWS-specific knowledge.

**How to apply**: When triggered, stop and ask. Wait for explicit user acknowledgment before continuing. A silent assumption that local = production is a regression.

---

## Future Preferences

Additional preferences will be added below as they are identified.