<?php

/**
 * Builds minimal FIT byte strings for tests, so a case can be constructed instead of recorded:
 * real-world files carry athletes' GPS, and this repository is public.
 *
 * A file is a 14-byte header, a run of definition and data messages, and a CRC (zero unless asked
 * for: this library checks it only with 'validate_crc'). Definitions describe a local message
 * type; data messages reference one.
 */
trait FitFileBuilder
{
    /**
     * @param array $fields    native fields as [[field number, size in bytes, base type], ...]
     * @param array $devFields developer fields as [[field number, size in bytes, developer data index], ...];
     *                         when non-empty the header's developer-data bit is set and the triples
     *                         follow the native fields, as the protocol lays them out
     * @param bool  $bigEndian architecture 1: the global message number, and every value of the
     *                         data messages using this definition, are big-endian
     */
    private function definition($localType, $globalMesgNum, array $fields, array $devFields = [], $bigEndian = false)
    {
        $header = 0x40 | $localType | ($devFields ? 0x20 : 0);
        $bytes = pack('CCC', $header, 0, $bigEndian ? 1 : 0) . pack($bigEndian ? 'n' : 'v', $globalMesgNum) . pack('C', count($fields));
        foreach ($fields as [$number, $size, $baseType]) {
            $bytes .= pack('CCC', $number, $size, $baseType);
        }
        if ($devFields) {
            $bytes .= pack('C', count($devFields));
            foreach ($devFields as [$number, $size, $developerDataIndex]) {
                $bytes .= pack('CCC', $number, $size, $developerDataIndex);
            }
        }

        return $bytes;
    }

    private function data($localType, $payload)
    {
        return pack('C', $localType) . $payload;
    }

    /**
     * A data message with a compressed timestamp header: local types 0-3 only, and a 5-bit offset
     * counted on from the previous message's timestamp.
     */
    private function compressed($localType, $timeOffset, $payload)
    {
        return pack('C', 0x80 | ($localType << 5) | ($timeOffset & 0x1F)) . $payload;
    }

    private function fitFile($records, $withCrc = false)
    {
        $header = pack('CCvV', 14, 16, 2132, strlen($records)) . '.FIT';
        $header .= pack('v', $withCrc ? adriangibbons\phpFITFileAnalysis::crc16($header) : 0);
        $body = $header . $records;

        return $body . pack('v', $withCrc ? adriangibbons\phpFITFileAnalysis::crc16($body) : 0);
    }
}
