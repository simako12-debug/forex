# Architect Skill Examples

## Example 1: Driver Enhancement (SHAR-14151)

### Workflow

```bash
# Step 1: Understand the requirements
/analyze-jira SHAR-14151

# Review the analysis output and clarifications

# Step 2: Get technical implementation plan
/architect SHAR-14151
```

### Analysis Output (Summary)
```
Issue: [DRIVER] Implement error handling for special characters in Genetec driver
Requirements:
- Escape brackets and commas in query parameters
- Only escape static data, not internal function calls
- Card number formatting uses internal functions (no escaping)
```

### Architect Output (Expected)

```markdown
# Implementation Plan for SHAR-14151

**Issue**: Implement error handling for special characters in Genetec driver
**Services Affected**: Packages / Drivers / Genetec
**Complexity**: Low

## Architecture

### Approach
Implement character escaping at the Genetec driver's HTTP query parameter construction layer. The solution escapes brackets `[`, `]` and commas `,` to `\[`, `\]`, `\,` before passing data to Genetec's API, while preserving internal function calls used for card number formatting.

### Integration Points
- Complex and Microservices: Use Genetec driver for access control integration
- Genetec API: Accepts HTTP query parameters with escaped special characters
- Card Number Formatting: Uses internal Genetec functions (no escaping)

### Data Flow
User Data → Driver (Escape special chars) → HTTP Query Params → Genetec API → Processing

### Design Decisions
- **Escaping Location**: In HTTP query parameter construction (not database layer) to keep data clean
- **Selective Escaping**: Only escape static data parameters; card formatting functions remain untouched
- **Method**: Simple string replacement of special characters with backslash prefix

## Implementation Steps

1. **Identify Query Parameter Construction Points**
   - Files: `packages/drivers/Genetec/src/*`
   - What: Find all locations where HTTP query parameters are built for Genetec API
   - Why: Need to identify where to apply escaping

2. **Create Escaping Utility Function**
   - Files: `packages/drivers/Genetec/src/Utils/CharacterEscaper.php` (NEW)
   - What: Create a static method to escape brackets and commas
   - Why: Reusable, testable escaping logic

3. **Apply Escaping to Static Query Parameters**
   - Files: `packages/drivers/Genetec/src/Client.php` (or similar)
   - What: Call escaping utility for static parameter values
   - Why: Prevents Genetec API from breaking on special characters

4. **Update/Create Unit Tests**
   - Files: `packages/drivers/Genetec/tests/Unit/CharacterEscaperTest.php` (NEW)
   - What: Test escaping logic with various inputs
   - Why: Verify escaping works correctly

5. **Update Integration Tests**
   - Files: `packages/drivers/Genetec/tests/Integration/ClientTest.php` (if exists)
   - What: Add test cases for user data with special characters
   - Why: Verify escaping integrates properly with API calls

## Files to Modify/Create

### Genetec Driver
- `packages/drivers/Genetec/src/Utils/CharacterEscaper.php` (NEW) - Escaping utility
- `packages/drivers/Genetec/src/Client.php` - Apply escaping to query parameters
- `packages/drivers/Genetec/tests/Unit/CharacterEscaperTest.php` (NEW) - Unit tests for escaper
- `packages/drivers/Genetec/tests/Integration/ClientTest.php` - Add integration test cases

## Testing Strategy

**Unit Tests**:
- `CharacterEscaperTest`: Test escaping single characters, multiple characters, edge cases
  - Test `[`, `]`, `,` characters
  - Test combinations like `[user,name]`
  - Test data without special characters (should pass through unchanged)

**Integration Tests**:
- Add test cases to ClientTest for:
  - User names with commas: "Smith, John"
  - User names with brackets: "Team [A]"
  - Multiple special characters: "Group [1], Admin"

**Manual Testing**:
- Create test user in Genetec with special characters in name
- Verify Genetec API no longer fails
- Verify card number formatting still works correctly
- Verify other API calls unaffected

## Database/Schema Changes

None - this is a driver-level change, no schema modifications needed.

## Potential Challenges & Solutions

| Challenge | Solution | Mitigation |
|-----------|----------|-----------|
| Double escaping (escaping already-escaped data) | Implement idempotent escaping | Test with pre-escaped data |
| Genetec internal functions breaking if escaped | Keep internal functions untouched | Clear code comments showing which functions need escaping |
| Backwards compatibility with existing data | Escaping happens only when sending to API | Verify with existing user records |
| Performance impact | Minimal - simple string replacement | Benchmarking if needed |

## Dependencies & Configuration

**Required Changes**:
- No new dependencies
- No environment variables
- No configuration changes

## Success Criteria

- [ ] Brackets and commas in user data are properly escaped with backslash
- [ ] Genetec API no longer fails on special characters
- [ ] Card number formatting (internal functions) remains unescaped and functional
- [ ] All unit tests pass with high coverage
- [ ] Integration tests verify API calls succeed with special characters
- [ ] No impact on non-affected API calls or functionality
- [ ] Code follows existing Genetec driver patterns
```

---

## Example 2: Feature Implementation (Hypothetical)

### Workflow
```bash
/analyze-jira SHAR-13881
# Review analysis

/architect SHAR-13881
# Get technical plan for implementation

# If plan looks good:
EnterPlanMode
# Implement according to plan with approval
```

This gives a complete workflow:
1. **Analyze** - Understand requirements, ask clarifying questions
2. **Architect** - Get technical implementation blueprint
3. **Implement** - Code with plan approval and guidance

---

## Tips for Best Results

1. **Run /analyze-jira first**
   - The architect skill builds on the analysis
   - Clarify requirements before planning implementation

2. **Provide context in the analysis**
   - If you clarify requirements with the user, that context will inform the architect plan

3. **Review the architecture decisions**
   - The plan should explain WHY design decisions were made
   - This helps with implementation and code review

4. **Use the plan as a checklist**
   - Follow the implementation steps in order
   - Use the files list to track progress
   - Use success criteria to verify completion

5. **Adapt as needed**
   - Plans are guides, not rigid requirements
   - If implementation reveals new information, adjust accordingly
   - Update the plan if scope changes
