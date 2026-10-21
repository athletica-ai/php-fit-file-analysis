<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * Public helpers on small files: one lap (fields unwrapped to scalars), 'always_arrays', the
 * debug data, JSON output, and histogram/partition buckets.
 */
class PublicApiTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const LAP = 19;
    private const RECORD = 20;
    private const EVENT = 21;
    private const ENUM = 0x00;
    private const UINT8 = 0x02;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    /** Records 1000-1004 (speed in mm/s, heart rate) and one lap. */
    private function oneLapFile()
    {
        $bytes = $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [6, 2, self::UINT16], [3, 1, self::UINT8]]);
        foreach ([1200, 1250, 2600, 2750, 3100] as $i => $speed) {
            $bytes .= $this->data(0, pack('VvC', 1000 + $i, $speed, 140 + $i));
        }
        $bytes .= $this->definition(1, self::LAP, [[253, 4, self::UINT32], [2, 4, self::UINT32], [15, 1, self::UINT8]])
            . $this->data(1, pack('VVC', 1004, 1000, 142));

        return $this->fitFile($bytes);
    }

    private function parse($file, array $options = [])
    {
        return new adriangibbons\phpFITFileAnalysis($file, $options + ['input_is_data' => true, 'units' => 'raw']);
    }

    public function testOneValueFieldsAreScalarsUnlessAlwaysArrays()
    {
        $this->assertSame(142, $this->parse($this->oneLapFile())->data_mesgs['lap']['avg_heart_rate']);

        $lap = $this->parse($this->oneLapFile(), ['always_arrays' => true])->data_mesgs['lap'];
        $this->assertSame([142], $lap['avg_heart_rate']);
        $this->assertSame([1004 + FIT_UNIX_TS_DIFF], $lap['timestamp']);
    }

    public function testLapJsonForAOneLapFile()
    {
        // count() of the unwrapped (int) lap timestamp was a TypeError.
        $json = json_decode($this->parse($this->oneLapFile())->getJSONLap(), true);

        $this->assertSame(1, $json['num_laps']);
        $this->assertSame(142, $json['data'][0]['avg_heart_rate']);
    }

    public function testRecordJsonHasTheRequestedFieldsOnly()
    {
        $json = json_decode($this->parse($this->oneLapFile())->getJSON(null, null, ['timestamp', 'heart_rate']), true);

        $this->assertCount(5, $json['data']);
        $this->assertSame(['timestamp' => 1000 + FIT_UNIX_TS_DIFF, 'heart_rate' => 140], $json['data'][0]);
    }

    public function testDebugInfoIsData()
    {
        $info = $this->parse($this->oneLapFile())->debugInfo();

        $this->assertSame(adriangibbons\Profile::VERSION, $info['profile_version']);
        $this->assertSame('record', $info['definitions'][0]['mesg_name']);
        $this->assertSame('speed', $info['definitions'][0]['fields'][1]['field_name']);
        $this->assertSame(5, $info['messages']['record']['heart_rate']);
    }

    public function testHistogramBucketsOfAFractionalWidthAreNotTruncated()
    {
        $histogram = $this->parse($this->oneLapFile())->histogram(0.5, 'speed');

        // 1.2, 1.25, 2.6, 2.75, 3.1 m/s to the nearest half (round() rounds a half up).
        $this->assertSame([0 => 0, '0.5' => 0, 1 => 1, '1.5' => 1, 2 => 0, '2.5' => 1, 3 => 2], $histogram);
    }

    public function testPausedSecondsAreNotCountedInTheLowestZone()
    {
        // Five seconds at 140-144 bpm, a 90 s timer stop, five more.
        $bytes = $this->definition(1, self::EVENT, [[253, 4, self::UINT32], [0, 1, self::ENUM], [1, 1, self::ENUM]])
            . $this->data(1, pack('VCC', 1000, 0, 0)) . $this->data(1, pack('VCC', 1005, 0, 4)) . $this->data(1, pack('VCC', 1095, 0, 0))
            . $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [3, 1, self::UINT8]]);
        foreach (array_merge(range(1000, 1004), range(1095, 1099)) as $ts) {
            $bytes .= $this->data(0, pack('VC', $ts, 140 + $ts % 5));
        }

        $zones = $this->parse($this->fitFile($bytes), ['data_every_second' => true])->partitionData('heart_rate', [100, 150], false);

        $this->assertSame(['0-99' => 0, '100-149' => 10, '150+' => 0], $zones);
    }
}
