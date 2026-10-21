<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * hr (132) messages carry a strap's buffered beats: filtered_bpm values, and their times packed
 * 12 bits apiece into event_timestamp_12. A filtered_bpm of 255 is "no reading"; it used to be
 * averaged into the record's heart rate as 255 bpm. It is skipped, and the values after it stay
 * on their own seconds.
 */
class HrInvalidValueTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const RECORD = 20;
    private const HR = 132;
    private const UINT8 = 0x02;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;
    private const BYTE = 0x0D;

    /** Eight 12-bit values, two to three bytes, as event_timestamp_12 packs them. */
    private function packTimes12(array $times)
    {
        $bytes = '';
        foreach (array_chunk($times, 2) as [$a, $b]) {
            $bytes .= pack('CCC', $a & 0xFF, (($a >> 8) & 0x0F) | (($b & 0x0F) << 4), ($b >> 4) & 0xFF);
        }

        return $bytes;
    }

    public function testAnInvalidBufferedBeatIsSkipped()
    {
        // Records each second from 1000 to 1008 with no heart rate of their own.
        $bytes = $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]]);
        for ($t = 1000; $t <= 1008; $t++) {
            $bytes .= $this->data(0, pack('Vv', $t, 200));
        }
        // First hr message: its timestamp, event_timestamp 0 (in 1/1024 s), and one beat at 1000.
        $bytes .= $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 4, self::UINT32], [6, 1, self::UINT8]])
            . $this->data(1, pack('VVC', 1000, 0, 100));
        // Second: eight beats at 1001..1008 s, the third (1003) invalid.
        $times = array_map(fn($s) => ($s * 1024) & 0xFFF, range(1, 8));
        $bytes .= $this->definition(2, self::HR, [[6, 8, self::UINT8], [10, 12, self::BYTE]])
            . $this->data(2, pack('C8', 101, 102, 0xFF, 104, 105, 106, 107, 108) . $this->packTimes12($times));

        $pFFA = new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw']);
        $hr = $pFFA->data_mesgs['record']['heart_rate'];

        $ts = fn($t) => $t + FIT_UNIX_TS_DIFF;
        $this->assertArrayNotHasKey($ts(1003), $hr);
        $this->assertSame(102, $hr[$ts(1002)]);
        $this->assertSame(104, $hr[$ts(1004)]);
        $this->assertSame(108, $hr[$ts(1008)]);
        $this->assertNotContains(255, $hr);
    }

    public function testABufferWithNoValidBeatKeepsTheNextOnesOnTheirSeconds()
    {
        $bytes = $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]]);
        for ($t = 1000; $t <= 1016; $t++) {
            $bytes .= $this->data(0, pack('Vv', $t, 200));
        }
        $bytes .= $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 4, self::UINT32], [6, 1, self::UINT8]])
            . $this->data(1, pack('VVC', 1000, 0, 100));
        // 1001..1008 all "no reading" (the strap dropped out), then 1009..1016 read 109..116.
        $bytes .= $this->definition(2, self::HR, [[6, 8, self::UINT8], [10, 12, self::BYTE]])
            . $this->data(2, str_repeat("\xFF", 8) . $this->packTimes12(array_map(fn($s) => ($s * 1024) & 0xFFF, range(1, 8))))
            . $this->data(2, pack('C8', ...range(109, 116)) . $this->packTimes12(array_map(fn($s) => ($s * 1024) & 0xFFF, range(9, 16))));

        $pFFA = new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw']);
        $hr = $pFFA->data_mesgs['record']['heart_rate'];

        $ts = fn($t) => $t + FIT_UNIX_TS_DIFF;
        $this->assertSame(100, $hr[$ts(1000)]);
        $this->assertArrayNotHasKey($ts(1001), $hr);
        $this->assertArrayNotHasKey($ts(1008), $hr);
        $this->assertSame(109, $hr[$ts(1009)]);
        $this->assertSame(116, $hr[$ts(1016)]);
    }

    /** Records each second from 1000 to $to, with no heart rate of their own. */
    private function records($to)
    {
        $bytes = $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]]);
        for ($t = 1000; $t <= $to; $t++) {
            $bytes .= $this->data(0, pack('Vv', $t, 200));
        }

        return $bytes;
    }

    private function heartRate($bytes)
    {
        $pFFA = new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw']);
        $hr = [];
        foreach ($pFFA->data_mesgs['record']['heart_rate'] ?? [] as $ts => $bpm) {
            $hr[$ts - FIT_UNIX_TS_DIFF] = $bpm;
        }

        return $hr;
    }

    public function testAOneBeatMessageWithNoValidBeatKeepsTheNextOnesOnTheirSeconds()
    {
        $hrFull = $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 4, self::UINT32], [6, 1, self::UINT8]]);
        $hrOne = $this->definition(2, self::HR, [[9, 4, self::UINT32], [6, 1, self::UINT8]]);
        $bytes = $this->records(1005) . $hrFull . $hrOne
            . $this->data(1, pack('VVC', 1000, 0, 100))
            . $this->data(2, pack('VC', 1024, 0xFF))   // 1001: no reading
            . $this->data(2, pack('VC', 2048, 102))
            . $this->data(2, pack('VC', 3072, 103));

        $this->assertSame([1000 => 100, 1002 => 102, 1003 => 103], $this->heartRate($bytes));
    }

    public function testAFirstMessageWithSeveralFullTimesThenTwelveBitOnes()
    {
        $bytes = $this->records(1010)
            . $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 8, self::UINT32], [6, 2, self::UINT8]])
            . $this->data(1, pack('VVVCC', 1000, 0, 1024, 100, 101))
            . $this->definition(2, self::HR, [[6, 8, self::UINT8], [10, 12, self::BYTE]])
            . $this->data(2, pack('C8', ...range(102, 109)) . $this->packTimes12(array_map(fn($s) => ($s * 1024) & 0xFFF, range(2, 9))));

        $hr = $this->heartRate($bytes);

        $this->assertSame(101, $hr[1001]);
        $this->assertSame(102, $hr[1002]);
        $this->assertSame(109, $hr[1009]);
    }

    public function testUnreadableTwelveBitTimesLoseOnlyThatMessagesBeats()
    {
        $bytes = $this->records(1010)
            . $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 4, self::UINT32], [6, 1, self::UINT8]])
            . $this->data(1, pack('VVC', 1000, 0, 100))
            . $this->definition(2, self::HR, [[6, 8, self::UINT8], [10, 12, self::BYTE]])
            . $this->data(2, pack('C8', ...range(101, 108)) . str_repeat("\xFF", 12))  // times invalid
            . $this->definition(3, self::HR, [[9, 4, self::UINT32], [6, 1, self::UINT8]])
            . $this->data(3, pack('VC', 9 * 1024, 109));

        $this->assertSame([1000 => 100, 1009 => 109], $this->heartRate($bytes));
    }

    public function testAnInvalidFirstTimeDoesNotDiscardTheRest()
    {
        $hrFull = $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 4, self::UINT32], [6, 1, self::UINT8]]);
        $bytes = $this->records(1005) . $hrFull
            . $this->data(1, pack('VVC', 1000, 0xFFFFFFFF, 100))
            . $this->data(1, pack('VVC', 1001, 1024, 101))
            . $this->data(1, pack('VVC', 1002, 2048, 102));

        $this->assertSame([1001 => 101, 1002 => 102], $this->heartRate($bytes));
    }

    public function testTwelveBitTimesWithoutBeatsStillMoveTheClockOn()
    {
        // 1001..1008: times but no filtered_bpm. The next message's 12-bit times count on from them;
        // from 1000 they would miss two 4096-tick rollovers and land 8 s early.
        $times = fn($from, $to) => $this->packTimes12(array_map(fn($s) => ($s * 1024) & 0xFFF, range($from, $to)));
        $bytes = $this->records(1020)
            . $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 4, self::UINT32], [6, 1, self::UINT8]])
            . $this->data(1, pack('VVC', 1000, 0, 100))
            . $this->definition(2, self::HR, [[10, 12, self::BYTE]])
            . $this->data(2, $times(1, 8))
            . $this->definition(3, self::HR, [[6, 8, self::UINT8], [10, 12, self::BYTE]])
            . $this->data(3, pack('C8', ...range(109, 116)) . $times(9, 16));

        $hr = $this->heartRate($bytes);

        $this->assertSame(109, $hr[1009]);
        $this->assertSame(116, $hr[1016]);
    }

    public function testTheClockIsAnchoredOnAMessagesFirstTimeOnly()
    {
        // The first message's timestamp is its first beat's, whose time is invalid: anchoring on its
        // second time (1 s later) would put every beat a second early.
        $bytes = $this->records(1005)
            . $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 8, self::UINT32], [6, 2, self::UINT8]])
            . $this->data(1, pack('VVVCC', 1000, 0xFFFFFFFF, 1024, 100, 101))
            . $this->definition(2, self::HR, [[253, 4, self::UINT32], [9, 4, self::UINT32], [6, 1, self::UINT8]])
            . $this->data(2, pack('VVC', 1002, 2048, 102));

        $this->assertSame([1001 => 101, 1002 => 102], $this->heartRate($bytes));
    }

    public function testInvalidFullTimesFallBackToTwelveBitOnes()
    {
        $bytes = $this->records(1005)
            . $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 4, self::UINT32], [6, 1, self::UINT8]])
            . $this->data(1, pack('VVC', 1000, 0, 100))
            . $this->definition(2, self::HR, [[9, 8, self::UINT32], [10, 3, self::BYTE], [6, 2, self::UINT8]])
            . $this->data(2, pack('VV', 0xFFFFFFFF, 0xFFFFFFFF) . substr($this->packTimes12([1024, 2048]), 0, 3) . pack('CC', 101, 102));

        $this->assertSame([1000 => 100, 1001 => 101, 1002 => 102], $this->heartRate($bytes));
    }

    public function testASingleHrMessageWithFullEventTimestamps()
    {
        // One hr message, event_timestamp[2] (no 12-bit form): beats at 1000.0 and 1001.0 s.
        $bytes = $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]])
            . $this->data(0, pack('Vv', 1000, 200)) . $this->data(0, pack('Vv', 1001, 200))
            . $this->definition(1, self::HR, [[253, 4, self::UINT32], [9, 8, self::UINT32], [6, 2, self::UINT8]])
            . $this->data(1, pack('VVVCC', 1000, 0, 1024, 120, 121));

        $pFFA = new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw']);

        $this->assertSame([1000 + FIT_UNIX_TS_DIFF => 120, 1001 + FIT_UNIX_TS_DIFF => 121], $pFFA->data_mesgs['record']['heart_rate']);
    }
}
