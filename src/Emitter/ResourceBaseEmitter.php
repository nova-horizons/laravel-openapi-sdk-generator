<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\PhpFile;

/**
 * Emits the abstract Resource base class: holds the PendingRequest and wraps
 * every call so Illuminate's client exceptions surface as the SDK's own.
 */
final readonly class ResourceBaseEmitter
{
    public function __construct(private string $namespace) {}

    public function emit(): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace.'\\Resources');
        $namespace->addUse('Closure');
        $namespace->addUse('Illuminate\\Http\\Client\\PendingRequest');
        $namespace->addUse('Illuminate\\Http\\Client\\Response');
        $namespace->addUse($this->namespace.'\\Exceptions\\ConnectionException');
        $namespace->addUse($this->namespace.'\\Exceptions\\RequestException');

        $class = $namespace->addClass('Resource');
        $class->setAbstract()->setReadOnly();

        $constructor = $class->addMethod('__construct');
        $constructor->addPromotedParameter('http')->setProtected()->setType('Illuminate\\Http\\Client\\PendingRequest');

        $send = $class->addMethod('send')->setProtected()->setReturnType('Illuminate\\Http\\Client\\Response');
        $send->addComment('@param Closure(): Response $request');
        $send->addComment('');
        $send->addComment('@throws ConnectionException on transport errors');
        $send->addComment('@throws RequestException on 4xx/5xx responses');
        $send->addParameter('request')->setType('Closure');
        $send->setBody(implode("\n", [
            'try {',
            '    return $request()->throw();',
            '} catch (\\Illuminate\\Http\\Client\\RequestException $e) {',
            '    throw new RequestException($e->response);',
            '} catch (\\Illuminate\\Http\\Client\\ConnectionException $e) {',
            '    throw new ConnectionException($e->getMessage(), $e->getCode(), $e);',
            '}',
        ]));

        return $file;
    }
}
