# GitHub Actions Setup for Monday Priorities

This workflow runs the `post:priorities` command every Monday at 9:00 AM PST.

## Required GitHub Secrets

You need to configure the following secrets in your GitHub repository:

1. **SLACK_OAUTH_TOKEN**
   - Your Slack OAuth token with permissions to post messages
   - Get it from: https://api.slack.com/apps (Your App > OAuth & Permissions)
   - Required scopes: `chat:write`, `channels:history`, `channels:read`

2. **SLACK_CHANNEL_ID**
   - The ID of the Slack channel to post to
   - Find it by right-clicking on the channel name in Slack > View channel details
   - Format: `C1234567890`

3. **LINEAR_API_KEY**
   - Your Linear API key
   - Get it from: https://linear.app/settings/api
   - Create a personal API key with read permissions

## How to Add Secrets

1. Go to your GitHub repository
2. Navigate to Settings > Secrets and variables > Actions
3. Click "New repository secret"
4. Add each secret with the name and value specified above

## Manual Testing

You can manually trigger the workflow to test it:

1. Go to Actions tab in your GitHub repository
2. Select "Monday Priorities Update" workflow
3. Click "Run workflow" button
4. Select the branch and click "Run workflow"

## Schedule

The workflow runs automatically every Monday at:
- 9:00 AM PST (Pacific Standard Time)
- 9:00 AM PDT (Pacific Daylight Time)

Note: The cron expression uses UTC time, so it's set to `0 17 * * 1` which converts to 9 AM PST.