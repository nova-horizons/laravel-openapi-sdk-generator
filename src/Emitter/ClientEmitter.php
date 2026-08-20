<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\PhpFile;
use NovaHorizons\SdkGenerator\Ir\ApiDef;
use NovaHorizons\SdkGenerator\Names;

/**
 * Emits the client. Container-friendly without a service provider:
 *
 *   #[Singleton] on the class plus #[Config] attributes on the constructor
 *   mean `app(OrbitClient::class)` resolves configured and shared — no
 *   binding, no provider. `make()` and `fromPendingRequest()` cover manual
 *   construction and tests.
 */
final readonly class ClientEmitter
{
    public function __construct(
        private string $namespace,
        private string $clientClass,
        private string $defaultApiKeyHeader,
        private string $configKey,
    ) {}

    public function emit(ApiDef $api): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace);
        $namespace->addUse('Illuminate\\Container\\Attributes\\Config');
        $namespace->addUse('Illuminate\\Container\\Attributes\\Singleton');
        $namespace->addUse('Illuminate\\Http\\Client\\ConnectionException');
        $namespace->addUse('Illuminate\\Http\\Client\\PendingRequest');
        $namespace->addUse('Illuminate\\Http\\Client\\RequestException');
        $namespace->addUse('Illuminate\\Support\\Facades\\Http');

        $namespace->addUse($this->namespace.'\\Exceptions\\ConfigurationException');

        $class = $namespace->addClass($this->clientClass);
        $class->setFinal();
        $class->addComment("Client for {$api->title} (spec version {$api->version}).\n");
        $class->addComment("Resolve via the container — `app({$this->clientClass}::class)` — to read");
        $class->addComment("config('{$this->configKey}.url'), .api_key, .timeout (seconds) and .retries,");
        $class->addComment('or construct explicitly with make() / fromPendingRequest().');
        if ($api->serverUrl !== null) {
            $class->addComment('');
            $class->addComment("When config('{$this->configKey}.url') is absent, the base URL defaults to the");
            $class->addComment("spec's declared server: {$api->serverUrl}");
        }
        $class->addAttribute('Illuminate\\Container\\Attributes\\Singleton');

        $header = $api->apiKeyHeader ?? $this->defaultApiKeyHeader;
        $class->addConstant('API_KEY_HEADER', $header)->setPublic();

        $class->addProperty('http')->setPrivate()->setType('?Illuminate\\Http\\Client\\PendingRequest')->setValue(null);

        $defaultUrl = $api->serverUrl ?? '';

        $constructor = $class->addMethod('__construct');
        $baseUrl = $constructor->addPromotedParameter('baseUrl')->setPrivate()->setType('string')->setDefaultValue($defaultUrl);
        $baseUrl->addAttribute('Illuminate\\Container\\Attributes\\Config', ["{$this->configKey}.url", $defaultUrl]);
        $apiKey = $constructor->addPromotedParameter('apiKey')->setPrivate()->setType('?string')->setDefaultValue(null);
        $apiKey->addAttribute('Illuminate\\Container\\Attributes\\Config', ["{$this->configKey}.api_key", null]);
        $timeout = $constructor->addPromotedParameter('timeout')->setPrivate()->setType('?int')->setDefaultValue(null);
        $timeout->addAttribute('Illuminate\\Container\\Attributes\\Config', ["{$this->configKey}.timeout", null]);
        $retries = $constructor->addPromotedParameter('retries')->setPrivate()->setType('?int')->setDefaultValue(null);
        $retries->addAttribute('Illuminate\\Container\\Attributes\\Config', ["{$this->configKey}.retries", null]);

        $make = $class->addMethod('make')->setStatic()->setReturnType('self');
        $makeUrl = $make->addParameter('baseUrl')->setType('string');
        if ($api->serverUrl !== null) {
            $makeUrl->setDefaultValue($api->serverUrl);
        }
        $make->addParameter('apiKey')->setType('string')->setNullable()->setDefaultValue(null);
        $make->addParameter('timeout')->setType('int')->setNullable()->setDefaultValue(null);
        $make->addParameter('retries')->setType('int')->setNullable()->setDefaultValue(null);
        $make->setBody('return new self($baseUrl, $apiKey, $timeout, $retries);');

        $from = $class->addMethod('fromPendingRequest')->setStatic()->setReturnType('self');
        $from->addComment('Bring your own PendingRequest — base URL, auth, middleware, fakes.');
        $from->addParameter('http')->setType('Illuminate\\Http\\Client\\PendingRequest');
        $from->setBody(implode("\n", [
            '$client = new self;',
            '$client->http = $http;',
            '',
            'return $client;',
        ]));

        $auth = $api->bearerAuth
            ? '$http = $http->withToken($this->apiKey);'
            : '$http = $http->withHeaders([self::API_KEY_HEADER => $this->apiKey]);';

        $missingUrlMessage = "{$this->clientClass} has no base URL. Set config('{$this->configKey}.url') "
            ."(config/services.php + .env) or pass a base URL to {$this->clientClass}::make().";

        $http = $class->addMethod('http')->setPublic()->setReturnType('Illuminate\\Http\\Client\\PendingRequest');
        $http->addComment('The underlying PendingRequest (built lazily from config on first use).');
        $http->setBody(implode("\n", [
            'if ($this->http !== null) {',
            '    return $this->http;',
            '}',
            '',
            "if (\$this->baseUrl === '') {",
            '    throw new ConfigurationException('.var_export($missingUrlMessage, true).');',
            '}',
            '',
            "\$http = Http::baseUrl(rtrim(\$this->baseUrl, '/'))->acceptJson()->asJson();",
            '',
            'if ($this->apiKey !== null) {',
            "    {$auth}",
            '}',
            '',
            'if ($this->timeout !== null) {',
            '    $http = $http->timeout($this->timeout);',
            '}',
            '',
            'if ($this->retries !== null) {',
            '    $http = $http->retry(',
            '        $this->retries,',
            '        static fn (int $attempt): int => $attempt * 100,',
            '        // Retry only what is safe: transport failures always; 5xx/429 only on',
            '        // GETs. Other 4xx and non-idempotent requests surface immediately',
            '        // instead of being re-fired.',
            '        static function (\\Throwable $e, PendingRequest $request, ?string $method = null): bool {',
            '            if ($e instanceof ConnectionException) {',
            '                return true;',
            '            }',
            '',
            "            return \$method === 'GET'",
            '                && $e instanceof RequestException',
            '                && ($e->response->status() >= 500 || $e->response->status() === 429);',
            '        },',
            '    );',
            '}',
            '',
            'return $this->http = $http;',
        ]));

        foreach (array_keys($api->resources) as $resourceName) {
            $resourceClass = '\\'.$this->namespace.'\\Resources\\'.$resourceName.'Resource';
            $namespace->addUse(ltrim($resourceClass, '\\'));

            $accessor = $class->addMethod(Names::accessor($resourceName));
            $accessor->setReturnType($resourceClass);
            $accessor->setBody('return new '.$namespace->simplifyName($resourceClass).'($this->http());');
        }

        return $file;
    }
}
