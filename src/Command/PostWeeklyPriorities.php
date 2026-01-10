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
    name: 'post:priorities',
    description: 'Post Monday update to Slack thread'
)]
class PostWeeklyPriorities extends Command
{
    private string $slackToken;
    private string $channelId;
    private string $linearApiKey;

    public function __construct(private ?Client $httpClient = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Fetches Linear issues and posts update to Slack thread')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be posted without actually posting')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get configuration
        $linearEnv = getenv('LINEAR_API_KEY') ?: ($_ENV['LINEAR_API_KEY'] ?? '');
        $this->linearApiKey = is_string($linearEnv) ? $linearEnv : '';

        $slackTokenEnv = getenv('SLACK_OAUTH_TOKEN') ?: ($_ENV['SLACK_OAUTH_TOKEN'] ?? '');
        $this->slackToken = is_string($slackTokenEnv) ? $slackTokenEnv : '';

        $channelIdEnv = getenv('SLACK_CHANNEL_ID') ?: ($_ENV['SLACK_CHANNEL_ID'] ?? '');
        $this->channelId = is_string($channelIdEnv) ? $channelIdEnv : '';

        $isDryRun = $input->getOption('dry-run');

        if (!$this->linearApiKey || !$this->slackToken || !$this->channelId) {
            $io->error('Missing required configuration. Please provide Slack token, channel ID, and Linear API key.');

            return Command::FAILURE;
        }

        if (!$this->httpClient) {
            $this->httpClient = new Client();
        }

        try {
            // Step 1: Get Linear issues
            $io->section('Fetching Linear issues...');
            $issuesData = $this->fetchLinearIssues();
            $formattedMessage = $this->formatIssuesForSlack($issuesData);

            if ($isDryRun) {
                $io->section('Dry Run - Message to be posted:');
                $io->text($formattedMessage);

                return Command::SUCCESS;
            }

            // Step 2: Find the weekly message thread
            $io->section('Finding weekly message thread...');
            $threadTs = $this->findWeeklyMessageThread();

            if (!$threadTs) {
                $io->warning('Could not find weekly message thread. Posting as new message.');
            }

            // Step 3: Post to Slack
            $io->section('Posting to Slack...');
            $result = $this->postToSlack($formattedMessage, $threadTs);

            if ($result) {
                $io->success('Successfully posted update to Slack!');

                return Command::SUCCESS;
            } else {
                $io->error('Failed to post to Slack.');

                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error('Error: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return array<int, array{week: string, issues: array<int, array<string, mixed>>}>
     */
    private function fetchLinearIssues(): array
    {
        $issuesQuery = <<<'GRAPHQL'
        query {
          viewer {
            id
            name
            assignedIssues(first: 100, orderBy: updatedAt) {
              nodes {
                id
                completedAt
                createdAt
                updatedAt
                estimate
                identifier
                priority
                title
                url
                state {
                  name
                  position
                }
              }
            }
          }
        }
        GRAPHQL;

        $body = json_encode(['query' => $issuesQuery]);
        assert($this->httpClient instanceof Client);
        $response = $this->httpClient->request('POST', 'https://api.linear.app/graphql', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $this->linearApiKey,
            ],
            'body' => $body,
        ]);

        /** @var array{data?: array{viewer?: array<string, mixed>}} $data */
        $data = json_decode($response->getBody()->getContents(), true) ?: [];

        if (!isset($data['data']['viewer'])) {
            throw new \RuntimeException('No user data found from Linear API.');
        }

        return $this->processLinearData($data['data']['viewer']);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<int, array{week: string, issues: array<int, array<string, mixed>>}>
     */
    public function processLinearData(array $user): array
    {
        $assignedIssues = $user['assignedIssues'] ?? [];
        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = is_array($assignedIssues) && isset($assignedIssues['nodes']) && is_array($assignedIssues['nodes']) ? $assignedIssues['nodes'] : [];

        /** @var array<int, array<string, mixed>> $allIssues */
        $allIssues = array_map(
            /** @param array<string, mixed> $issue
             * @return array<string, mixed>
             */
            fn(array $issue): array => [
                'id' => $issue['id'],
                'identifier' => $issue['identifier'],
                'title' => $issue['title'],
                'url' => $issue['url'],
                'estimate' => $issue['estimate'] ?? null,
                'priority' => $issue['priority'],
                'createdAt' => isset($issue['createdAt']) && is_string($issue['createdAt']) ? new \DateTimeImmutable($issue['createdAt'])->format('Y-m-d H:i:s') : null,
                'completedAt' => isset($issue['completedAt']) && is_string($issue['completedAt']) ? new \DateTimeImmutable($issue['completedAt'])->format('Y-m-d H:i:s') : null,
                'stateName' => isset($issue['state']) && is_array($issue['state']) && isset($issue['state']['name']) && is_string($issue['state']['name']) ? $issue['state']['name'] : '',
                'statePosition__c' => match (isset($issue['state']) && is_array($issue['state']) && isset($issue['state']['name']) && is_string($issue['state']['name']) ? $issue['state']['name'] : '') {
                    'Done' => 0,
                    'In Review' => 1,
                    'In Progress' => 2,
                    'Pending' => 3,
                    'Todo' => 4,
                    'Backlog' => 5,
                    'Triage' => 6,
                    'Canceled' => 8,
                    'Duplicate' => 9,
                    default => 100,
                },
                'stateSymbol' => match (isset($issue['state']) && is_array($issue['state']) && isset($issue['state']['name']) && is_string($issue['state']['name']) ? $issue['state']['name'] : '') {
                    'Done' => 'done_linear',
                    'In Review' => 'in_review_linear',
                    'In Progress' => 'in_progress_linear',
                    'Pending' => 'blocked',
                    'Todo' => 'todo_linear',
                    'Backlog' => 'backlog_linear',
                    'Triage' => 'triage_linear',
                    'Canceled' => 'canceled_linear',
                    'Duplicate' => 'clown_face',
                    default => null,
                },
            ],
            $nodes
        );

        // Calculate week boundaries
        $lastMonday = new \DateTimeImmutable('monday last week');
        $thisMonday = new \DateTimeImmutable('monday this week');
        $lastSunday = $thisMonday->modify('-1 second');
        $now = new \DateTimeImmutable('now');

        // Format week labels
        $lastWeekLabel = $lastMonday->format('Y-m-d');
        $thisWeekLabel = $thisMonday->format('Y-m-d');

        // Active states to include in "This Week"
        $activeStates = ['Done', 'In Review', 'In Progress', 'Pending', 'Todo'];

        // Filter issues for last week (completed only)
        $lastWeekIssues = array_filter(
            $allIssues,
            function ($issue) use ($lastMonday, $lastSunday) {
                if (!isset($issue['completedAt']) || !is_string($issue['completedAt'])) {
                    return false;
                }
                $completedDate = new \DateTimeImmutable($issue['completedAt']);

                return $completedDate >= $lastMonday && $completedDate <= $lastSunday;
            }
        );

        // Filter issues for this week (active states only, excluding completed before this week)
        $thisWeekIssues = array_filter(
            $allIssues,
            function ($issue) use ($activeStates, $thisMonday) {
                // Check if it's in an active state
                if (!in_array($issue['stateName'], $activeStates, true)) {
                    return false;
                }

                // If it's Done and has a completedAt date, only include if completed this week
                if ('Done' === $issue['stateName'] && isset($issue['completedAt']) && is_string($issue['completedAt'])) {
                    $completedDate = new \DateTimeImmutable($issue['completedAt']);

                    return $completedDate >= $thisMonday; // Only include if completed this week
                }

                // Include all other active states (In Progress, Todo, etc.)
                return true;
            }
        );

        // Sort function for issues
        $sortIssues = function (array $a, array $b): int {
            $statePositionDiff = $a['statePosition__c'] <=> $b['statePosition__c'];
            if ($statePositionDiff) {
                return $statePositionDiff;
            }

            $estimateDiff = $b['estimate'] <=> $a['estimate'];
            if ($estimateDiff) {
                return $estimateDiff;
            }

            $priorityDiff = $a['priority'] <=> $b['priority'];
            if ($priorityDiff) {
                return $priorityDiff;
            }

            if (isset($a['completedAt']) || isset($b['completedAt'])) {
                $completedAtDiff = $a['completedAt'] <=> $b['completedAt'];
                if ($completedAtDiff) {
                    return $completedAtDiff;
                }
            }

            return $a['createdAt'] <=> $b['createdAt'];
        };

        // Sort both week arrays
        usort($thisWeekIssues, $sortIssues);
        usort($lastWeekIssues, $sortIssues);

        // Build result array (Last Week first, then This Week)
        $weeklyIssues = [];

        // Add last week if there are issues
        if (!empty($lastWeekIssues)) {
            $weeklyIssues[] = [
                'week' => $lastWeekLabel,
                'issues' => $lastWeekIssues,
            ];
        }

        // Add this week if there are issues
        if (!empty($thisWeekIssues)) {
            $weeklyIssues[] = [
                'week' => $thisWeekLabel,
                'issues' => $thisWeekIssues,
            ];
        }

        return $weeklyIssues;
    }

    /**
     * @param array<int, array{week: string, issues: array<int, array<string, mixed>>}> $weeklyIssues
     */
    private function formatIssuesForSlack(array $weeklyIssues): string
    {
        $blocks = [];

        foreach ($weeklyIssues as $weekData) {
            // Add week header using header block
            $blocks[] = [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $weekData['week'],
                ],
            ];

            // Build ordered list items for issues
            $listItems = [];
            /** @var array<int, array<string, mixed>> $issues */
            $issues = $weekData['issues'];
            foreach ($issues as $issue) {
                $stateSymbol = $issue['stateSymbol'] ?? null;
                $identifier = isset($issue['identifier']) && (is_string($issue['identifier']) || is_numeric($issue['identifier'])) ? (string) $issue['identifier'] : '';
                $title = isset($issue['title']) && (is_string($issue['title']) || is_numeric($issue['title'])) ? (string) $issue['title'] : '';

                // Build elements for this list item
                $itemElements = [];

                // Add state symbol emoji if present
                if (is_string($stateSymbol) && '' !== $stateSymbol) {
                    $itemElements[] = [
                        'type' => 'emoji',
                        'name' => $stateSymbol,
                    ];
                    $itemElements[] = [
                        'type' => 'text',
                        'text' => ' ',
                    ];
                }

                // Add issue link
                $itemElements[] = [
                    'type' => 'link',
                    'url' => 'https://linear.app/vacatia/issue/'.$identifier.'/',
                    'text' => $identifier,
                ];

                // Add separator and title
                $itemElements[] = [
                    'type' => 'text',
                    'text' => ' - '.$title,
                ];

                $listItems[] = [
                    'type' => 'rich_text_section',
                    'elements' => $itemElements,
                ];
            }

            // Add the ordered list if there are items
            if (!empty($listItems)) {
                $blocks[] = [
                    'type' => 'rich_text',
                    'elements' => [
                        [
                            'type' => 'rich_text_list',
                            'style' => 'ordered',
                            'elements' => $listItems,
                        ],
                    ],
                ];
            }
        }

        return json_encode(['blocks' => $blocks]) ?: '{"blocks":[]}';
    }

    private function findWeeklyMessageThread(): ?string
    {
        // Search for messages in the channel from the last week
        // Looking for typical weekly update patterns
        $searchPatterns = [
            'priorities for the week of',
        ];

        $now = time();
        $oneWeekAgo = $now - (7 * 24 * 60 * 60);

        // Get channel history
        assert($this->httpClient instanceof Client);
        $response = $this->httpClient->request('GET', 'https://slack.com/api/conversations.history', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->slackToken,
            ],
            'query' => [
                'channel' => $this->channelId,
                'oldest' => $oneWeekAgo,
                'limit' => 100,
            ],
        ]);

        /** @var array{ok?: bool, error?: string, messages?: array<int, array{text?: string, ts: string, thread_ts?: string}>}|array{} $data */
        $data = json_decode($response->getBody()->getContents(), true) ?: [];

        if (empty($data['ok'])) {
            $error = isset($data['error']) ? (string) $data['error'] : 'Unknown error';
            throw new \RuntimeException('Failed to get channel history: '.$error);
        }

        // Look for messages matching our patterns
        foreach ($data['messages'] ?? [] as $message) {
            $text = strtolower($message['text'] ?? '');
            foreach ($searchPatterns as $pattern) {
                if (str_contains($text, $pattern)) {
                    // Check if this is a parent message (not a reply)
                    if (!isset($message['thread_ts']) || $message['thread_ts'] === $message['ts']) {
                        return $message['ts'];
                    }
                }
            }
        }

        return null;
    }

    private function postToSlack(string $message, ?string $threadTs = null): bool
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($message, true) ?: [];
        $payload['channel'] = $this->channelId;
        $payload['reply_broadcast'] = true;

        if ($threadTs) {
            $payload['thread_ts'] = $threadTs;
        }

        assert($this->httpClient instanceof Client);
        $response = $this->httpClient->request('POST', 'https://slack.com/api/chat.postMessage', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->slackToken,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        /** @var array{ok?: bool, error?: string}|array{} $data */
        $data = json_decode($response->getBody()->getContents(), true) ?: [];

        if (empty($data['ok'])) {
            $error = isset($data['error']) ? (string) $data['error'] : 'Unknown error';
            throw new \RuntimeException('Failed to post to Slack: '.$error);
        }

        return true;
    }
}
