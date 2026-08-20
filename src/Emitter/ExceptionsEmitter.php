<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Emitter;

use Nette\PhpGenerator\PhpFile;
use NovaHorizons\SdkGenerator\Ir\ApiDef;

/**
 * Emits the per-SDK exception hierarchy:
 *
 * - {Brand}Exception       marker interface — catch anything this SDK throws
 * - RequestException       extends Illuminate's, so existing catch sites keep working
 * - ConnectionException    extends Illuminate's, ditto
 * - UnexpectedResponseException  thrown by Cast when a response defies the spec
 */
final readonly class ExceptionsEmitter
{
    public function __construct(
        private string $namespace,
        private string $brand, // e.g. "Orbit" — client class name minus "Client"
        private Types $types,
    ) {}

    public function interfaceName(): string
    {
        return $this->brand.'Exception';
    }

    /** @return array<string, PhpFile> relative path => file */
    public function emit(ApiDef $api): array
    {
        $files = [];
        $ns = $this->namespace.'\\Exceptions';
        $marker = $this->interfaceName();

        // Marker interface
        $file = new PhpFile;
        $file->setStrictTypes();
        $interface = $file->addNamespace($ns)->addInterface($marker);
        $interface->addComment("Marker for every exception the {$this->brand} SDK throws.");
        $interface->addExtend(\Throwable::class);
        $files["Exceptions/{$marker}.php"] = $file;

        // RequestException
        $file = new PhpFile;
        $file->setStrictTypes();
        $namespace = $file->addNamespace($ns);
        $namespace->addUse('Illuminate\\Http\\Client\\RequestException', 'BaseRequestException');
        $class = $namespace->addClass('RequestException');
        $class->setFinal()
            ->setExtends('Illuminate\\Http\\Client\\RequestException')
            ->addImplement($ns.'\\'.$marker);
        $class->addComment("4xx/5xx response from the {$this->brand} API.\n");
        $class->addComment('Extends Illuminate\'s RequestException, so $e->response and existing catch sites keep working.');

        if ($api->errorClass !== null) {
            $errorDto = $this->types->dtoClass($api->errorClass);
            $namespace->addUse(ltrim($errorDto, '\\'));
            $namespace->addUse(ltrim($this->types->castClass(), '\\'));

            $method = $class->addMethod('error');
            $method->setReturnType($errorDto)->setReturnNullable();
            $method->addComment('The API error body, when it matches the documented error schema.');
            $method->setBody(implode("\n", [
                'try {',
                '    $data = $this->response->json();',
                '',
                '    return is_array($data) ? '.$namespace->simplifyName($errorDto).'::fromArray($data) : null;',
                '} catch (UnexpectedResponseException) {',
                '    return null;',
                '}',
            ]));
        }

        $files['Exceptions/RequestException.php'] = $file;

        // ConnectionException
        $file = new PhpFile;
        $file->setStrictTypes();
        $namespace = $file->addNamespace($ns);
        $class = $namespace->addClass('ConnectionException');
        $class->setFinal()
            ->setExtends('Illuminate\\Http\\Client\\ConnectionException')
            ->addImplement($ns.'\\'.$marker);
        $class->addComment("Transport failure talking to the {$this->brand} API (DNS, timeout, refused connection).");
        $files['Exceptions/ConnectionException.php'] = $file;

        // UnexpectedResponseException
        $file = new PhpFile;
        $file->setStrictTypes();
        $namespace = $file->addNamespace($ns);
        $class = $namespace->addClass('UnexpectedResponseException');
        $class->setFinal()
            ->setExtends(\UnexpectedValueException::class)
            ->addImplement($ns.'\\'.$marker);
        $class->addComment("A 2xx response whose body does not match the OpenAPI spec.\n");
        $class->addComment('Thrown by the Cast helpers during hydration.');
        $files['Exceptions/UnexpectedResponseException.php'] = $file;

        return $files;
    }
}
