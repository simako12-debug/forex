# Example Analyses

This file shows examples of good Jira issue analysis output from the `/analyze-jira` skill.

## Example 1: Feature Request with Epic

### Input
```
/analyze-jira SHAR-13881
```

### Expected Output (with Epic Context)

**Note**: The analysis is automatically saved to `.ai/task/SHAR-13881/analysis.md` for future reference.

```markdown
# Analysis of SHAR-13881: Alert Media - Read Confirmation

## Understanding

**Summary**: Implement a read confirmation system for media alerts that allows users to mark alerts as read and persists this state across sessions.

**Requirements**:
- Users should be able to mark individual media alerts as read
- Read status must persist in the database
- Read status should be tracked per user per alert
- System should distinguish between unread and read alerts
- Likely needs API endpoint to toggle read/unread status
- UI needs to display read/unread indicator

**Success Criteria**:
- Users can click/interact to mark alerts as read
- Read status is immediately updated in UI
- Read status persists after page refresh
- Read status is associated with the current user
- Tests validate read/unread state transitions

**Technical Scope**:
- Services: notification microservice (handles alerts)
- Components:
  - Database schema: alerts table needs read_at or is_read column
  - API: notification service needs read confirmation endpoint
  - Frontend: alert UI component needs read toggle button
- Integration: May affect alert queries/filters

**Epic Context**:
- Epic: SHAR-12500 - Alert System Enhancement
- Epic Description: "Improve user experience with the alert system by adding read/unread tracking, smart filtering, and notification preferences"
- How This Issue Fits: Core feature of the alert enhancement epic - enables users to manage which alerts they've already reviewed

**Context**:
- Status: In Progress
- Priority: Medium
- Assigned to: [Team member]
- Related: SHAR-13882 (smart filtering), SHAR-13883 (notification preferences)
- Epic: SHAR-12500

## Assumptions

**Database**:
- Each alert record can have a read_at timestamp or is_read boolean
- Alerts table already exists and is modeled correctly
- User ID is available in the alerts context

**API Design**:
- New endpoint: PATCH /alerts/{id}/read or POST /alerts/{id}/confirmation
- Returns the updated alert object
- Likely needs authentication/authorization

**Frontend**:
- Read status update is idempotent (clicking twice has same effect as once)
- UI should provide visual feedback for read/unread state
- No batch read operations mentioned (only individual alerts)

**Notification Trigger**:
- Marking as read does NOT trigger new notifications
- Should not appear in "unread" notification counts

**Multi-tenancy**:
- Alert read status is per user (not global)
- Users should only see their own read status

## Questions

**Clarifications Needed**:
1. Should "read" also track WHEN the user read it? (read_at timestamp vs is_read boolean)
2. Are there multiple types of alerts? Do all need read confirmation or just some?
3. Should there be an "unread" action (toggle back to unread)?
4. Is this for the notification system or specifically media alerts?
5. Does the epic have more detail on the read status design that isn't in this issue?

**Missing Information**:
1. Where exactly is "media alert" defined? Is it in the notification MS or elsewhere?
2. What's the current alert schema? Are there existing tables to modify?
3. Is there a frontend for managing alerts? Which service serves it?
4. Should read status be returned in alert list queries?
5. Are there other issues in the epic (like filtering) that might affect this design?

**Edge Cases**:
1. What happens if a user marks an alert as read, then that alert is updated? Does it stay marked read?
2. Can admins/support staff mark alerts as read on behalf of users?
3. What if user deletes an alert - should it track that differently from read?
4. Should there be a "mark all as read" action?

**Validation Needed**:
1. Performance: Will this add significant queries to alert list endpoints?
2. Backwards compatibility: Existing API clients won't know about read status - is that OK?
3. Do we need to migrate existing alerts' read status? (Should they default to read or unread?)
```

## Example 2: Bug Fix

### Input
```
/analyze-jira SHAR-14107
```

### Expected Output

**Note**: The analysis is automatically saved to `.ai/task/SHAR-14107/analysis.md` for future reference.

```markdown
# Analysis of SHAR-14107: Assign Correct Complex Label for Scrappers

## Understanding

**Summary**: Fix the scrapper configuration system to correctly assign complex labels to scrappers based on their destination system.

**Requirements**:
- Each destination system must have a corresponding complex label
- Scrappers must be assigned the correct label for their destination
- Labels should be validated during scrapper setup/update
- Existing scrappers may have incorrect labels that need fixing

**Success Criteria**:
- New scrappers get the correct label automatically
- Existing scrappers with wrong labels are identified
- Label assignment is validated before save
- Tests verify correct label-to-destination mapping

**Technical Scope**:
- Services: Tools/Scrappers (or Tools/Cockpit)
- Components:
  - Scrapper configuration (app/Console/Commands/ScrapeCommand.php)
  - Label mapping logic
  - Data validation/constraints
  - Possibly database/Helm values

**Context**:
- Status: In Progress
- Priority: Medium
- Related: Scrapper deployment, complex system integration
- Blocking: Possibly other scrapper-related features

## Assumptions

**Label System**:
- Labels are defined somewhere (likely in .helm/values.*.yaml or Helm charts)
- Each label maps to exactly one destination system
- Labels are used for deployment or organization purposes

**Destination Systems**:
- Each scrapper has one primary destination system
- Destination system is defined at scrapper creation time
- Destination system cannot change (or changing it should update label)

**Current State**:
- Some scrappers currently have wrong labels
- The label assignment logic is either missing or incorrect
- There's a way to map destination systems to their labels

**Validation**:
- Labels must exist before assigning to scrapper
- Invalid destination systems should be rejected
- Label assignment should be atomic with scrapper creation/update

## Questions

**Clarifications Needed**:
1. What defines a "correct" label for a destination system? Is there a mapping table/config?
2. What's the current broken behavior? How do scrappers get assigned wrong labels?
3. Are we fixing just new scrappers, or also migrating existing ones?
4. What happens if you try to assign a scrapper to a destination with no defined label?

**Missing Information**:
1. Where is the label definition/mapping stored? (database, config, Helm values)
2. Where is the current label assignment happening? (CLI command, API endpoint, migration)
3. How many scrappers are affected? Do they all need manual fixing or can it be automated?
4. Are labels used elsewhere that we need to update?

**Technical Details**:
1. Should label be set automatically based on destination, or should it be selected by user?
2. Is validation needed at the database level (foreign key) or application level?
3. Should changing a scrapper's destination auto-update its label?
4. Are there existing tests for label assignment? Should they be updated?

**Migration/Data**:
1. Do we need a database migration to fix existing scrapper labels?
2. Should there be a command to audit/report scrappers with wrong labels?
3. How to identify which scrappers have wrong labels?

**Impact**:
1. Will this affect scrapper deployment? Need to redeploy to apply correct labels?
2. Are there any scrappers in production with wrong labels that need emergency fixing?
```

## Key Principles

These examples demonstrate:

1. **Epic Context Included**: When an issue is part of an epic, the epic's goals and scope are referenced
2. **Complete Understanding**: Requirements are broken down into specific, actionable items
3. **Assumptions Documented**: What we're assuming about the system and why
4. **Specific Questions**: Not vague - each question is answerable
5. **No Implementation**: No code, no file suggestions, no "here's how to fix it"
6. **Technical Depth**: Shows understanding of the codebase context

## How to Use These Examples

When using `/analyze-jira`:

1. **Always check for Epic links** first - they often contain crucial strategic context
2. Include epic information in the "Epic Context" section
3. Reference epic goals when explaining how this issue contributes
4. Ask clarifying questions about epic requirements if needed
5. Aim for this level of detail in your analysis
- Show you understand the current system and how it fits into broader features
- Ask specific questions, not generic ones
- Document your assumptions explicitly
- Help the reader understand scope and boundaries
- Enable the team to say "yes, that's right" or "no, actually..."
