<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Orbitron\LayoutState;
use Kinetis\Orbitron\ProjectLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the admitted layout is, and what every rejected one is called.
 * Each case runs against a real directory: the read is a real read.
 */
final class ProjectLayoutTest extends TestCase
{
    private const string SKELETON = <<<'JSON'
        {
            "autoload": {"psr-4": {"App\\": "src/"}},
            "autoload-dev": {"psr-4": {"App\\Tests\\": "tests/"}}
        }
        JSON;

    /** @var list<ProjectFixture> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->remove();
        }

        $this->fixtures = [];
    }

    private function fixture(?string $manifest): ProjectFixture
    {
        $fixture = new ProjectFixture($manifest);

        $this->fixtures[] = $fixture;

        return $fixture;
    }

    private function layoutFor(?string $manifest): ProjectLayout
    {
        return ProjectLayout::read($this->fixture($manifest)->root);
    }

    public function test_the_skeleton_layout_passes_every_check_and_reports_both_namespaces(): void
    {
        $layout = $this->layoutFor(self::SKELETON);

        self::assertFalse($layout->hasError());
        self::assertSame(
            [
                ['composerManifest', 'pass', 'manifest_read'],
                ['productionNamespace', 'pass', 'namespace_unique'],
                ['testNamespace', 'pass', 'namespace_unique'],
            ],
            array_map(
                static fn ($check): array => [$check->name, $check->state->value, $check->code],
                $layout->checks(),
            ),
        );
        self::assertSame(['production' => 'App\\', 'test' => 'App\\Tests\\'], $layout->namespaces());
    }

    /**
     * A project declares whatever else it needs. Only the two fixed paths
     * are read, and an unrelated mapping — array-valued included — is
     * left alone.
     */
    public function test_unrelated_additional_mappings_do_not_disturb_the_two_fixed_paths(): void
    {
        $layout = $this->layoutFor(<<<'JSON'
            {
                "autoload": {
                    "psr-4": {
                        "App\\": "src/",
                        "Vendor\\Toolkit\\": ["packages/toolkit/src/", "packages/toolkit/extra/"],
                        "Legacy\\": "lib/"
                    },
                    "files": ["src/helpers.php"]
                },
                "autoload-dev": {
                    "psr-4": {
                        "App\\Tests\\": "tests/",
                        "Vendor\\Toolkit\\Tests\\": ["packages/toolkit/tests/"]
                    }
                }
            }
            JSON);

        self::assertFalse($layout->hasError());
        self::assertSame(['production' => 'App\\', 'test' => 'App\\Tests\\'], $layout->namespaces());
    }

    public function test_a_missing_manifest_is_an_error_whose_dependent_checks_are_skipped(): void
    {
        $layout = $this->layoutFor(null);

        self::assertTrue($layout->hasError());
        self::assertSame(LayoutState::Error, $layout->manifest->state);
        self::assertSame('manifest_missing', $layout->manifest->code);

        // Skipped, not failed: no layout was ever seen, so nothing about
        // it is claimed either way.
        foreach ([$layout->production, $layout->test] as $check) {
            self::assertSame(LayoutState::Skip, $check->state);
            self::assertSame('manifest_unusable', $check->code);
        }

        self::assertNull($layout->namespaces());
    }

    public function test_a_manifest_that_cannot_be_opened_is_unreadable_rather_than_missing(): void
    {
        $fixture = $this->fixture(self::SKELETON);

        chmod($fixture->manifestPath(), 0o000);
        clearstatcache();

        if (is_readable($fixture->manifestPath())) {
            self::markTestSkipped('This process bypasses file permissions, so an unreadable file cannot be staged.');
        }

        $layout = ProjectLayout::read($fixture->root);

        self::assertSame('manifest_unreadable', $layout->manifest->code);
        self::assertSame(LayoutState::Skip, $layout->production->state);
    }

    /**
     * The admitted size is read whole; one byte more is refused. Nothing
     * between the two sizes is ever read, which is what makes the read
     * bounded rather than merely checked afterwards.
     */
    public function test_exactly_one_mebibyte_is_read_and_one_byte_more_is_refused(): void
    {
        $padded = static function (int $size): string {
            $manifest = self::SKELETON;

            return $manifest . str_repeat(' ', $size - strlen($manifest));
        };

        $accepted = $padded(ProjectLayout::MAX_MANIFEST_BYTES);

        self::assertSame(ProjectLayout::MAX_MANIFEST_BYTES, strlen($accepted));
        self::assertFalse($this->layoutFor($accepted)->hasError());

        $refused = $this->layoutFor($padded(ProjectLayout::MAX_MANIFEST_BYTES + 1));

        self::assertSame('manifest_oversize', $refused->manifest->code);
        self::assertSame(LayoutState::Skip, $refused->test->state);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableManifestProvider(): iterable
    {
        yield 'a truncated object' => ['{"autoload": {', 'manifest_not_json'];
        yield 'plain prose' => ['not json at all', 'manifest_not_json'];
        yield 'an empty file' => ['', 'manifest_not_json'];
        yield 'a JSON list' => ['[{"autoload": {"psr-4": {"App\\\\": "src/"}}}]', 'manifest_not_an_object'];
        yield 'an empty JSON list' => ['[]', 'manifest_not_an_object'];
        yield 'a JSON string' => ['"composer.json"', 'manifest_not_an_object'];
        yield 'a JSON number' => ['12', 'manifest_not_an_object'];
        yield 'JSON null' => ['null', 'manifest_not_an_object'];
    }

    #[DataProvider('unusableManifestProvider')]
    public function test_an_unusable_manifest_reports_its_own_code_and_skips_the_layout_checks(
        string $manifest,
        string $code,
    ): void {
        $layout = $this->layoutFor($manifest);

        self::assertTrue($layout->hasError());
        self::assertSame($code, $layout->manifest->code);
        self::assertSame(LayoutState::Skip, $layout->production->state);
        self::assertSame(LayoutState::Skip, $layout->test->state);
        self::assertNull($layout->namespaces());
    }

    /**
     * An empty JSON object is a manifest, not a parse failure: it is read,
     * and it is the PSR-4 maps that are missing from it.
     */
    public function test_an_empty_json_object_is_read_and_its_missing_maps_are_the_errors(): void
    {
        $layout = $this->layoutFor('{}');

        self::assertSame(LayoutState::Pass, $layout->manifest->state);
        self::assertSame('psr4_map_missing', $layout->production->code);
        self::assertSame('psr4_map_missing', $layout->test->code);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rejectedLayoutProvider(): iterable
    {
        yield 'no psr-4 map under autoload' => [
            '{"autoload": {"files": ["src/helpers.php"]}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'psr4_map_missing',
            'namespace_unique',
        ];

        yield 'a psr-4 map that is a list' => [
            '{"autoload": {"psr-4": []}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'path_unmapped',
            'namespace_unique',
        ];

        yield 'nothing mapped to the fixed path' => [
            '{"autoload": {"psr-4": {"App\\\\": "source/"}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'path_unmapped',
            'namespace_unique',
        ];

        yield 'two prefixes mapped to the fixed path' => [
            '{"autoload": {"psr-4": {"App\\\\": "src/", "Domain\\\\": "src/"}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'path_ambiguous',
            'namespace_unique',
        ];

        yield 'an array-valued mapping to the fixed path' => [
            '{"autoload": {"psr-4": {"App\\\\": ["src/"]}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'path_mapping_not_a_string',
            'namespace_unique',
        ];

        yield 'an array-valued mapping beside a valid one' => [
            '{"autoload": {"psr-4": {"App\\\\": "src/", "Domain\\\\": ["src/", "domain/"]}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'path_mapping_not_a_string',
            'namespace_unique',
        ];

        yield 'an unterminated namespace prefix' => [
            '{"autoload": {"psr-4": {"App": "src/"}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'namespace_invalid',
            'namespace_unique',
        ];

        yield 'an empty namespace prefix' => [
            '{"autoload": {"psr-4": {"": "src/"}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'namespace_invalid',
            'namespace_unique',
        ];

        yield 'a numeric namespace prefix' => [
            '{"autoload": {"psr-4": {"0": "src/"}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'namespace_invalid',
            'namespace_unique',
        ];

        yield 'a prefix that is not a PHP identifier' => [
            '{"autoload": {"psr-4": {"9Lives\\\\": "src/"}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/"}}}',
            'namespace_invalid',
            'namespace_unique',
        ];

        yield 'no autoload-dev section at all' => [
            '{"autoload": {"psr-4": {"App\\\\": "src/"}}}',
            'namespace_unique',
            'psr4_map_missing',
        ];

        yield 'a test namespace mapped to the wrong path' => [
            '{"autoload": {"psr-4": {"App\\\\": "src/"}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "test/"}}}',
            'namespace_unique',
            'path_unmapped',
        ];

        yield 'two prefixes mapped to the test path' => [
            '{"autoload": {"psr-4": {"App\\\\": "src/"}}, "autoload-dev": {"psr-4": {"App\\\\Tests\\\\": "tests/", "Acme\\\\Tests\\\\": "tests/"}}}',
            'namespace_unique',
            'path_ambiguous',
        ];
    }

    #[DataProvider('rejectedLayoutProvider')]
    public function test_a_rejected_layout_names_the_failing_check_and_reports_no_namespaces(
        string $manifest,
        string $productionCode,
        string $testCode,
    ): void {
        $layout = $this->layoutFor($manifest);

        self::assertTrue($layout->hasError());
        self::assertSame(LayoutState::Pass, $layout->manifest->state);
        self::assertSame($productionCode, $layout->production->code);
        self::assertSame($testCode, $layout->test->code);

        // One valid half is still not the admitted layout, so no
        // namespace is offered for a caller to build on.
        self::assertNull($layout->namespaces());
    }

    /**
     * The codes carry the outcome and nothing else: no path, no file
     * contents, no exception text.
     */
    public function test_no_diagnostic_echoes_the_manifest_or_its_path(): void
    {
        $fixture = $this->fixture('{"autoload": {"psr-4": {"Secret\\\\": "/srv/secret/"}}}');
        $layout = ProjectLayout::read($fixture->root);

        foreach ($layout->checks() as $check) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $check->code);
            self::assertStringNotContainsString($fixture->root, $check->code);
        }
    }
}
