<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Http\Routing\Exception\DuplicateRouteException;
use Kinetis\Orbitron\HealthScaffold;
use Kinetis\Orbitron\ScaffoldStatus;
use Kinetis\Testing\ApplicationTestCase;
use Kinetis\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/**
 * What the two generated files do once they are on disk, through the
 * framework's own discovery, container, kernel and test client rather
 * than through anything Orbitron owns.
 */
final class GeneratedHealthEndpointTest extends TestCase
{
    /** @var list<ScaffoldProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->remove();
        }

        $this->projects = [];
    }

    private function applied(): ScaffoldProject
    {
        $project = new ScaffoldProject();

        $this->projects[] = $project;

        self::assertSame(ScaffoldStatus::Created, (new HealthScaffold())->apply($project->root)->status);

        $project->autoload();

        return $project;
    }

    public function test_the_generated_route_answers_the_same_document_on_two_sequential_requests(): void
    {
        $client = TestApplication::boot($this->applied()->root)->client();

        // Two requests through one resident application, which is what
        // the generated test itself issues.
        foreach ([1, 2] as $ignored) {
            $response = $client->get('/health');

            $response->assertOk()->assertHeader('Content-Type', 'application/json');

            self::assertSame('{"status":"ok"}', $response->body());
        }
    }

    public function test_the_generated_test_extends_the_frameworks_own_application_test_case(): void
    {
        $project = $this->applied();

        self::assertTrue(is_subclass_of($project->test . 'Http\\HealthControllerTest', ApplicationTestCase::class));
    }

    /**
     * Orbitron reads no application source, so a `GET /health` already
     * declared elsewhere is invisible to the scaffold and the apply
     * succeeds. Route discovery is what refuses the pair.
     */
    public function test_a_route_the_project_already_declares_is_surfaced_by_route_discovery(): void
    {
        $project = new ScaffoldProject();

        $this->projects[] = $project;

        $namespace = rtrim($project->production, '\\') . '\\Http';

        file_put_contents($project->path('src/Http/StatusController.php'), <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Kinetis\\Http\\Attributes\\Get;

        final readonly class StatusController
        {
            #[Get('/health')]
            public function show(): array
            {
                return ['status' => 'already here'];
            }
        }

        PHP);

        self::assertSame(ScaffoldStatus::Created, (new HealthScaffold())->apply($project->root)->status);

        $project->autoload();

        $this->expectException(DuplicateRouteException::class);

        TestApplication::boot($project->root);
    }
}
