<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * A compressed timestamp header carries a 5-bit offset counted on from the previous timestamp in
 * the file -- of any message, and including one that was itself compressed. The reference used to
 * come from record messages only and never advanced past a compressed record, so a run of
 * compressed records spanning more than 32 s lost time; and other messages got no timestamp.
 */
class CompressedTimestampTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const RECORD = 20;
    private const EVENT = 21;
    private const ENUM = 0x00;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    /** An event at $start (normal header) gives the reference; then compressed records at $times. */
    private function file($start, array $times, $extra = '')
    {
        $bytes = $this->definition(1, self::EVENT, [[253, 4, self::UINT32], [0, 1, self::ENUM], [1, 1, self::ENUM]])
            . $this->data(1, pack('VCC', $start, 0, 0))
            . $this->definition(0, self::RECORD, [[7, 2, self::UINT16]]);
        foreach ($times as $i => $time) {
            $bytes .= $this->compressed(0, $time & 0x1F, pack('v', 200 + $i));
        }

        return $this->fitFile($bytes . $extra);
    }

    private function recordTimestamps($file, array $options = [])
    {
        $pFFA = new adriangibbons\phpFITFileAnalysis($file, $options + ['input_is_data' => true, 'units' => 'raw']);

        return array_values($pFFA->data_mesgs['record']['timestamp']);
    }

    public function testTheReferenceComesFromANonRecordMessageAndRollsOverAt32Seconds()
    {
        // 1000 is 8 past a 32 s boundary; 1026 is past the next one.
        $times = [1001, 1002, 1026];

        $this->assertSame(array_map(fn($t) => $t + FIT_UNIX_TS_DIFF, $times), $this->recordTimestamps($this->file(1000, $times)));
    }

    public function testARunOfCompressedRecordsLongerThan32SecondsKeepsCounting()
    {
        $times = [1010, 1020, 1030, 1040, 1050, 1060];

        $this->assertSame(array_map(fn($t) => $t + FIT_UNIX_TS_DIFF, $times), $this->recordTimestamps($this->file(1000, $times)));
    }

    public function testRecordValuesAreKeyedByTheCompressedTimestamp()
    {
        $pFFA = new adriangibbons\phpFITFileAnalysis($this->file(1000, [1005, 1010]), ['input_is_data' => true, 'units' => 'raw']);

        $this->assertSame([1005 + FIT_UNIX_TS_DIFF => 200, 1010 + FIT_UNIX_TS_DIFF => 201], $pFFA->data_mesgs['record']['power']);
    }

    public function testANonRecordMessageWithACompressedHeaderGetsItsTimestamp()
    {
        $event = $this->definition(2, self::EVENT, [[0, 1, self::ENUM], [1, 1, self::ENUM]])
            . $this->compressed(2, 1012 & 0x1F, pack('CC', 0, 4));
        $pFFA = new adriangibbons\phpFITFileAnalysis($this->file(1000, [1005], $event), ['input_is_data' => true, 'units' => 'raw']);

        $this->assertSame([1000 + FIT_UNIX_TS_DIFF, 1012 + FIT_UNIX_TS_DIFF], $pFFA->data_mesgs['event']['timestamp']);
    }

    public function testGarminTimestampsAreLeftInTheFitEpoch()
    {
        $this->assertSame([1001, 1020], $this->recordTimestamps($this->file(1000, [1001, 1020]), ['garmin_timestamps' => true]));
    }
}
