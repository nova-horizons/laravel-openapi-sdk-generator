<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Laravel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use NovaHorizons\SdkGenerator\Config;
use NovaHorizons\SdkGenerator\ConsumerManifest;
use NovaHorizons\SdkGenerator\Generator;

final class GenerateSdkCommand extends Command
{
    protected $signature = 'sdk:generate
        {consumer? : Consumer key from config/sdk-generator.php}
        {--all : Generate for every configured consumer}
        {--fresh : Skip the pregenerate hook (use the spec as-is)}';

    protected $description = 'Generate a Laravel-native SDK from this project\'s OpenAPI spec into consumer projects';

    public function handle(): int
    {
        /** @var array<string, mixed> $consumers */
        $consumers = config('sdk-generator.consumers', []);

        $consumerArg = $this->argument('consumer');

        /** @var list<string> $targets */
        $targets = $this->option('all')
            ? array_keys($consumers)
            : (is_string($consumerArg) && $consumerArg !== '' ? [$consumerArg] : []);

        if ($targets === []) {
            $this->error('Pass a consumer key or --all. Configured: '.(implode(', ', array_keys($consumers)) ?: '(none)'));

            return self::FAILURE;
        }

        $pregenerate = config('sdk-generator.pregenerate');
        if (is_string($pregenerate) && ! $this->option('fresh')) {
            $this->info("Running {$pregenerate}...");
            $this->call($pregenerate);
        }

        $spec = config('sdk-generator.spec');
        if (! is_string($spec) || ! is_file($spec)) {
            $this->error('Spec not found: '.var_export($spec, true));

            return self::FAILURE;
        }

        $apiId = config('sdk-generator.id');
        if (! is_string($apiId) || $apiId === '') {
            $this->error("Set 'id' in config/sdk-generator.php — the key consumers file this API's SDK under in their composer.json \"extra.sdk-generator\" block (e.g. 'orbit').");

            return self::FAILURE;
        }

        foreach ($targets as $key) {
            if (! array_key_exists($key, $consumers)) {
                $this->error("Unknown consumer '{$key}'. Configured: ".(implode(', ', array_keys($consumers)) ?: '(none)'));

                return self::FAILURE;
            }

            $checkout = $this->resolveCheckout($key, $consumers[$key]);
            if ($checkout === null) {
                return self::FAILURE;
            }

            try {
                $manifest = ConsumerManifest::fromCheckout($checkout, $apiId);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $out = $checkout.'/'.$manifest->out;
            $this->info("Generating for '{$key}' => {$out}");

            $result = (new Generator(new Config(
                specPath: $spec,
                outputDir: $out,
                namespace: $manifest->namespace,
                clientClass: $manifest->client,
                configKey: $manifest->configKey,
                allow: $manifest->allow,
            )))->generate();

            foreach ($result->warnings as $warning) {
                $this->warn('  '.$warning);
            }
            $this->line('  '.count($result->files).' files written');

            $this->checkConsumerConfig($checkout, $result->clientClass, $result->configKey);
            $this->formatOutput($checkout, $manifest, $out);
        }

        return self::SUCCESS;
    }

    /**
     * A consumer's config value is the path to its checkout — absolute, or
     * relative to this project's root. Machine-specific paths belong in .env,
     * not in the committed config (see config/sdk-generator.php).
     */
    private function resolveCheckout(string $key, mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            $env = 'SDK_CONSUMER_'.strtoupper(str_replace('-', '_', $key));
            $this->error(
                "Consumer '{$key}' has no checkout path. Point it at the consumer's checkout via .env:\n"
                ."    config/sdk-generator.php:  '{$key}' => env('{$env}'),\n"
                ."    .env:                      {$env}=../consumer-checkout"
            );

            return null;
        }

        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        $real = realpath($absolute);
        if ($real === false || ! is_dir($real)) {
            $this->error("Consumer '{$key}' checkout not found: {$absolute}");

            return null;
        }

        return $real;
    }

    /**
     * Best-effort check that the consumer actually defines the config the
     * generated client reads. Static string search only — the consumer's app
     * is never booted — so this warns, never fails.
     */
    private function checkConsumerConfig(string $checkout, string $clientClass, string $configKey): void
    {
        $segments = explode('.', $configKey, 2);
        if (count($segments) !== 2) {
            return;
        }

        [$file, $tail] = $segments;
        $key = explode('.', $tail)[0];
        $configFile = "{$checkout}/config/{$file}.php";
        $contents = is_file($configFile) ? (string) file_get_contents($configFile) : '';

        if (! str_contains($contents, "'{$key}'") && ! str_contains($contents, "\"{$key}\"")) {
            $env = strtoupper(str_replace('-', '_', Str::snake($key)));
            $this->warn(
                "  Heads up: config/{$file}.php in the consumer doesn't define '{$key}' — app({$clientClass}::class) "
                ."will use the spec's default URL, or throw ConfigurationException if the spec has none. "
                ."Add: '{$key}' => ['url' => env('{$env}_URL'), 'api_key' => env('{$env}_API_KEY')]"
            );
        }
    }

    /**
     * Formatting runs the consumer's own toolchain, from the consumer's root:
     * an explicit "format" command from the manifest (php-cs-fixer, a
     * sail-wrapped invocation, anything), or vendor/bin/pint when present.
     */
    private function formatOutput(string $checkout, ConsumerManifest $manifest, string $outputPath): void
    {
        if ($manifest->format === false) {
            return;
        }

        if (is_string($manifest->format)) {
            $command = str_replace('{out}', escapeshellarg($manifest->out), $manifest->format);
            $this->line("  Formatting: {$command}");
            $result = Process::path($checkout)->timeout(300)->run($command);
            if ($result->failed()) {
                $this->warn('  Format command failed: '.trim($result->errorOutput()."\n".$result->output()));
            }

            return;
        }

        $pint = $checkout.'/vendor/bin/pint';
        if (! is_file($pint)) {
            $this->warn('  No vendor/bin/pint in the consumer — set "format" in its extra.sdk-generator block (e.g. "vendor/bin/php-cs-fixer fix {out}" or a sail-wrapped command), or "format": false to skip formatting');

            return;
        }

        $this->line("  Formatting with {$pint}");
        $result = Process::path($checkout)->timeout(300)->run([$pint, $outputPath]);
        if ($result->failed()) {
            $this->warn('  Pint failed: '.$result->errorOutput());
        }
    }
}
