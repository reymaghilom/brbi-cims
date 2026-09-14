<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A delete confirmed from a screen loaded before another CI saved newer data. Thrown under the
 * exact IncomeSource row lock, before anything is deleted — see DeleteBusinessReport and
 * DeleteIncomeSource.
 */
class BusinessReportDeleteConflictException extends RuntimeException
{
    public static function forReport(): self
    {
        return new self('This Business Report was updated by another CI after you opened this page. The delete was not performed. Please refresh and review the latest information before deleting.');
    }

    public static function forBusiness(): self
    {
        return new self('This business was updated by another CI after you opened this page. The delete was not performed. Please refresh and review the latest information before deleting.');
    }
}
