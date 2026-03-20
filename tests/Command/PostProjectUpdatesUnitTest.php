<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PostProjectUpdates;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PostProjectUpdatesUnitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('LINEAR_API_KEY=test_linear_key');
        putenv('ANTHROPIC_API_KEY=test_anthropic_key');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('LINEAR_API_KEY');
        putenv('ANTHROPIC_API_KEY');
    }

    public function testMissingEnvVarsReturnFailure(): void
    {
        putenv('LINEAR_API_KEY');
        putenv('ANTHROPIC_API_KEY');

        $command = new PostProjectUpdates();
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Missing required', $output);
    }

    public function testDryRunOutputsGeneratedUpdateWithoutPosting(): void
    {
        $mockHandler = new MockHandler([
            // Projects query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'Project Alpha',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Previous update content.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query for project
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [
                                [
                                    'identifier' => 'ALPHA-1',
                                    'title' => 'Implement login',
                                    'state' => ['name' => 'Done'],
                                    'assignee' => ['name' => 'Alice'],
                                    'labels' => ['nodes' => [['name' => 'feature']]],
                                ],
                                [
                                    'identifier' => 'ALPHA-2',
                                    'title' => 'Fix logout bug',
                                    'state' => ['name' => 'In Progress'],
                                    'assignee' => ['name' => 'Bob'],
                                    'labels' => ['nodes' => []],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Claude API response
            new Response(200, [], (string) json_encode([
                'content' => [
                    ['type' => 'text', 'text' => json_encode([
                        'body' => "## Project Alpha Update\n\nLogin feature completed. Logout bug fix in progress.",
                        'health' => 'onTrack',
                    ])],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--dry-run' => true]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Project Alpha', $output);
        $this->assertStringContainsString('onTrack', $output);
        // Should have consumed all mock responses (no mutation call)
        $this->assertEquals(0, $mockHandler->count());
    }

    public function testSuccessfulPostReturnsSuccess(): void
    {
        $mockHandler = new MockHandler([
            // Projects query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'Project Alpha',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Previous update content.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [
                                [
                                    'identifier' => 'ALPHA-1',
                                    'title' => 'Implement login',
                                    'state' => ['name' => 'Done'],
                                    'assignee' => ['name' => 'Alice'],
                                    'labels' => ['nodes' => []],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Claude API response
            new Response(200, [], (string) json_encode([
                'content' => [
                    ['type' => 'text', 'text' => json_encode([
                        'body' => 'Login feature completed.',
                        'health' => 'onTrack',
                    ])],
                ],
            ])),
            // Linear mutation response
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projectUpdateCreate' => [
                        'success' => true,
                        'projectUpdate' => ['id' => 'update-1'],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully posted', $output);
        $this->assertEquals(0, $mockHandler->count());
    }

    public function testNoProjectsFoundReturnsSuccess(): void
    {
        $mockHandler = new MockHandler([
            // Projects query returns empty
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No in-progress projects', $output);
    }

    public function testNoPreviousUpdateFallsBackToSevenDays(): void
    {
        $mockHandler = new MockHandler([
            // Projects query - no previous updates
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'New Project',
                                'projectUpdates' => [
                                    'nodes' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [
                                [
                                    'identifier' => 'NEW-1',
                                    'title' => 'Setup repo',
                                    'state' => ['name' => 'Done'],
                                    'assignee' => ['name' => 'Alice'],
                                    'labels' => ['nodes' => []],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Claude API response
            new Response(200, [], (string) json_encode([
                'content' => [
                    ['type' => 'text', 'text' => json_encode([
                        'body' => 'Repo setup completed.',
                        'health' => 'onTrack',
                    ])],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--dry-run' => true]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        // All 3 mock responses consumed (projects + issues + claude)
        $this->assertEquals(0, $mockHandler->count());
    }

    public function testNoIssuesUpdatedPostsStaticMessageWithoutClaudeCall(): void
    {
        $mockHandler = new MockHandler([
            // Projects query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'Quiet Project',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Last week we made good progress.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query returns empty
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--dry-run' => true]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Quiet Project', $output);
        $this->assertStringContainsString('onTrack', $output);
        // Should NOT contain the planning message
        $this->assertStringNotContainsString('planning', $output);
        // Only 2 responses consumed (projects + issues), no Claude call
        $this->assertEquals(0, $mockHandler->count());
    }

    public function testClaudeReturnsAtRiskHealth(): void
    {
        $mockHandler = new MockHandler([
            // Projects query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'Risky Project',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Previous update content.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [
                                [
                                    'identifier' => 'RISK-1',
                                    'title' => 'Blocked by dependency',
                                    'state' => ['name' => 'In Progress'],
                                    'assignee' => ['name' => 'Bob'],
                                    'labels' => ['nodes' => [['name' => 'blocked']]],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Claude API returns atRisk
            new Response(200, [], (string) json_encode([
                'content' => [
                    ['type' => 'text', 'text' => json_encode([
                        'body' => 'Blocked by external dependency.',
                        'health' => 'atRisk',
                    ])],
                ],
            ])),
            // Linear mutation
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projectUpdateCreate' => [
                        'success' => true,
                        'projectUpdate' => ['id' => 'update-1'],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('atRisk', $output);
    }

    public function testHttpErrorReturnsFailure(): void
    {
        $mockHandler = new MockHandler([
            new ConnectException('Network error', new Request('POST', 'https://api.linear.app/graphql')),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Network error', $output);
    }

    public function testMalformedClaudeResponseFallsBack(): void
    {
        $mockHandler = new MockHandler([
            // Projects query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'Project Beta',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Previous update content.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [
                                [
                                    'identifier' => 'BETA-1',
                                    'title' => 'Some task',
                                    'state' => ['name' => 'In Progress'],
                                    'assignee' => ['name' => 'Charlie'],
                                    'labels' => ['nodes' => []],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Claude API returns non-JSON
            new Response(200, [], (string) json_encode([
                'content' => [
                    ['type' => 'text', 'text' => 'Here is a plain text response without JSON'],
                ],
            ])),
            // Linear mutation
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projectUpdateCreate' => [
                        'success' => true,
                        'projectUpdate' => ['id' => 'update-1'],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // Should fall back to using raw text as body with onTrack health
        $this->assertStringContainsString('onTrack', $output);
    }

    public function testSkipsProjectWithRecentUpdate(): void
    {
        $recentDate = new \DateTimeImmutable('-2 days')->format('Y-m-d\TH:i:s.v\Z');

        $mockHandler = new MockHandler([
            // Projects query — last update was 2 days ago
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'Recently Updated',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => $recentDate],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Skipping', $output);
        $this->assertStringContainsString('Recently Updated', $output);
        // Only 1 response consumed (projects query), no issues/claude/mutation calls
        $this->assertEquals(0, $mockHandler->count());
    }

    public function testNewProjectWithNoIssuesGetsPlanningMessage(): void
    {
        $mockHandler = new MockHandler([
            // Projects query — no previous updates
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'Brand New Project',
                                'projectUpdates' => [
                                    'nodes' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query returns empty
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--dry-run' => true]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('planning', $output);
        $this->assertEquals(0, $mockHandler->count());
    }

    public function testTemplateFallbackWhenAnthropicKeyMissing(): void
    {
        putenv('ANTHROPIC_API_KEY');

        $mockHandler = new MockHandler([
            // Projects query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'No Key Project',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Previous update content.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [
                                [
                                    'identifier' => 'NOKEY-1',
                                    'title' => 'Some work',
                                    'state' => ['name' => 'Done'],
                                    'assignee' => ['name' => 'Alice'],
                                    'labels' => ['nodes' => []],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--dry-run' => true]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // Should use template fallback (mentions completed items)
        $this->assertStringContainsString('Completed 1 item', $output);
        // Should warn about missing key
        $this->assertStringContainsString('ANTHROPIC_API_KEY not set', $output);
        // Only 2 responses consumed (projects + issues), no Claude call
        $this->assertEquals(0, $mockHandler->count());
    }

    public function testEmptyClaudeResponseFallsBackToTemplate(): void
    {
        $mockHandler = new MockHandler([
            // Projects query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-1',
                                'name' => 'Empty Response Project',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Previous update content.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [
                                [
                                    'identifier' => 'EMPTY-1',
                                    'title' => 'A task',
                                    'state' => ['name' => 'In Progress'],
                                    'assignee' => ['name' => 'Bob'],
                                    'labels' => ['nodes' => []],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Claude API returns empty content
            new Response(200, [], (string) json_encode([
                'content' => [],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--dry-run' => true]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // Should fall back to template
        $this->assertStringContainsString('template fallback', $output);
        $this->assertStringContainsString('in progress', $output);
    }

    public function testOneProjectFailureDoesNotAbortOthers(): void
    {
        $mockHandler = new MockHandler([
            // Projects query — two projects
            new Response(200, [], (string) json_encode([
                'data' => [
                    'projects' => [
                        'nodes' => [
                            [
                                'id' => 'proj-bad',
                                'name' => 'Failing Project',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Previous update content.'],
                                    ],
                                ],
                            ],
                            [
                                'id' => 'proj-good',
                                'name' => 'Good Project',
                                'projectUpdates' => [
                                    'nodes' => [
                                        ['createdAt' => '2026-03-11T00:00:00Z', 'body' => 'Previous update content.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
            // Issues query for first project — network error
            new ConnectException('Connection refused', new Request('POST', 'https://api.linear.app/graphql')),
            // Issues query for second project — success with no issues
            new Response(200, [], (string) json_encode([
                'data' => [
                    'project' => [
                        'issues' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $command = new PostProjectUpdates($mockClient);
        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--dry-run' => true]);

        // Should return FAILURE because one project failed
        $this->assertEquals(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // First project should show error
        $this->assertStringContainsString('Failed to process Failing Project', $output);
        // Second project should still be processed
        $this->assertStringContainsString('Good Project', $output);
    }
}
