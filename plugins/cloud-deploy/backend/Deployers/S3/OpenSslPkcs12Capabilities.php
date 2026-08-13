<?php

namespace Plugins\CloudDeploy\Deployers\S3;

use Symfony\Component\Process\Process;
use Throwable;

final class OpenSslPkcs12Capabilities
{
    /** @var array<string, self> */
    private static array $cache = [];

    private function __construct(
        private readonly bool $supportsModern2026,
        private readonly string $diagnosticVersion,
    ) {}

    /**
     * @param  null|callable(list<string>):array{exitCode:int,output:string,errorOutput:string}  $runner
     */
    public static function probe(string $binary, ?callable $runner = null): self
    {
        if (isset(self::$cache[$binary])) {
            return self::$cache[$binary];
        }

        $runner ??= static function (array $command): array {
            try {
                $process = new Process($command);
                $process->setTimeout(10);
                $process->run();

                return [
                    'exitCode' => $process->getExitCode() ?? 1,
                    'output' => $process->getOutput(),
                    'errorOutput' => $process->getErrorOutput(),
                ];
            } catch (Throwable) {
                return ['exitCode' => 1, 'output' => '', 'errorOutput' => ''];
            }
        };

        $help = self::runProbe($runner, [$binary, 'pkcs12', '-help']);
        $version = self::runProbe($runner, [$binary, 'version', '-v']);
        $helpText = $help['output']."\n".$help['errorOutput'];
        $diagnosticVersion = self::sanitizeVersion($version['output'].$version['errorOutput']);

        return self::$cache[$binary] = new self(
            self::hasOption($helpText, '-pbmac1_pbkdf2')
                && self::hasOption($helpText, '-pbmac1_pbkdf2_md')
                && self::hasOption($helpText, '-macsaltlen'),
            $diagnosticVersion,
        );
    }

    public function supportsModern2026(): bool
    {
        return $this->supportsModern2026;
    }

    public function diagnosticVersion(): string
    {
        return $this->diagnosticVersion;
    }

    /** @param callable(list<string>):array{exitCode:int,output:string,errorOutput:string} $runner @param list<string> $command */
    private static function runProbe(callable $runner, array $command): array
    {
        try {
            return $runner($command);
        } catch (Throwable) {
            return ['exitCode' => 1, 'output' => '', 'errorOutput' => ''];
        }
    }

    private static function hasOption(string $helpText, string $option): bool
    {
        return preg_match('/(?<![A-Za-z0-9_-])'.preg_quote($option, '/').'(?![A-Za-z0-9_-])/', $helpText) === 1;
    }

    private static function sanitizeVersion(string $version): string
    {
        if (preg_match('/\b(OpenSSL\s+\d+(?:\.\d+){1,3}[A-Za-z0-9._+-]*)\b/', $version, $matches) === 1) {
            return $matches[1];
        }

        return '未知 OpenSSL CLI 版本';
    }
}
