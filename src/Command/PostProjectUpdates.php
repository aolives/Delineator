<?php

declare(strict_types=1);

namespace App\Command;

use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'post:project-updates',
    description: 'Generate and post weekly project status updates to Linear'
)]
class PostProjectUpdates extends Command
{
    private string $linearApiKey;
    private string $anthropicApiKey;

    public function __construct(private ?Client $httpClient = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Fetches project activity from Linear, generates a summary via Claude, and posts it as a project status update')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be posted without actually posting')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Generate updates even if a recent update exists')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $linearEnv = getenv('LINEAR_API_KEY') ?: ($_ENV['LINEAR_API_KEY'] ?? '');
        $this->linearApiKey = is_string($linearEnv) ? $linearEnv : '';

        $anthropicEnv = getenv('ANTHROPIC_API_KEY') ?: ($_ENV['ANTHROPIC_API_KEY'] ?? '');
        $this->anthropicApiKey = is_string($anthropicEnv) ? $anthropicEnv : '';

        $isDryRun = $input->getOption('dry-run');
        $isForce = $input->getOption('force');

        if ('' === $this->linearApiKey || '0' === $this->linearApiKey) {
            $io->error('Missing required configuration. Please provide LINEAR_API_KEY.');

            return Command::FAILURE;
        }

        if ('' === $this->anthropicApiKey || '0' === $this->anthropicApiKey) {
            $io->warning('ANTHROPIC_API_KEY not set — will use template fallback for all updates.');
        }

        if (!$this->httpClient instanceof Client) {
            $this->httpClient = new Client([
                'connect_timeout' => 10,
                'timeout' => 30,
            ]);
        }

        try {
            $io->section('Fetching in-progress projects...');
            $projects = $this->fetchProjects();
        } catch (\Exception $e) {
            $io->error('Error fetching projects: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $projects) {
            $io->info('No in-progress projects found.');

            return Command::SUCCESS;
        }

        $quietMessages = [
            'Quiet week on this project. Work continues as planned.',
            'No big updates — everything is tracking smoothly.',
            'Business as usual this week. On track.',
            'All good here — plugging along as planned.',
            'Nothing new to report. Steady as she goes.',
            'Low activity this week, everything on track.',
            'Smooth sailing — work continues in the background.',
            'No updates needed — things are humming along.',
            'Ticking along nicely. More to share next week.',
            'Cruising along — no blockers, no surprises.',
            'Steady week — everything moving as expected.',
            'Nothing to flag. Work is progressing normally.',
            'On track, no changes to report.',
            'Chugging along — more updates next time.',
            'Quiet stretch, but things are in good shape.',
            'Holding steady. Will have more to share soon.',
            'Everything is on track. Business as usual.',
            'Moving along as planned — nothing to call out.',
            'Progressing as expected — no surprises this week.',
            'Keeping the wheels turning. Nothing new to report.',
            'Status quo this week. All good.',
            'Heads down, making progress. More next week.',
            'Things are humming along nicely.',
            'Staying the course — everything on track.',
            'All quiet on this front. Moving forward.',
        ];
        shuffle($quietMessages);
        $quietMessageIndex = 0;

        $failures = 0;

        foreach ($projects as $project) {
            $projectId = is_scalar($project['id'] ?? null) ? (string) $project['id'] : '';
            $projectName = is_scalar($project['name'] ?? null) ? (string) $project['name'] : '';
            $projectDescription = is_scalar($project['description'] ?? null) ? (string) $project['description'] : '';
            $io->section("Processing: {$projectName}");

            try {
                // Determine baseline date from last project update
                /** @var array<string, mixed> $projectUpdates */
                $projectUpdates = is_array($project['projectUpdates'] ?? null) ? $project['projectUpdates'] : [];
                /** @var array<int, array<string, mixed>> $lastUpdateNodes */
                $lastUpdateNodes = is_array($projectUpdates['nodes'] ?? null) ? $projectUpdates['nodes'] : [];
                $previousBody = '';
                if ([] !== $lastUpdateNodes && is_string($lastUpdateNodes[0]['createdAt'] ?? null)) {
                    $lastUpdateDate = new \DateTimeImmutable($lastUpdateNodes[0]['createdAt']);
                    $sixDaysAgo = new \DateTimeImmutable('-6 days', new \DateTimeZone('UTC'));

                    // Skip if a manual update was posted within the last 6 days
                    if (!$isForce && $lastUpdateDate >= $sixDaysAgo) {
                        $io->info("Skipping {$projectName} — already has a recent update.");
                        continue;
                    }

                    $baselineDate = $lastUpdateNodes[0]['createdAt'];
                    $previousBody = is_string($lastUpdateNodes[0]['body'] ?? null) ? $lastUpdateNodes[0]['body'] : '';
                } else {
                    $baselineDate = new \DateTimeImmutable('-7 days', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
                }

                $issues = $this->fetchProjectIssues($projectId, $baselineDate);

                if ([] === $issues && '' === $previousBody) {
                    // New project with no activity — still in planning phase
                    $body = 'Currently in the planning and scoping phase. Defining requirements and shaping the work ahead.';
                    $health = 'onTrack';
                } elseif ([] === $issues) {
                    $body = $quietMessages[$quietMessageIndex % count($quietMessages)];
                    ++$quietMessageIndex;
                    $health = 'onTrack';
                } elseif ('' === $this->anthropicApiKey || '0' === $this->anthropicApiKey) {
                    $result = $this->generateTemplateUpdate($issues);
                    $body = $result['body'];
                    $health = $result['health'];
                } else {
                    try {
                        $result = $this->generateStatusUpdate($projectName, $projectDescription, $issues, $previousBody);
                        $body = $result['body'];
                        $health = $result['health'];
                    } catch (\Exception $e) {
                        $io->warning("Claude API unavailable ({$e->getMessage()}), using template fallback.");
                        $result = $this->generateTemplateUpdate($issues);
                        $body = $result['body'];
                        $health = $result['health'];
                    }
                }

                if ($isDryRun) {
                    $io->section("[DRY RUN] {$projectName}");
                    $io->text("Health: {$health}");
                    $io->text($body);
                } else {
                    $this->postProjectUpdate($projectId, $body, $health);
                    $io->success("Successfully posted update for {$projectName} (health: {$health})");
                }
            } catch (\Exception $e) {
                ++$failures;
                $io->error("Failed to process {$projectName}: {$e->getMessage()}");
            }
        }

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function executeGraphQLQuery(string $query, array $variables = []): array
    {
        $payload = ['query' => $query];
        if ([] !== $variables) {
            $payload['variables'] = $variables;
        }
        $body = json_encode($payload);
        assert($this->httpClient instanceof Client);
        $response = $this->httpClient->request('POST', 'https://api.linear.app/graphql', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $this->linearApiKey,
            ],
            'body' => $body,
        ]);

        /** @var array<string, mixed> $data */
        $data = json_decode($response->getBody()->getContents(), true) ?: [];

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProjects(): array
    {
        $query = <<<'GRAPHQL'
        query {
          projects(
            first: 50,
            filter: {
              lead: { isMe: { eq: true } },
              status: { type: { eq: "started" } }
            }
          ) {
            nodes {
              id
              name
              description
              projectUpdates(first: 1) {
                nodes {
                  createdAt
                  body
                }
              }
            }
          }
        }
        GRAPHQL;

        $data = $this->executeGraphQLQuery($query);

        if (!empty($data['errors'])) {
            /** @var array<int, array<string, mixed>> $errors */
            $errors = is_array($data['errors']) ? $data['errors'] : [];
            $errorMessage = isset($errors[0]) && is_string($errors[0]['message'] ?? null)
                ? $errors[0]['message']
                : 'Unknown GraphQL error';
            throw new \RuntimeException('GraphQL error fetching projects: '.$errorMessage);
        }

        if (!isset($data['data']) || !is_array($data['data']) || !isset($data['data']['projects'])) {
            throw new \RuntimeException('No project data found from Linear API.');
        }

        /** @var array<string, mixed> $projects */
        $projects = $data['data']['projects'];

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = is_array($projects['nodes'] ?? null) ? $projects['nodes'] : [];

        return $nodes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProjectIssues(string $projectId, string $since): array
    {
        $query = <<<'GRAPHQL'
        query($projectId: String!, $since: DateTime!) {
          project(id: $projectId) {
            issues(
              first: 100,
              filter: { updatedAt: { gte: $since } }
            ) {
              nodes {
                identifier
                title
                state {
                  name
                }
                assignee {
                  name
                }
                labels {
                  nodes {
                    name
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

        $data = $this->executeGraphQLQuery($query, [
            'projectId' => $projectId,
            'since' => $since,
        ]);

        if (!empty($data['errors'])) {
            /** @var array<int, array<string, mixed>> $errors */
            $errors = is_array($data['errors']) ? $data['errors'] : [];
            $errorMessage = isset($errors[0]) && is_string($errors[0]['message'] ?? null)
                ? $errors[0]['message']
                : 'Unknown GraphQL error';
            throw new \RuntimeException('GraphQL error fetching issues: '.$errorMessage);
        }

        if (!isset($data['data']) || !is_array($data['data']) || !isset($data['data']['project'])) {
            throw new \RuntimeException('Unexpected response fetching issues: missing project data.');
        }

        /** @var array<string, mixed> $dataArray */
        $dataArray = $data['data'];
        $project = is_array($dataArray['project'] ?? null) ? $dataArray['project'] : [];
        $issues = is_array($project['issues'] ?? null) ? $project['issues'] : [];

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = is_array($issues['nodes'] ?? null) ? $issues['nodes'] : [];

        return $nodes;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     *
     * @return array{body: string, health: string}
     */
    private function generateStatusUpdate(string $projectName, string $projectDescription, array $issues, string $previousUpdate = ''): array
    {
        $issuesSummary = '';
        foreach ($issues as $issue) {
            $identifier = is_scalar($issue['identifier'] ?? null) ? (string) $issue['identifier'] : '';
            $title = is_scalar($issue['title'] ?? null) ? (string) $issue['title'] : '';
            $state = is_array($issue['state'] ?? null) && is_string($issue['state']['name'] ?? null) ? $issue['state']['name'] : 'Unknown';
            $assignee = is_array($issue['assignee'] ?? null) && is_string($issue['assignee']['name'] ?? null) ? $issue['assignee']['name'] : 'Unassigned';
            $labelNodes = is_array($issue['labels'] ?? null) && is_array($issue['labels']['nodes'] ?? null) ? $issue['labels']['nodes'] : [];
            $labels = array_map(
                fn (mixed $label): string => is_array($label) && is_string($label['name'] ?? null) ? $label['name'] : '',
                $labelNodes
            );
            $labelStr = [] !== $labels ? ' ['.implode(', ', $labels).']' : '';

            $issuesSummary .= "- {$identifier}: {$title} ({$state}, assigned to {$assignee}){$labelStr}\n";
        }

        $previousContext = '';
        if ('' !== $previousUpdate) {
            $previousContext = <<<CONTEXT

        Here is the previous status update for context. Do not repeat the same phrasing or structure — write something fresh:

        ---
        {$previousUpdate}
        ---

        CONTEXT;
        }

        $descriptionContext = '' !== $projectDescription ? "\n        Project description: {$projectDescription}\n" : '';

        $prompt = <<<PROMPT
        You are a project lead writing a brief weekly status update for "{$projectName}". Your audience is management.
        {$descriptionContext}
        Write like a confident engineer who knows their project is in good shape. Be straightforward and factual — say what happened and what's coming next. Don't use hedging language, don't qualify things, and don't mention anything about pace or velocity. Don't reference ticket IDs, assignee names, or labels. Avoid words like "however", "although", "despite", "remains", "concerns", or "challenges".

        IMPORTANT: The state field on each issue is authoritative. Only describe work as "in progress" if the state is literally "In Progress". Issues in "Backlog" or "Todo" are not being actively worked on — they may have been updated for administrative reasons (due date changes, comments, re-prioritization). Do not describe backlog items as if work has started on them.
        {$previousContext}
        Here are the issues that were updated this week:

        {$issuesSummary}

        Write 2-3 plain sentences. Focus on what was accomplished or started, and what's up next. No headers, no bullet points.

        Return your response as JSON with two fields:
        - "body": the status update text (plain markdown, no headers)
        - "health": one of "onTrack", "atRisk", or "offTrack"

        Health should almost always be "onTrack". Use "atRisk" only if an external dependency is actively blocking the team. Use "offTrack" only if the project cannot move forward at all.

        Return ONLY the JSON object, no markdown code fences or other text.
        PROMPT;

        assert($this->httpClient instanceof Client);
        $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->anthropicApiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'json' => [
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ],
        ]);

        /** @var array<string, mixed> $responseData */
        $responseData = json_decode($response->getBody()->getContents(), true) ?: [];

        $content = is_array($responseData['content'] ?? null) ? $responseData['content'] : [];
        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text = $block['text'];
                break;
            }
        }

        if ('' === $text) {
            throw new \RuntimeException('Claude API returned no text content.');
        }

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text) ?? $text;
        $text = preg_replace('/\n?```\s*$/', '', $text) ?? $text;

        /** @var array{body?: string, health?: string}|null $parsed */
        $parsed = json_decode($text, true);

        if (is_array($parsed) && is_string($parsed['body'] ?? null) && '' !== $parsed['body']) {
            $health = is_string($parsed['health'] ?? null) && in_array($parsed['health'], ['onTrack', 'atRisk', 'offTrack'], true)
                ? $parsed['health']
                : 'onTrack';

            return ['body' => $parsed['body'], 'health' => $health];
        }

        // If we got text but couldn't parse valid JSON with a body, use the raw text
        if ('' !== trim($text)) {
            return ['body' => $text, 'health' => 'onTrack'];
        }

        throw new \RuntimeException('Claude API returned empty body after parsing.');
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     *
     * @return array{body: string, health: string}
     */
    private function generateTemplateUpdate(array $issues): array
    {
        $completed = [];
        $inProgress = [];
        $other = [];

        foreach ($issues as $issue) {
            $title = is_scalar($issue['title'] ?? null) ? (string) $issue['title'] : '';
            $state = is_array($issue['state'] ?? null) && is_string($issue['state']['name'] ?? null) ? $issue['state']['name'] : 'Unknown';

            match ($state) {
                'Done' => $completed[] = $title,
                'In Progress', 'In Review' => $inProgress[] = $title,
                default => $other[] = $title,
            };
        }

        $parts = [];
        if ([] !== $completed) {
            $count = count($completed);
            $parts[] = "Completed {$count} item".($count > 1 ? 's' : '').' this week.';
        }
        if ([] !== $inProgress) {
            $count = count($inProgress);
            $parts[] = "{$count} item".($count > 1 ? 's' : '').' currently in progress.';
        }
        if ([] === $completed && [] === $inProgress) {
            $parts[] = 'Continuing steady work across the project.';
        }

        $emojis = ['🚀', '✅', '💪', '👍', '🎯', '⚡', '🌟', '✨', '🏗', '🔥', '💫', '🙌'];
        $emoji = $emojis[array_rand($emojis)];

        return ['body' => implode(' ', $parts).' '.$emoji, 'health' => 'onTrack'];
    }

    private function postProjectUpdate(string $projectId, string $body, string $health): void
    {
        $mutation = <<<'GRAPHQL'
        mutation($projectId: String!, $body: String!, $health: ProjectUpdateHealthType!) {
          projectUpdateCreate(input: {
            projectId: $projectId,
            body: $body,
            health: $health
          }) {
            success
            projectUpdate {
              id
            }
          }
        }
        GRAPHQL;

        $data = $this->executeGraphQLQuery($mutation, [
            'projectId' => $projectId,
            'body' => $body,
            'health' => $health,
        ]);

        if (!empty($data['errors'])) {
            /** @var array<int, array<string, mixed>> $errors */
            $errors = is_array($data['errors']) ? $data['errors'] : [];
            $errorMessage = isset($errors[0]) && is_string($errors[0]['message'] ?? null)
                ? $errors[0]['message']
                : 'Unknown GraphQL error';
            throw new \RuntimeException('GraphQL error posting update: '.$errorMessage);
        }

        /** @var array<string, mixed> $dataArray */
        $dataArray = is_array($data['data'] ?? null) ? $data['data'] : [];
        /** @var array<string, mixed> $createResult */
        $createResult = is_array($dataArray['projectUpdateCreate'] ?? null) ? $dataArray['projectUpdateCreate'] : [];
        $success = $createResult['success'] ?? false;
        if (true !== $success) {
            throw new \RuntimeException('Failed to create project update in Linear.');
        }
    }
}
