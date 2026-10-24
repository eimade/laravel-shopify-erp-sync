<?php

namespace App\GraphQL\Queries;

use App\Services\ConnectorHealth;
use Illuminate\Support\Str;

final class SyncHealthQuery
{
    public function __construct(private readonly ConnectorHealth $health) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $snapshot = $this->health->snapshot();
        $out = [];
        foreach ($snapshot as $key => $value) {
            $out[Str::camel($key)] = $value;
        }

        return $out;
    }
}
