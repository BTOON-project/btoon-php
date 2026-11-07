<?php

// Static-analysis stubs for the optional compression extensions used by
// src/Internal/Codec.php. This file is never loaded at runtime; signatures follow
// the extensions' own *.stub.php files.

namespace Zstd\UnCompress {
    final class Context
    {
    }
}

namespace {
    /** kjdev/php-ext-lz4 >= 0.8.0 (raw LZ4 block format, no size header). */
    function lz4_compress_raw(string $data, int $level = 0): string|false
    {
    }

    /** kjdev/php-ext-lz4 >= 0.8.0; $maxsize is the exact output buffer size. */
    function lz4_uncompress_raw(string $data, int $maxsize): string|false
    {
    }

    /** kjdev/php-ext-zstd. */
    function zstd_compress(string $data, int $level = 3, ?string $dict = null): string|false
    {
    }

    /** kjdev/php-ext-zstd >= 0.15.0. */
    function zstd_uncompress_init(?string $dict = null): Zstd\UnCompress\Context|false
    {
    }

    /** kjdev/php-ext-zstd >= 0.15.0. */
    function zstd_uncompress_add(Zstd\UnCompress\Context $context, string $data): string|false
    {
    }

    /** kjdev/php-ext-snappy (raw Snappy format). */
    function snappy_compress(string $data): string|false
    {
    }

    /** kjdev/php-ext-snappy (raw Snappy format). */
    function snappy_uncompress(string $data): string|false
    {
    }
}
