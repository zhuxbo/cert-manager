<?php

namespace App\Services\EnterpriseLookup;

interface LookupInterface
{
    /**
     * @return array{name:?string, registration_number:?string, address:?string, state:?string, city:?string, regionname:?string, legal_person:?string}
     *
     * @throws LookupException
     */
    public function lookup(string $name): array;
}
