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
 * - ConfigurationException      the client is missing required setup (e.g. no base URL)
 */
final readonly class ExceptionsEmitter
{
    public function __construct(
        private string $namespace,
        private string $brand, // e.g. "Orbit" — client class name minus "Client"
        private Types $types,
    ) {}

    /** @return array<string, PhpFile> relative path => file */
    public function emit(ApiDef $api): array
    {
        $files = [];
        $ns = $this->namespace.'\\Exceptions';
        $marker = $this->brand.'Exception';

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

        $files['Exceptions/ConnectionException.php'] = $this->exceptionClass(
            'ConnectionException',
            'Illuminate\\Http\\Client\\ConnectionException',
            "Transport failure talking to the {$this->brand} API (DNS, timeout, refused connection).",
        );

        $files['Exceptions/UnexpectedResponseException.php'] = $this->exceptionClass(
            'UnexpectedResponseException',
            \UnexpectedValueException::class,
            "A 2xx response whose body does not match the OpenAPI spec.\n",
            'Thrown by the Cast helpers during hydration.',
        );

        $files['Exceptions/ConfigurationException.php'] = $this->exceptionClass(
            'ConfigurationException',
            \LogicException::class,
            "The {$this->brand} client is missing required setup (e.g. no base URL).\n",
            'A deployment/wiring problem, not an API failure — fix the config rather than catching this.',
        );

        return $files;
    }

    /** A final exception class extending $extends and implementing the marker interface. */
    private function exceptionClass(string $name, string $extends, string ...$comments): PhpFile
    {
        $ns = $this->namespace.'\\Exceptions';

        $file = new PhpFile;
        $file->setStrictTypes();
        $class = $file->addNamespace($ns)->addClass($name);
        $class->setFinal()
            ->setExtends($extends)
            ->addImplement($ns.'\\'.$this->brand.'Exception');
        foreach ($comments as $comment) {
            $class->addComment($comment);
        }

        return $file;
    }
}
