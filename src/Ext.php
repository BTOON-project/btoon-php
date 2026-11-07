<?php

declare(strict_types=1);

namespace BTOON;

/**
 * A generic MessagePack extension: a signed type id and opaque bytes (SPEC §5.7).
 *
 * Ids 0…127 belong to applications. The decoder also returns the reserved BTOON
 * ids (-128…-11 and -9…-7) as Ext. The ids BTOON defines (-1…-6 and -10) have
 * dedicated classes, and the encoder rejects them in an Ext so it can never write
 * a malformed or non-canonical timestamp, bigint, vector or tabular payload.
 */
final readonly class Ext
{
    public function __construct(public int $type, public string $data)
    {
        if ($type < -128 || $type > 127) {
            throw new BtoonException(
                ErrorCode::InvalidArgument,
                sprintf('extension type must be within -128…127, got %d', $type),
            );
        }
    }
}
