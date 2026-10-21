<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * APP-1788: every lap field holds one entry per lap message, so index $i is lap $i in all of them.
 *
 * Built from a synthetic file rather than a demo file: none of the demo files has a lap with a
 * missing value, and the real-world files that do (triathlons, HR dropouts) carry athletes' GPS.
 */
class LapFieldAlignmentTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const LAP = 19;
    private const UINT8 = 0x02;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    /**
     * Three laps. Lap 0 has no valid power, lap 1 no valid HR, and lap 2 comes from a second
     * definition that adds enhanced_avg_speed and drops power altogether.
     */
    private function threeLapFile()
    {
        $withPower = [[253, 4, self::UINT32], [2, 4, self::UINT32], [15, 1, self::UINT8], [19, 2, self::UINT16]];
        $withSpeed = [[253, 4, self::UINT32], [2, 4, self::UINT32], [15, 1, self::UINT8], [110, 4, self::UINT32]];

        return $this->fitFile(
            $this->definition(0, self::LAP, $withPower)
            . $this->data(0, pack('VVCv', 1000, 900, 140, 0xFFFF))
            . $this->data(0, pack('VVCv', 1100, 1000, 0xFF, 200))
            . $this->definition(1, self::LAP, $withSpeed)
            . $this->data(1, pack('VVCV', 1200, 1100, 150, 3500))
        );
    }

    public function testAFieldMissingFromSomeLapsKeepsItsOtherValuesOnTheirOwnLaps()
    {
        $lap = (new adriangibbons\phpFITFileAnalysis($this->threeLapFile(), ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['lap'];

        $this->assertSame([140, null, 150], $lap['avg_heart_rate']);
        $this->assertSame([null, 200, null], $lap['avg_power']);
    }

    public function testAFieldFirstSeenOnALaterLapIsNullForTheEarlierLaps()
    {
        $lap = (new adriangibbons\phpFITFileAnalysis($this->threeLapFile(), ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['lap'];

        $this->assertSame([null, null, 3.5], $lap['enhanced_avg_speed']);
    }

    public function testEveryLapFieldHasOneEntryPerLap()
    {
        $lap = (new adriangibbons\phpFITFileAnalysis($this->threeLapFile(), ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['lap'];

        $this->assertSame([3], array_values(array_unique(array_map('count', $lap))));
    }

    /** The FIT-to-Unix epoch shift must not turn a missing start_time into a real timestamp. */
    public function testAMissingLapStartTimeStaysNull()
    {
        $fields = [[253, 4, self::UINT32], [2, 4, self::UINT32]];
        $file = $this->fitFile(
            $this->definition(0, self::LAP, $fields)
            . $this->data(0, pack('VV', 1000, 900))
            . $this->data(0, pack('VV', 1100, 0xFFFFFFFF))
        );

        $lap = (new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['lap'];

        $this->assertSame(900 + FIT_UNIX_TS_DIFF, $lap['start_time'][0]);
        $this->assertNull($lap['start_time'][1]);
    }

    /** Statute pace divides by the speed, so a lap with no speed must be skipped, not divided by. */
    public function testUnitConversionLeavesAMissingLapValueNull()
    {
        $pFFA = new adriangibbons\phpFITFileAnalysis($this->threeLapFile(), ['input_is_data' => true, 'units' => 'statute', 'pace' => true]);

        $this->assertNull($pFFA->data_mesgs['lap']['enhanced_avg_speed'][0]);
        $this->assertEqualsWithDelta(60 / 2.23693629 / 3.5, $pFFA->data_mesgs['lap']['enhanced_avg_speed'][2], 0.001);
    }

}
