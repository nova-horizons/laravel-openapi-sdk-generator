<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Tests;

use NovaHorizons\SdkGenerator\ConsumerManifest;
use PHPUnit\Framework\TestCase;

final class ConsumerManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sdkgen-manifest-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/composer.json');
        @rmdir($this->dir);
    }

    private function writeComposerJson(string $extraBlock): void
    {
        file_put_contents($this->dir.'/composer.json', <<<JSON
            {
                "name": "acme/billing-app",
                "require": {"php": "^8.3"},
                "extra": {$extraBlock}
            }
            JSON);
    }

    public function test_parses_full_manifest(): void
    {
        $this->writeComposerJson(<<<'JSON'
            {"sdk-generator": {"orbit": {
                "namespace": "App\\Sdk\\Orbit",
                "out": "app/Sdk/Orbit/",
                "client": "OrbitClient",
                "config-key": "services.orbit",
                "allow": ["missing-error-schema", "untyped-response"],
                "format": "vendor/bin/sail php vendor/bin/php-cs-fixer fix {out}"
            }}}
            JSON);

        $manifest = ConsumerManifest::fromCheckout($this->dir, 'orbit');

        $this->assertSame('App\\Sdk\\Orbit', $manifest->namespace);
        $this->assertSame('app/Sdk/Orbit', $manifest->out);
        $this->assertSame('OrbitClient', $manifest->client);
        $this->assertSame('services.orbit', $manifest->configKey);
        $this->assertSame(['missing-error-schema', 'untyped-response'], $manifest->allow);
        $this->assertSame('vendor/bin/sail php vendor/bin/php-cs-fixer fix {out}', $manifest->format);
    }

    public function test_minimal_manifest_gets_defaults(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/Sdk/Orbit"}}}');

        $manifest = ConsumerManifest::fromCheckout($this->dir, 'orbit');

        $this->assertNull($manifest->client);
        $this->assertNull($manifest->configKey);
        $this->assertSame([], $manifest->allow);
        $this->assertNull($manifest->format);
    }

    public function test_format_false_disables_formatting(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/Sdk/Orbit", "format": false}}}');

        $this->assertFalse(ConsumerManifest::fromCheckout($this->dir, 'orbit')->format);
    }

    public function test_non_string_format_rejected(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/Sdk/Orbit", "format": true}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"format" must be a shell command string');

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }

    public function test_missing_composer_json_names_the_checkout(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No composer.json found');

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }

    public function test_missing_extra_block_shows_an_example(): void
    {
        $this->writeComposerJson('{"laravel": {"providers": []}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"extra": {"sdk-generator"');

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }

    public function test_unknown_api_id_lists_defined_ids(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/Sdk/Orbit"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no "extra.sdk-generator.gizmo" entry (defined: orbit)');

        ConsumerManifest::fromCheckout($this->dir, 'gizmo');
    }

    public function test_flat_unkeyed_block_rejected(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/Sdk/Orbit"}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be keyed by API id');

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }

    public function test_missing_namespace_rejected(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"out": "app/Sdk/Orbit"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"extra.sdk-generator.orbit.namespace" is required');

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }

    public function test_absolute_out_rejected(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "/etc/sdk"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('relative to the consumer root');

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }

    public function test_parent_traversal_in_out_rejected(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/../../elsewhere"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without ".."');

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }

    public function test_unknown_key_rejected(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/Sdk", "namespcae": "typo"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown "extra.sdk-generator.orbit" key(s) namespcae');

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }

    public function test_unknown_allow_rule_rejected(): void
    {
        $this->writeComposerJson('{"sdk-generator": {"orbit": {"namespace": "App\\\\Sdk\\\\Orbit", "out": "app/Sdk", "allow": ["missing-eror-schema"]}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("unknown allow rule 'missing-eror-schema'");

        ConsumerManifest::fromCheckout($this->dir, 'orbit');
    }
}
