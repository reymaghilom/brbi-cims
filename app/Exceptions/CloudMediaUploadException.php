<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Cloudinary upload call fails for a reason a CI-facing UI must surface as a safe,
 * generic retry message — never the underlying SDK/HTTP exception's own message (which can include
 * request/response details, account identifiers, or API internals). The original exception is
 * still reported (see CloudinaryMediaStorage::store()) for later investigation; only this message
 * ever reaches a response the browser can see.
 */
class CloudMediaUploadException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('One or more files could not be uploaded to cloud storage.');
    }
}
