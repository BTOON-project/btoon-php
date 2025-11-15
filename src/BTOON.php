<?php

namespace BTOON;

use BTOON\Encoder\EncoderInterface;
use BTOON\Encoder\NativeEncoder;
use BTOON\Encoder\PureEncoder;
use BTOON\Decoder\DecoderInterface;
use BTOON\Decoder\NativeDecoder;
use BTOON\Decoder\PureDecoder;

/**
 * Main BTOON class for encoding and decoding
 */
class BTOON
{
    const VERSION = '0.0.1';
    
    /**
     * @var EncoderInterface
     */
    private static $encoder;
    
    /**
     * @var DecoderInterface
     */
    private static $decoder;
    
    /**
     * Encode PHP value to BTOON format
     *
     * @param mixed $value
     * @param array $options
     * @return string Binary BTOON data
     */
    public static function encode($value, array $options = []): string
    {
        $encoder = self::getEncoder();
        return $encoder->encode($value, $options);
    }
    
    /**
     * Decode BTOON data to PHP value
     *
     * @param string $data Binary BTOON data
     * @param array $options
     * @return mixed
     */
    public static function decode(string $data, array $options = [])
    {
        $decoder = self::getDecoder();
        return $decoder->decode($data, $options);
    }
    
    /**
     * Check if native extension is available
     *
     * @return bool
     */
    public static function hasNativeExtension(): bool
    {
        return extension_loaded('btoon');
    }
    
    /**
     * Get the encoder instance
     *
     * @return EncoderInterface
     */
    private static function getEncoder(): EncoderInterface
    {
        if (self::$encoder === null) {
            if (self::hasNativeExtension()) {
                self::$encoder = new NativeEncoder();
            } else {
                self::$encoder = new PureEncoder();
            }
        }
        
        return self::$encoder;
    }
    
    /**
     * Get the decoder instance
     *
     * @return DecoderInterface
     */
    private static function getDecoder(): DecoderInterface
    {
        if (self::$decoder === null) {
            if (self::hasNativeExtension()) {
                self::$decoder = new NativeDecoder();
            } else {
                self::$decoder = new PureDecoder();
            }
        }
        
        return self::$decoder;
    }
    
    /**
     * Reset encoder and decoder instances
     * Useful for testing or switching implementations
     */
    public static function reset(): void
    {
        self::$encoder = null;
        self::$decoder = null;
    }
}
