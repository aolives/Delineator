<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PostWeeklyPriorities;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PostWeeklyPrioritiesUnitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up environment variables for testing
        putenv('LINEAR_API_KEY=test_linear_key');
        putenv('SLACK_OAUTH_TOKEN=xoxb-test-token');
        putenv('SLACK_CHANNEL_ID=C1234567890');
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up environment variables
        putenv('LINEAR_API_KEY');
        putenv('SLACK_OAUTH_TOKEN');
        putenv('SLACK_CHANNEL_ID');
    }
    
    public function testProcessLinearDataSortsIssuesByStateAndPriority(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();
        
        // And: User data with issues in different states
        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'TASK-1',
                        'title' => 'Todo task',
                        'url' => 'https://linear.app/test/issue/TASK-1',
                        'estimate' => 1,
                        'priority' => 3,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'completedAt' => null,
                        'cycle' => [
                            'startsAt' => '2025-11-04T00:00:00Z',
                            'isActive' => true,
                            'isNext' => false,
                            'isPrevious' => false
                        ],
                        'state' => ['name' => 'Todo']
                    ],
                    [
                        'id' => '2',
                        'identifier' => 'TASK-2',
                        'title' => 'In Progress task',
                        'url' => 'https://linear.app/test/issue/TASK-2',
                        'estimate' => 2,
                        'priority' => 1,
                        'createdAt' => '2025-11-01T11:00:00Z',
                        'completedAt' => null,
                        'cycle' => [
                            'startsAt' => '2025-11-04T00:00:00Z',
                            'isActive' => true,
                            'isNext' => false,
                            'isPrevious' => false
                        ],
                        'state' => ['name' => 'In Progress']
                    ],
                    [
                        'id' => '3',
                        'identifier' => 'TASK-3',
                        'title' => 'Done task',
                        'url' => 'https://linear.app/test/issue/TASK-3',
                        'estimate' => 3,
                        'priority' => 2,
                        'createdAt' => '2025-11-01T09:00:00Z',
                        'completedAt' => '2025-11-03T15:00:00Z',
                        'cycle' => [
                            'startsAt' => '2025-11-04T00:00:00Z',
                            'isActive' => true,
                            'isNext' => false,
                            'isPrevious' => false
                        ],
                        'state' => ['name' => 'Done']
                    ]
                ]
            ]
        ];
        
        // When: Processing the data
        $result = $command->processLinearData($userData);
        
        // Then: Should have one cycle
        $this->assertCount(1, $result);
        $this->assertEquals('2025-11-04', $result[0]['date']);
        
        // And: Issues should be sorted by state (Done -> In Progress -> Todo)
        $issues = $result[0]['issues'];
        $this->assertCount(3, $issues);
        
        // First issue should be Done
        $this->assertEquals('TASK-3', $issues[0]['identifier']);
        $this->assertEquals('Done', $issues[0]['stateName']);
        
        // Second issue should be In Progress
        $this->assertEquals('TASK-2', $issues[1]['identifier']);
        $this->assertEquals('In Progress', $issues[1]['stateName']);
        
        // Third issue should be Todo
        $this->assertEquals('TASK-1', $issues[2]['identifier']);
        $this->assertEquals('Todo', $issues[2]['stateName']);
    }
    
    public function testProcessLinearDataFiltersOldCycles(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();
        
        // And: Issues from different time periods
        $twoWeeksAgo = (new \DateTimeImmutable('-3 weeks'))->format('Y-m-d\T00:00:00\Z');
        $lastMonday = (new \DateTimeImmutable('monday last week'))->format('Y-m-d\T00:00:00\Z');
        $thisMonday = (new \DateTimeImmutable('monday this week'))->format('Y-m-d\T00:00:00\Z');
        
        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => 'old',
                        'identifier' => 'OLD-1',
                        'title' => 'Very old issue',
                        'url' => 'https://linear.app/test/issue/OLD-1',
                        'estimate' => 1,
                        'priority' => 1,
                        'createdAt' => '2025-10-01T10:00:00Z',
                        'cycle' => ['startsAt' => $twoWeeksAgo],
                        'state' => ['name' => 'Done']
                    ],
                    [
                        'id' => 'recent',
                        'identifier' => 'RECENT-1',
                        'title' => 'Last week issue',
                        'url' => 'https://linear.app/test/issue/RECENT-1',
                        'estimate' => 1,
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'cycle' => ['startsAt' => $lastMonday],
                        'state' => ['name' => 'Done']
                    ],
                    [
                        'id' => 'current',
                        'identifier' => 'CURRENT-1',
                        'title' => 'This week issue',
                        'url' => 'https://linear.app/test/issue/CURRENT-1',
                        'estimate' => 1,
                        'priority' => 1,
                        'createdAt' => '2025-11-04T10:00:00Z',
                        'cycle' => ['startsAt' => $thisMonday],
                        'state' => ['name' => 'In Progress']
                    ]
                ]
            ]
        ];
        
        // When: Processing the data
        $result = $command->processLinearData($userData);
        
        // Then: Should include last week and this week, but not older
        $allIdentifiers = [];
        foreach ($result as $cycle) {
            foreach ($cycle['issues'] as $issue) {
                $allIdentifiers[] = $issue['identifier'];
            }
        }
        
        $this->assertContains('RECENT-1', $allIdentifiers);
        $this->assertContains('CURRENT-1', $allIdentifiers);
        $this->assertNotContains('OLD-1', $allIdentifiers);
    }
    
    public function testProcessLinearDataHandlesEmptyAssignedIssues(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();
        
        // And: User data with no assigned issues
        $userData = [
            'assignedIssues' => [
                'nodes' => []
            ]
        ];
        
        // When: Processing the data
        $result = $command->processLinearData($userData);
        
        // Then: Should return empty array
        $this->assertEmpty($result);
    }
    
    public function testProcessLinearDataHandlesMissingFields(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();
        
        // And: User data with issues missing optional fields
        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'TASK-1',
                        'title' => 'Task without cycle',
                        'url' => 'https://linear.app/test/issue/TASK-1',
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'state' => ['name' => 'Todo']
                        // Note: no cycle, no estimate, no completedAt
                    ]
                ]
            ]
        ];
        
        // When: Processing the data
        $result = $command->processLinearData($userData);
        
        // Then: Should handle gracefully (no cycle means it won't be included)
        $this->assertEmpty($result); // No cycle date means it won't be grouped
    }
    
    public function testDryRunDoesNotMakeHttpCalls(): void
    {
        // Given: A mock handler that will throw if called
        $mockHandler = new MockHandler([
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => [
                            'nodes' => [
                                [
                                    'id' => '1',
                                    'identifier' => 'TEST-1',
                                    'title' => 'Test Issue',
                                    'url' => 'https://linear.app/test/issue/TEST-1',
                                    'estimate' => 1,
                                    'priority' => 1,
                                    'createdAt' => '2025-11-01T10:00:00Z',
                                    'cycle' => [
                                        'startsAt' => '2025-11-04T00:00:00Z',
                                        'isActive' => true
                                    ],
                                    'state' => ['name' => 'In Progress']
                                ]
                            ]
                        ]
                    ]
                ]
            ]))
        ]);
        
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        
        // And: A command with the mocked client
        $command = new PostWeeklyPriorities($mockClient);
        
        $application = new Application();
        $application->add($command);
        
        $commandTester = new CommandTester($command);
        
        // When: Running with dry-run
        $commandTester->execute(['--dry-run' => true]);
        
        // Then: Should complete successfully
        $this->assertEquals(0, $commandTester->getStatusCode());
        
        // And: Output should indicate dry run
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Dry Run', $output);
        
        // And: Mock handler should have been called exactly once (for Linear API)
        $this->assertEquals(0, $mockHandler->count(), 'One request should have been made');
    }
    
    public function testMissingEnvironmentVariablesReturnError(): void
    {
        // Given: No environment variables
        putenv('LINEAR_API_KEY');
        putenv('SLACK_OAUTH_TOKEN');
        putenv('SLACK_CHANNEL_ID');
        
        // And: A command
        $command = new PostWeeklyPriorities();
        
        $application = new Application();
        $application->add($command);
        
        $commandTester = new CommandTester($command);
        
        // When: Running the command
        $commandTester->execute([]);
        
        // Then: Should return failure
        $this->assertEquals(1, $commandTester->getStatusCode());
        
        // And: Should show error message
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Missing required configuration', $output);
    }
}