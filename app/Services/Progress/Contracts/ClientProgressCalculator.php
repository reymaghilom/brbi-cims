<?php

namespace App\Services\Progress\Contracts;

interface ClientProgressCalculator
{
    /**
     * @param  iterable<string, bool>  $applicableRequirements
     */
    public function calculate(iterable $applicableRequirements): ProgressResult;
}
