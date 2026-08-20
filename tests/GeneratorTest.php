<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Tests;

use NovaHorizons\SdkGenerator\Config;
use NovaHorizons\SdkGenerator\Generator;
use NovaHorizons\SdkGenerator\SpecViolation;
use NovaHorizons\SdkGenerator\SpecViolationsException;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir().'/sdkgen-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->out)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->out, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->out);
        }
    }

    public function test_generates_gizmo_sdk(): void
    {
        $result = (new Generator(new Config(
            specPath: __DIR__.'/fixtures/gizmo-api.json',
            outputDir: $this->out,
            namespace: 'Gizmo\\Sdk',
            clientClass: 'GizmoClient',
            allow: [SpecViolation::MISSING_ERROR_SCHEMA, SpecViolation::UNTYPED_RESPONSE],
        )))->generate();

        $this->assertContains('GizmoClient.php', $result->files);
        $this->assertContains('Omitted.php', $result->files);
        $this->assertContains('Dto/Widget.php', $result->files);
        $this->assertContains('Dto/WidgetAlert.php', $result->files);
        $this->assertContains('Resources/WidgetsResource.php', $result->files);

        // Legacy-DB naming rule: strip leading underscores, camelCase on `_`, keep embedded caps
        $widget = (string) file_get_contents($this->out.'/Dto/Widget.php');
        $this->assertStringContainsString('public ?int $recWidgetSN', $widget);
        $this->assertStringContainsString("\$data['__rec_WidgetSN']", $widget);
        $this->assertStringContainsString('public ?string $calcAssemblyCodeID', $widget);

        // allOf flattening: WidgetAlert = Widget + alert fields
        $alert = (string) file_get_contents($this->out.'/Dto/WidgetAlert.php');
        $this->assertStringContainsString('alertType', $alert);
        $this->assertStringContainsString('recWidgetSN', $alert);

        // Sentinel style on request-only update DTO
        $update = (string) file_get_contents($this->out.'/Dto/WidgetUpdate.php');
        $this->assertStringContainsString('Omitted::Value', $update);

        // Lazy pagination on offset/limit list endpoints
        $widgets = (string) file_get_contents($this->out.'/Resources/WidgetsResource.php');
        $this->assertStringContainsString('public function searchWidgetsLazy(', $widgets);
        $this->assertStringContainsString('LazyCollection::make', $widgets);

        // Testing fakes
        $this->assertContains('Testing/GizmoFake.php', $result->files);
        $fake = (string) file_get_contents($this->out.'/Testing/GizmoFake.php');
        $this->assertStringContainsString('public static function widget(array $overrides = []): array', $fake);
        $this->assertStringContainsString("SEARCH_WIDGETS = '*/widgets/search'", $fake);

        // Container attributes on the client; trustworthy spec servers[0] is the default URL
        $client = (string) file_get_contents($this->out.'/GizmoClient.php');
        $this->assertStringContainsString('#[Singleton]', $client);
        $this->assertStringContainsString("#[Config('services.gizmo.url', 'https://api.example.com/v1')]", $client);
        $this->assertStringContainsString("string \$baseUrl = 'https://api.example.com/v1',", $client);

        // Everything parses
        foreach ($result->files as $file) {
            $output = shell_exec('php -l '.escapeshellarg($this->out.'/'.$file).' 2>&1');
            $this->assertStringContainsString('No syntax errors', (string) $output, $file);
        }
    }

    public function test_strict_mode_rejects_undocumented_error_bodies(): void
    {
        $this->expectException(SpecViolationsException::class);
        $this->expectExceptionMessageMatches('/missing-error-schema/');

        (new Generator(new Config(
            specPath: __DIR__.'/fixtures/gizmo-api.json',
            outputDir: $this->out,
            namespace: 'Gizmo\\Sdk',
        )))->generate();
    }

    public function test_deprecated_flows_through(): void
    {
        (new Generator(new Config(
            specPath: __DIR__.'/fixtures/errdemo-spec.json',
            outputDir: $this->out,
            namespace: 'Err\\Sdk',
            clientClass: 'ErrClient',
        )))->generate();

        $resource = (string) file_get_contents($this->out.'/Resources/ThingsResource.php');
        $this->assertStringContainsString('@deprecated per the OpenAPI spec', $resource);

        $dto = (string) file_get_contents($this->out.'/Dto/Thing.php');
        $this->assertStringContainsString('@deprecated per the OpenAPI spec', $dto);

        $this->assertFileExists($this->out.'/README.md');
    }

    public function test_generates_exception_hierarchy(): void
    {
        $result = (new Generator(new Config(
            specPath: __DIR__.'/fixtures/errdemo-spec.json',
            outputDir: $this->out,
            namespace: 'Err\\Sdk',
            clientClass: 'ErrClient',
        )))->generate();

        $this->assertContains('Exceptions/ErrException.php', $result->files);
        $this->assertContains('Exceptions/RequestException.php', $result->files);
        $this->assertContains('Exceptions/ConnectionException.php', $result->files);
        $this->assertContains('Exceptions/UnexpectedResponseException.php', $result->files);
        $this->assertContains('Exceptions/ConfigurationException.php', $result->files);
        $this->assertContains('Resources/Resource.php', $result->files);

        // Localhost servers[0] is rejected as a default URL (with a nudge)...
        $this->assertNotEmpty(array_filter($result->warnings, fn (string $w): bool => str_contains($w, 'not a usable default base URL')));

        // ...so the client has no default and fails loudly when unconfigured
        $client = (string) file_get_contents($this->out.'/ErrClient.php');
        $this->assertStringContainsString("#[Config('services.err.url', '')]", $client);
        $this->assertStringContainsString('throw new ConfigurationException(', $client);

        // Documented error bodies => typed error() accessor
        $requestException = (string) file_get_contents($this->out.'/Exceptions/RequestException.php');
        $this->assertStringContainsString('public function error(): ?Error', $requestException);

        // Resources route through the send() wrapper
        $resource = (string) file_get_contents($this->out.'/Resources/ThingsResource.php');
        $this->assertStringContainsString('$this->send(fn (): Response =>', $resource);
        $this->assertStringContainsString('extends Resource', $resource);
    }

    public function test_no_error_accessor_without_documented_error_bodies(): void
    {
        $result = (new Generator(new Config(
            specPath: __DIR__.'/fixtures/gizmo-api.json',
            outputDir: $this->out,
            namespace: 'Gizmo\\Sdk',
            allow: [SpecViolation::MISSING_ERROR_SCHEMA, SpecViolation::UNTYPED_RESPONSE],
        )))->generate();

        $this->assertContains('Exceptions/RequestException.php', $result->files);
        $requestException = (string) file_get_contents($this->out.'/Exceptions/RequestException.php');
        $this->assertStringNotContainsString('function error(', $requestException);
    }

    public function test_generates_orbit_sdk(): void
    {
        $result = (new Generator(new Config(
            specPath: __DIR__.'/fixtures/orbit-api.json',
            outputDir: $this->out,
            namespace: 'Orbit\\Sdk',
        )))->generate();

        // The Orbit spec is strict-clean and documents error bodies
        $this->assertSame([], $result->warnings);
        $requestException = (string) file_get_contents($this->out.'/Exceptions/RequestException.php');
        $this->assertStringContainsString('public function error(): ?Error', $requestException);

        // Client class derived from the spec title
        $this->assertContains('OrbitClient.php', $result->files);

        // Property enum in a request body => native backed enum
        $this->assertContains('Enums/LaunchPlanCadence.php', $result->files);

        // Inline response hoisting, nested child included
        $this->assertContains('Dto/TelemetrySnapshotResponse.php', $result->files);
        $this->assertContains('Dto/TelemetrySnapshotResponseSignal.php', $result->files);

        // additionalProperties map
        $usage = (string) file_get_contents($this->out.'/Dto/UsageReport.php');
        $this->assertStringContainsString('array<string, int>', $usage);

        // Fake factories seed from spec example values
        $fake = (string) file_get_contents($this->out.'/Testing/OrbitFake.php');
        $this->assertStringContainsString("'callsign' => 'AURORA-7'", $fake);

        // Single-variant oneOf unwraps to a nullable ref
        $beacon = (string) file_get_contents($this->out.'/Dto/Beacon.php');
        $this->assertStringContainsString('public ?GeoFix $lastFix', $beacon);

        // Everything parses
        foreach ($result->files as $file) {
            $output = shell_exec('php -l '.escapeshellarg($this->out.'/'.$file).' 2>&1');
            $this->assertStringContainsString('No syntax errors', (string) $output, $file);
        }
    }
}
