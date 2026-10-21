<?php
namespace adriangibbons;

/**
 * The bytes given are not a FIT file this library can read: a bad header, a truncated file, a
 * data message with no definition, or (with 'validate_crc') a CRC mismatch.
 */
class FitParseException extends \RuntimeException
{
}
