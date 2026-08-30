<?php

declare(strict_types=1);

namespace App\Services\Delegation\Dns;

interface DelegationDnsProvider
{
    public function upsertTxt(string $name, array $values, int $ttl = 600): bool;

    public function allTxt(): array;

    public function deleteTxt(string $name): void;

    public function deleteRecords(array $recordIds): void;
}
