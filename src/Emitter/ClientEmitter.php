<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\PhpFile;
use NovaHorizons\SdkGenerator\Ir\ApiDef;

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
        $namespace->addUse('Illuminate\\Http\\Client\\PendingRequest');
        $namespace->addUse('Illuminate\\Support\\Facades\\Http');

        $class = $namespace->addClass($this->clientClass);
        $class->setFinal();
        $class->addComment("Client for {$api->title} (spec version {$api->version}).\n");
        $class->addComment("Resolve via the container — `app({$this->clientClass}::class)` — to read");
        $class->addComment("config('{$this->configKey}.url'), .api_key, .timeout (seconds) and .retries,");
        $class->addComment('or construct explicitly with make() / fromPendingRequest().');
        $class->addAttribute('Illuminate\\Container\\Attributes\\Singleton');

        $header = $api->apiKeyHeader ?? $this->defaultApiKeyHeader;
        $class->addConstant('API_KEY_HEADER', $header)->setPublic();

        $class->addProperty('http')->setPrivate()->setType('?Illuminate\\Http\\Client\\PendingRequest')->setValue(null);

        $constructor = $class->addMethod('__construct');
        $baseUrl = $constructor->addPromotedParameter('baseUrl')->setPrivate()->setType('string')->setDefaultValue('');
        $baseUrl->addAttribute('Illuminate\\Container\\Attributes\\Config', ["{$this->configKey}.url", '']);
        $apiKey = $constructor->addPromotedParameter('apiKey')->setPrivate()->setType('?string')->setDefaultValue(null);
        $apiKey->addAttribute('Illuminate\\Container\\Attributes\\Config', ["{$this->configKey}.api_key", null]);
        $timeout = $constructor->addPromotedParameter('timeout')->setPrivate()->setType('?int')->setDefaultValue(null);
        $timeout->addAttribute('Illuminate\\Container\\Attributes\\Config', ["{$this->configKey}.timeout", null]);
        $retries = $constructor->addPromotedParameter('retries')->setPrivate()->setType('?int')->setDefaultValue(null);
        $retries->addAttribute('Illuminate\\Container\\Attributes\\Config', ["{$this->configKey}.retries", null]);

        $make = $class->addMethod('make')->setStatic()->setReturnType('self');
        $make->addParameter('baseUrl')->setType('string');
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

        $auth = $header === 'Authorization'
            ? '$http = $http->withToken($this->apiKey);'
            : '$http = $http->withHeaders([self::API_KEY_HEADER => $this->apiKey]);';

        $http = $class->addMethod('http')->setPublic()->setReturnType('Illuminate\\Http\\Client\\PendingRequest');
        $http->addComment('The underlying PendingRequest (built lazily from config on first use).');
        $http->setBody(implode("\n", [
            'if ($this->http !== null) {',
            '    return $this->http;',
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
            '    $http = $http->retry($this->retries, 100);',
            '}',
            '',
            'return $this->http = $http;',
        ]));

        foreach (array_keys($api->resources) as $resourceName) {
            $resourceClass = '\\'.$this->namespace.'\\Resources\\'.$resourceName.'Resource';
            $namespace->addUse(ltrim($resourceClass, '\\'));

            $accessor = $class->addMethod(lcfirst($resourceName));
            $accessor->setReturnType($resourceClass);
            $accessor->setBody('return new '.$namespace->simplifyName($resourceClass).'($this->http());');
        }

        return $file;
    }
}
