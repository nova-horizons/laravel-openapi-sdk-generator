<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Laravel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use NovaHorizons\SdkGenerator\Config;
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
        /** @var array<string, array{path: string, namespace: string, client?: string, pint?: bool, config_key?: string, allow?: list<string>}> $consumers */
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

        foreach ($targets as $key) {
            $consumer = $consumers[$key] ?? null;
            if ($consumer === null) {
                $this->error("Unknown consumer '{$key}'");

                return self::FAILURE;
            }

            $this->info("Generating for '{$key}' => {$consumer['path']}");

            $result = (new Generator(new Config(
                specPath: $spec,
                outputDir: $consumer['path'],
                namespace: $consumer['namespace'],
                clientClass: $consumer['client'] ?? null,
                configKey: $consumer['config_key'] ?? null,
                allow: $consumer['allow'] ?? [],
            )))->generate();

            foreach ($result->warnings as $warning) {
                $this->warn('  '.$warning);
            }
            $this->line('  '.count($result->files).' files written');

            if ($consumer['pint'] ?? true) {
                $this->formatWithConsumerPint($consumer['path']);
            }
        }

        return self::SUCCESS;
    }

    private function formatWithConsumerPint(string $outputPath): void
    {
        // Walk up from the output dir to find the consumer's own Pint.
        $dir = realpath($outputPath) ?: $outputPath;
        while ($dir !== dirname($dir)) {
            $pint = $dir.'/vendor/bin/pint';
            if (is_file($pint)) {
                $this->line("  Formatting with {$pint}");
                $result = Process::path($dir)->run([$pint, $outputPath]);
                if ($result->failed()) {
                    $this->warn('  Pint failed: '.$result->errorOutput());
                }

                return;
            }
            $dir = dirname($dir);
        }

        $this->warn('  No consumer vendor/bin/pint found — skipping format step');
    }
}
