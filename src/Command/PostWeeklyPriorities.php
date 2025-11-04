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
            ->addOption('slack-token', null, InputOption::VALUE_REQUIRED, 'Slack OAuth token (or set SLACK_OAUTH_TOKEN env var)')
            ->addOption('channel-id', null, InputOption::VALUE_REQUIRED, 'Slack channel ID (or set SLACK_CHANNEL_ID env var)')
            ->addOption('thread-ts', null, InputOption::VALUE_OPTIONAL, 'Thread timestamp to reply to (optional - will search for weekly message if not provided)')
            ->addOption('linear-api-key', null, InputOption::VALUE_REQUIRED, 'Linear API key (or set LINEAR_API_KEY env var)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be posted without actually posting')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get configuration
        $this->slackToken = $input->getOption('slack-token') ?? $_ENV['SLACK_OAUTH_TOKEN'] ?? '';
        $this->channelId = $input->getOption('channel-id') ?? $_ENV['SLACK_CHANNEL_ID'] ?? '';
        $this->linearApiKey = $input->getOption('linear-api-key') ?? $_ENV['LINEAR_API_KEY'] ?? '';
        $threadTs = $input->getOption('thread-ts');
        $isDryRun = $input->getOption('dry-run');

        if (!$this->slackToken || !$this->channelId || !$this->linearApiKey) {
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

            // Step 2: Find the weekly message thread if no thread_ts provided
            if (!$threadTs) {
                $io->section('Finding weekly message thread...');
                $threadTs = $this->findWeeklyMessageThread();

                if (!$threadTs) {
                    $io->warning('Could not find weekly message thread. Posting as new message.');
                }
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

    private function fetchLinearIssues(): array
    {
        $issuesQuery = <<<'GRAPHQL'
        query {
          user(id: "aa644b5e-8fd7-43b1-814f-71f8954eb4d6") {
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

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['data']['user'])) {
            throw new \RuntimeException('No user data found from Linear API.');
        }

        return $this->processLinearData($data['data']['user']);
    }

    private function processLinearData(array $user): array
    {
        $allIssues = array_map(
            fn(array $issue) => [
                'id' => $issue['id'],
                'identifier' => $issue['identifier'],
                'title' => $issue['title'],
                'url' => $issue['url'],
                'estimate' => $issue['estimate'] ?? null,
                'priority' => $issue['priority'],
                'createdAt' => isset($issue['createdAt']) ? (new \DateTimeImmutable($issue['createdAt']))->format('Y-m-d H:i:s') : null,
                'completedAt' => isset($issue['completedAt']) ? (new \DateTimeImmutable($issue['completedAt']))->format('Y-m-d H:i:s') : null,
                'cycleStartsAt' => isset($issue['cycle']['startsAt']) ? (new \DateTimeImmutable($issue['cycle']['startsAt']))->format('Y-m-d') : null,
                'isActive' => $issue['cycle']['isActive'] ?? null,
                'isNext' => $issue['cycle']['isNext'] ?? null,
                'isPrevious' => $issue['cycle']['isPrevious'] ?? null,
                'stateName' => $issue['state']['name'],
                'statePosition__c' => match ($issue['state']['name']) {
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
                'stateSymbol' => match ($issue['state']['name']) {
                    'Pending' => ':blocked:',
                    'In Progress' => ':loading:',
                    'In Review' => ':in_review:',
                    'Done' => ':done_2:',
                    'Canceled' => ':x:',
                    'Duplicate' => ':clown_face:',
                    default => null,
                },
            ],
            $user['assignedIssues']['nodes'] ?? []
        );

        $allIssuesByCycle = array_map(
            fn(string $date) => ['date' => $date],
            array_filter(array_unique(array_column($allIssues, 'cycleStartsAt')),
                function(?string $date) {
                    if (null === $date) {
                        return false;
                    }
                    return new \DateTimeImmutable($date) >= new \DateTimeImmutable('monday last week');
                }
            ),
        );

        sort($allIssuesByCycle);

        foreach ($allIssuesByCycle as $key => $cycle) {
            $allIssuesByCycle[$key]['issues'] = array_filter(
                $allIssues,
                fn($issue) => $issue['cycleStartsAt'] === $cycle['date']
            );
            usort(
                $allIssuesByCycle[$key]['issues'],
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
            foreach ($cycle['issues'] as $issue) {
                $symbol = $issue['stateSymbol'] ? $issue['stateSymbol'].' ' : '';
                $issuesList[] = sprintf(
                    '%d. %s<%s|%s> - %s',
                    ++$count,
                    $symbol,
                    'https://linear.app/vacatia/issue/'.$issue['identifier'].'/',
                    $issue['identifier'],
                    $issue['title']
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

        return json_encode(['blocks' => $blocks]);
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

        $data = json_decode($response->getBody()->getContents(), true);

        if (!$data['ok']) {
            throw new \RuntimeException('Failed to get channel history: '.($data['error'] ?? 'Unknown error'));
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
        $payload = json_decode($message, true);
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

        $data = json_decode($response->getBody()->getContents(), true);

        if (!$data['ok']) {
            throw new \RuntimeException('Failed to post to Slack: '.($data['error'] ?? 'Unknown error'));
        }

        return true;
    }
}
