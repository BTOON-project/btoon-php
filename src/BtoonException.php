<?php

declare(strict_types=1);

namespace BTOON;

/**
 * The only exception type thrown by BTOON for bad input, bad options or values
 * that can't be encoded. The SPEC §7 code is available as a string
 * ({@see $errorCode}, {@see getErrorCode()}) and as an enum ({@see $error}).
 *
 * ```php
 * try {
 *     $value = BTOON\decode($bytes);
 * } catch (BTOON\BtoonException $e) {
 *     if ($e->errorCode === 'truncated') { ... }
 * }
 * ```
 */
final class BtoonException extends \RuntimeException
{
    /** The error code as an enum. */
    public readonly ErrorCode $error;

    /** The error code string from SPEC §7, e.g. "truncated" or "invalid_utf8". */
    public readonly string $errorCode;

    public function __construct(ErrorCode $error, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->error = $error;
        $this->errorCode = $error->value;
    }

    /** The error code string from SPEC §7, e.g. "truncated" or "invalid_utf8". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
