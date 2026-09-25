<?php

/**
 * Builds minimal FIT byte strings for tests, so a case can be constructed instead of recorded:
 * real-world files carry athletes' GPS, and this repository is public.
 *
 * A file is a 14-byte header, a run of definition and data messages, and a CRC this library does
 * not check. Definitions describe a local message type; data messages reference one.
 */
trait FitFileBuilder
{
    /**
     * @param array $fields    native fields as [[field number, size in bytes, base type], ...]
     * @param array $devFields developer fields as [[field number, size in bytes, developer data index], ...];
     *                         when non-empty the header's developer-data bit is set and the triples
     *                         follow the native fields, as the protocol lays them out
     */
    private function definition($localType, $globalMesgNum, array $fields, array $devFields = [])
    {
        $header = 0x40 | $localType | ($devFields ? 0x20 : 0);
        $bytes = pack('CCCvC', $header, 0, 0, $globalMesgNum, count($fields));
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

    private function fitFile($records)
    {
        return pack('CCvV', 14, 16, 2132, strlen($records)) . '.FIT' . pack('v', 0) . $records . pack('v', 0);
    }
}
