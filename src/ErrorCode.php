<?php

declare(strict_types=1);

namespace BTOON;

/**
 * Machine-readable error codes (SPEC §7). The string values are shared by every
 * BTOON implementation, so tests and callers can compare them across languages.
 */
enum ErrorCode: string
{
    /** Input ends before a value is complete, or a length runs past the input. */
    case Truncated = 'truncated';
    /** The reserved marker 0xc1. */
    case InvalidMarker = 'invalid_marker';
    /** Bytes remain after the top-level value (strict mode). */
    case TrailingData = 'trailing_data';
    /** A string, map key or column name is not well-formed UTF-8. */
    case InvalidUtf8 = 'invalid_utf8';
    /** A map key is not a string. */
    case NonStringKey = 'non_string_key';
    /** A map contains the same key twice (strict mode). */
    case DuplicateKey = 'duplicate_key';
    /** Nesting deeper than max_depth (also raised by the encoder for cyclic data). */
    case MaxDepth = 'max_depth';
    /** A malformed BTOON extension payload (SPEC §5). */
    case InvalidExtension = 'invalid_extension';
    /** A malformed tabular payload (SPEC §5.6.3). */
    case InvalidTabular = 'invalid_tabular';
    /** A malformed compression or signed envelope (SPEC §6, §8). */
    case InvalidEnvelope = 'invalid_envelope';
    /** A size above a configured limit. */
    case SizeLimit = 'size_limit';
    /** A compression algorithm this installation cannot handle. */
    case UnsupportedCompression = 'unsupported_compression';
    /** HMAC verification failed, or a signature is present but no key was given (SPEC §8). */
    case AuthenticationFailed = 'authentication_failed';
    /** The caller passed something that can't be encoded or an invalid option. */
    case InvalidArgument = 'invalid_argument';
}
