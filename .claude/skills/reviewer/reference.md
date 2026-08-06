# Reviewer Reference: Evaluation Checklists

## Critical Evaluation Points

**Plan Compliance:**
- Deviation from approved architecture? (even minor)
- New dependencies introduced? (composer.json changes, packages added)
- Configuration changes aligned with plan? (config/*, .env)
- Database migrations match architecture? (schema changes, seeder impacts)
- Service layer changes match approved patterns? (Trait usage, dependency injection)

**Correctness:**
- Null pointer exceptions possible? (null !== check, property access on null)
- Empty collection handling? (foreach on null, array access)
- Boundary conditions (0, -1, PHP_INT_MAX)?
- Race conditions in concurrent code?
- Database transaction isolation (beginTransaction, rollback paths)?
- Eloquent N+1 queries? (missing eager loading with()?)

**Regressions:**
- Shared code modified → what breaks? (shared Services, Traits, Middleware)
- State mutations → who depends on old state? (model properties, cache)
- API changes → backward compatibility? (Controller signatures, event payloads)
- Database queries optimized → any N+1 patterns? (missing with(), chunks, counts)
- Migration impacts → can it rollback cleanly?
- Queue job changes → backward compatibility with delayed jobs?

**Error Handling:**
- External calls (HTTP, database, S3) wrapped in try/catch?
- Exceptions logged with context (Sentry, Log::error)?
- User-facing errors communicative? (not raw exception messages)
- Cascading failures prevented? (transaction rollback on error)
- Database constraints enforced? (foreign keys, unique constraints handled)
- API client timeouts configured? (HTTP retries, exponential backoff)

**Testing:**
- Happy path tested? (success case)
- At least one error path tested? (exception, null, boundary)
- Edge cases from claims tested?
- Flaky tests? (timing, test ordering, database state, randomness)
- Database tests using transactions/rollback or refreshState()?
- Mocked external dependencies (APIs, S3, queues)?
- Test data factories used? (Model::factory())

## Common Developer Mistakes

**"It's tested"** (without showing tests)
→ Tests passing ≠ tests existing. Demand test file + line number.

**"Should be fine"** (vague technical reasoning)
→ Not evidence. Challenge: prove with code or architecture reference.

**"Follows existing pattern"** (without verifying pattern is safe)
→ Existing pattern may be the bug. Show it's used safely elsewhere.

**"Rare edge case"** (hand-wavy probability)
→ Production = "rare" happens hourly. Challenge: prove impossible or tested.

**"Refactoring, not behavior change"** (without showing tests prove behavior preserved)
→ Refactorings break. Demand: test results before/after same.

**"Checked already"** (without showing output)
→ Demand: run command again, show output here.

**"TODO: validate card expiry"** (unlinked @todo in CardService.php)
→ AUTOMATIC FAIL. Challenge: Show Jira ticket key (SHAR-XXXXX) or rejection stands.

**"Will handle in follow-up PR"** (future work, no tracking)
→ Future work = technical debt. Challenge: Specific Jira ticket created now.

**"Flagged for future refactor"** (no accountability)
→ No ticket, no timeline = no acceptance. Challenge: SHAR ticket or REJECT.

## Escalation Examples

### Authorization token caching
Developer caches auth tokens for 5 min. Plan says "verify on each request".
Evidence: Token can be revoked during 5-min window, user retains access.
Risk level: Security vs performance trade-off.
**Decision required from user:** Is 5-min cache window acceptable?

### Unresolved @todo tags
Code contains 3 unresolved todos:
- `app/Services/CardService.php:142`: "TODO: SHAR-14100 validate expiry dates"
- `app/Services/BadgeService.php:256`: "TODO: handle concurrent badge updates"
- `app/Drivers/WawelynxDriver.php:389`: "FIXME: error handling for API timeout"

Developer response: "Will handle in next sprint"
**Decision required from user:**
- CardService todo links to SHAR-14100 (acceptable)
- BadgeService todo has no ticket — which SHAR ticket?
- WawelynxDriver todo has no ticket — which SHAR ticket?
Verdict: Cannot merge until all todos have explicit Jira ticket linkage.
