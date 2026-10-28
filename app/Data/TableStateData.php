<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class TableStateData extends Data
{
    /**
     * @param  list<array{type?: string, name?: string, isToggled?: bool}>  $columns
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $columnSearches
     * @param  array<string, int>  $widths
     */
    public function __construct(
        public array $columns = [],
        public ?string $sort = null,
        public array $filters = [],
        public ?string $search = null,
        public array $columnSearches = [],
        public ?string $grouping = null,
        public int|string|null $perPage = null,
        public array $widths = [],
    ) {}

    public function hash(): string
    {
        return hash('xxh128', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
