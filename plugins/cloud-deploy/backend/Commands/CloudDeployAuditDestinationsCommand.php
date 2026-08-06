<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Throwable;

class CloudDeployAuditDestinationsCommand extends Command
{
    protected $signature = 'cloud-deploy:audit-destinations {--json : 输出 JSON}';

    protected $description = '只读审计 cloud-deploy 历史凭证的出站目标分类';

    public function __construct(
        private readonly Registry $registry,
        private readonly OutboundDestinationPolicy $policy,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = [];
        CloudDeployAccess::withoutGlobalScopes()
            ->orderBy('id')
            ->chunkById(100, function ($accesses) use (&$rows): void {
                foreach ($accesses as $access) {
                    $rows = array_merge($rows, $this->auditAccess($access));
                }
            });

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['access_id', 'provider', 'field', 'host', 'port', 'scope', 'status', 'suggested_allowlist'],
            $rows,
        );

        return self::SUCCESS;
    }

    /** @return list<array<string,int|string|null>> */
    private function auditAccess(CloudDeployAccess $access): array
    {
        try {
            $schema = $this->registry->resolveProvider($access->provider)->credentialSchema();
            $credentials = $access->credentials;
        } catch (InvalidArgumentException) {
            return [];
        } catch (Throwable) {
            return [[
                'access_id' => $access->id,
                'provider' => $access->provider,
                'field' => null,
                'host' => null,
                'port' => null,
                'scope' => null,
                'status' => 'credentials_unreadable',
                'suggested_allowlist' => null,
            ]];
        }

        $rows = [];
        foreach ($schema as $field) {
            if (empty($field['destination'])) {
                continue;
            }

            $key = (string) $field['key'];
            $url = $credentials[$key] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            [$host, $port] = $this->safeHostPort($url);
            $scope = null;
            $status = 'allowed';
            $suggestedAllowlist = null;

            try {
                $destination = $this->policy->authorize($access->provider, $url);
                $host = $destination->host;
                $port = $destination->port;
                $scope = $destination->scope;
            } catch (OutboundDestinationException $e) {
                $status = $e->reasonCode();
                if ($status === 'private_not_allowed' && $host !== null && $port !== null) {
                    $allowlistHost = str_contains($host, ':') ? "[$host]" : $host;
                    $suggestedAllowlist = strtolower($access->provider)."@$allowlistHost:$port";
                    $scope = 'private';
                }
            }

            $rows[] = [
                'access_id' => $access->id,
                'provider' => $access->provider,
                'field' => $key,
                'host' => $host,
                'port' => $port,
                'scope' => $scope,
                'status' => $status,
                'suggested_allowlist' => $suggestedAllowlist,
            ];
        }

        return $rows;
    }

    /** @return array{0:?string,1:?int} */
    private function safeHostPort(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return [null, null];
        }

        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : match ($scheme) {
                'https' => 443,
                'http' => 80,
                default => null,
            };

        return [$host !== '' ? $host : null, $port];
    }
}
