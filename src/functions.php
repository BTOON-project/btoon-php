<?php

namespace BTOON;

/**
 * Encode PHP value to BTOON format
 *
 * @param mixed $value
 * @param array $options
 * @return string
 */
function encode($value, array $options = []): string
{
    return BTOON::encode($value, $options);
}

/**
 * Decode BTOON data to PHP value
 *
 * @param string $data
 * @param array $options
 * @return mixed
 */
function decode(string $data, array $options = [])
{
    return BTOON::decode($data, $options);
}

/**
 * Check if BTOON native extension is available
 *
 * @return bool
 */
function has_native_extension(): bool
{
    return BTOON::hasNativeExtension();
}

/**
 * Get BTOON version
 *
 * @return string
 */
function version(): string
{
    return BTOON::VERSION;
}
