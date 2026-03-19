# GitHub Actions Workflows

## Monday Priorities Update

Runs the `post:priorities` command every Monday to post a weekly priorities update to Slack.

### How it works

1. Fetches issues completed since last Monday and currently active issues (Todo, In Progress, In Review) from Linear via the `viewer` query
2. Separates issues into "last week" (completed) and "this week" (active) groups
3. Sorts by state, estimate, and priority
4. Formats as a rich text Slack message with status emojis and blocker details
5. Finds the weekly priorities thread in the channel and posts as a reply

### Schedule

Mondays at 12:00 UTC (5 AM PDT / 4 AM PST) — always done before 8 AM regardless of DST.

### Required Secrets

- **SLACK_OAUTH_TOKEN** — Slack OAuth token with `chat:write`, `channels:history`, `channels:read` scopes. Get from: https://api.slack.com/apps
- **SLACK_CHANNEL_ID** — Slack channel ID (format: `C1234567890`). Right-click channel > View channel details.
- **LINEAR_API_KEY** — Linear personal API key. Get from: https://linear.app/settings/api

## Weekly Project Updates

Runs the `post:project-updates` command every Friday to generate and post status updates for all in-progress Linear projects you lead.

### How it works

1. Fetches your in-progress projects from Linear (filtered by `lead: isMe`)
2. Skips projects that already have an update within the last 6 days
3. For each project, fetches issues updated since the last status update
4. Generates a summary via the Claude API (or uses a template fallback if unavailable)
5. Posts the update to Linear via the `projectUpdateCreate` mutation

### Schedule

Fridays at 14:00 UTC (7 AM PDT / 6 AM PST).

### Required Secrets

- **LINEAR_API_KEY** — Same as above (already configured)
- **ANTHROPIC_API_KEY** (optional) — Anthropic API key for Claude-generated summaries. Get from: https://console.anthropic.com. If not provided, a template-based fallback is used instead.

## How to Add Secrets

1. Go to your GitHub repository
2. Navigate to Settings > Secrets and variables > Actions
3. Click "New repository secret"
4. Add each secret with the name and value specified above

## Manual Testing

You can manually trigger either workflow:

1. Go to the Actions tab in your GitHub repository
2. Select the workflow
3. Click "Run workflow"
4. Select the branch and click "Run workflow"