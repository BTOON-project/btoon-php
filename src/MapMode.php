<?php

declare(strict_types=1);

namespace BTOON;

/**
 * How the decoder returns maps (the `maps` option).
 *
 * PHP arrays can't hold every map: an array whose keys are exactly 0, 1, …, n-1 in order
 * is a list, and the encoder writes lists as BTOON arrays. That covers the empty map and a
 * map with the keys "0", "1", … in that order, because PHP turns integer-like string keys
 * into int keys. `Auto`, the default since 0.2.0, keeps round trips lossless.
 */
enum MapMode: string
{
    /**
     * Associative arrays, except for the maps that PHP would turn into a list, which become
     * `\ArrayObject` so that they encode as maps again. Ordinary maps stay arrays, and maps
     * survive a decode/encode round trip. The default since 0.2.0.
     */
    case Auto = 'auto';

    /**
     * Always arrays (the default in 0.1.x): the maps above come back as lists, which
     * encode as arrays, so a round trip turns them into arrays.
     */
    case Array = 'array';

    /** Always `\ArrayObject`, table rows included. */
    case Object = 'object';
}
