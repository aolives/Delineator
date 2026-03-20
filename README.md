# Delineator
Posts recurring status updates so I don't have to.

## Commands

### `post:priorities`
Posts a weekly priorities update to Slack every Monday. Fetches your active Linear issues and formats them into a Slack message.

**Options:**
- `--dry-run` — Preview updates without posting

**Behavior:**
- Fetches issues completed since last Monday and active issues (Todo, In Progress, In Review)
- Sorts by state, estimate, and priority
- Formats as a rich text Slack message with status emojis and blocker details
- Finds the weekly thread in the channel and posts as a reply
- Shows blocking relationships between issues

### `post:project-updates`
Posts weekly status updates to Linear for every in-progress project you lead. Uses the Claude API to generate natural-sounding summaries, with a template fallback if the API is unavailable.

**Options:**
- `--dry-run` — Preview updates without posting
- `--force` / `-f` — Generate updates even if a recent one exists (within 6 days)

**Behavior:**
- Skips projects that already have an update within the last 6 days
- New projects with no issues get a "planning phase" message
- Quiet projects with no recent issue activity get a randomized status message
- Gracefully degrades if `ANTHROPIC_API_KEY` is not set (uses template-based summaries)

## Setup

See [.github/workflows/README.md](.github/workflows/README.md) for secrets and scheduling details.
