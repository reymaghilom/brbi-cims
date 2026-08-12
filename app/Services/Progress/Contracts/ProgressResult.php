<?php

namespace App\Services\Progress\Contracts;

final readonly class ProgressResult
{
    /**
     * @param  list<string>  $completed
     * @param  list<string>  $incomplete
     */
    public function __construct(
        public array $completed,
        public array $incomplete,
        public float $percentage,
        public bool $isComplete,
    ) {}
}
