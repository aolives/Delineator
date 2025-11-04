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
final class PostWeeklyPriorities extends Command
{
    private Client $httpClient;
    private string $slackToken;
    private string $channelId;
    private string $linearApiKey;

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

        $this->httpClient = new Client();

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
     * @return array<int, array{date: string, issues: array<int, array<string, mixed>>}>
     */
    private function fetchLinearIssues(): array
    {
        $issuesQuery = <<<'GRAPHQL'
        query {
          viewer {
            id
            name
            assignedIssues(orderBy: updatedAt) {
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
                cycle {
                  id
                  isActive
                  isNext
                  isPrevious
                  name
                  startsAt
                }
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
     * @return array<int, array{date: string, issues: array<int, array<string, mixed>>}>
     */
    private function processLinearData(array $user): array
    {

        $assignedIssues = $user['assignedIssues'] ?? [];
        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = is_array($assignedIssues) && isset($assignedIssues['nodes']) && is_array($assignedIssues['nodes']) ? $assignedIssues['nodes'] : [];

        /** @var array<int, array<string, mixed>> $allIssues */
        $allIssues = array_map(
            /** @param array<string, mixed> $issue
             * @return array<string, mixed>
             */
            function(array $issue): array {
                return [
                'id' => $issue['id'],
                'identifier' => $issue['identifier'],
                'title' => $issue['title'],
                'url' => $issue['url'],
                'estimate' => $issue['estimate'] ?? null,
                'priority' => $issue['priority'],
                'createdAt' => isset($issue['createdAt']) && is_string($issue['createdAt']) ? (new \DateTimeImmutable($issue['createdAt']))->format('Y-m-d H:i:s') : null,
                'completedAt' => isset($issue['completedAt']) && is_string($issue['completedAt']) ? (new \DateTimeImmutable($issue['completedAt']))->format('Y-m-d H:i:s') : null,
                'cycleStartsAt' => isset($issue['cycle']) && is_array($issue['cycle']) && isset($issue['cycle']['startsAt']) && is_string($issue['cycle']['startsAt']) ? (new \DateTimeImmutable($issue['cycle']['startsAt']))->format('Y-m-d') : null,
                'isActive' => isset($issue['cycle']) && is_array($issue['cycle']) && isset($issue['cycle']['isActive']) ? $issue['cycle']['isActive'] : null,
                'isNext' => isset($issue['cycle']) && is_array($issue['cycle']) && isset($issue['cycle']['isNext']) ? $issue['cycle']['isNext'] : null,
                'isPrevious' => isset($issue['cycle']) && is_array($issue['cycle']) && isset($issue['cycle']['isPrevious']) ? $issue['cycle']['isPrevious'] : null,
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
                    'Pending' => ':blocked:',
                    'In Progress' => ':loading:',
                    'In Review' => ':in_review:',
                    'Done' => ':done_2:',
                    'Canceled' => ':x:',
                    'Duplicate' => ':clown_face:',
                    default => null,
                },
            ];
            },
            $nodes
        );

        /** @var array<int, string|null> $dates */
        $dates = array_column($allIssues, 'cycleStartsAt');
        $uniqueDates = array_unique($dates);

        /** @var array<string> $filteredDates */
        $filteredDates = array_values(array_filter($uniqueDates,
            /** @param mixed $date */
            function($date): bool {
                if (!is_string($date)) {
                    return false;
                }
                return new \DateTimeImmutable($date) >= new \DateTimeImmutable('monday last week');
            }
        ));

        $allIssuesByCycle = array_map(
            /** @param string $date */
            fn(string $date): array => ['date' => $date],
            $filteredDates
        );

        sort($allIssuesByCycle);

        foreach ($allIssuesByCycle as $key => $cycle) {
            $allIssuesByCycle[$key]['issues'] = array_filter(
                $allIssues,
                /** @param array<string, mixed> $issue */
                fn($issue) => ($issue['cycleStartsAt'] ?? '') === $cycle['date']
            );
            usort(
                $allIssuesByCycle[$key]['issues'],
                /** @param array<string, mixed> $a @param array<string, mixed> $b */
                function($a, $b) {
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
                }
            );
        }

        return $allIssuesByCycle;
    }

    /**
     * @param array<int, array{date: string, issues: array<int, array<string, mixed>>}> $issuesByCycle
     */
    private function formatIssuesForSlack(array $issuesByCycle): string
    {
        $blocks = [];

        foreach ($issuesByCycle as $cycle) {
            // Add date header
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*{$cycle['date']}*",
                ],
            ];

            // Add issues
            $issuesList = [];
            $count = 0;
            /** @var array<int, array<string, mixed>> $issues */
            $issues = $cycle['issues'];
            foreach ($issues as $issue) {
                $stateSymbol = isset($issue['stateSymbol']) ? $issue['stateSymbol'] : null;
                $symbol = is_string($stateSymbol) && $stateSymbol !== '' ? $stateSymbol.' ' : '';

                $identifier = isset($issue['identifier']) && (is_string($issue['identifier']) || is_numeric($issue['identifier'])) ? (string) $issue['identifier'] : '';
                $title = isset($issue['title']) && (is_string($issue['title']) || is_numeric($issue['title'])) ? (string) $issue['title'] : '';

                $issuesList[] = sprintf(
                    '%d. %s<%s|%s> - %s',
                    ++$count,
                    $symbol,
                    'https://linear.app/vacatia/issue/'.$identifier.'/',
                    $identifier,
                    $title
                );
            }

            if (!empty($issuesList)) {
                $blocks[] = [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => implode("\n", $issuesList),
                    ],
                ];
            }

            // Add divider between cycles
            //            $blocks[] = ['type' => 'divider'];
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
