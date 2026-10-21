<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * The analysis helpers: best efforts include the last window, paused seconds are no samples, any
 * timer stop pauses, and gear changes on files without (or ending with) them.
 */
class AnalysisTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const RECORD = 20;
    private const EVENT = 21;
    private const ENUM = 0x00;
    private const UINT8 = 0x02;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    private const TIMER = 0;
    private const START = 0;
    private const STOP = 1;
    private const STOP_ALL = 4;

    /**
     * @param array $power  power by second from 1000
     * @param array $events [[timestamp, event, event_type, data], ...]
     */
    private function file(array $power, array $events = [], array $heartRate = [])
    {
        $bytes = $this->definition(1, self::EVENT, [[253, 4, self::UINT32], [0, 1, self::ENUM], [1, 1, self::ENUM], [3, 4, self::UINT32]]);
        foreach ($events as [$ts, $event, $type, $data]) {
            $bytes .= $this->data(1, pack('VCCV', $ts, $event, $type, $data));
        }
        $bytes .= $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16], [3, 1, self::UINT8]]);
        foreach ($power as $ts => $watts) {
            $bytes .= $this->data(0, pack('VvC', $ts, $watts, $heartRate[$ts] ?? 0xFF));
        }

        return $this->fitFile($bytes);
    }

    private function parse($file, array $options = [])
    {
        return new adriangibbons\phpFITFileAnalysis($file, $options + ['input_is_data' => true, 'units' => 'raw']);
    }

    private function seconds($from, $to, $value)
    {
        return array_fill_keys(range($from, $to), $value);
    }

    public function testABestEffortIncludesTheLastWindow()
    {
        $pFFA = $this->parse($this->file([1000 => 100, 1001 => 100, 1002 => 100, 1003 => 400]));

        $this->assertEquals([2 => 250], $pFFA->criticalPower([2]));
    }

    public function testABestEffortAsLongAsTheRideIsItsAverage()
    {
        // The window was dropped, leaving max() of nothing: a ValueError.
        $pFFA = $this->parse($this->file([1000 => 100, 1001 => 200, 1002 => 300, 1003 => 400]));

        $this->assertEquals([4 => 250], $pFFA->criticalPower(4));
        $this->assertEquals([2 => 350, 4 => 250], $pFFA->criticalPower([2, 4]));
    }

    /**
     * Ten seconds riding, a timer stop for 90 s, ten more. With data_every_second the paused
     * seconds are nulls.
     */
    private function pausedRide($stopType)
    {
        $power = $this->seconds(1000, 1009, 200) + $this->seconds(1100, 1109, 200);
        $hr = $this->seconds(1000, 1009, 150) + $this->seconds(1100, 1109, 150);
        $events = [[1000, self::TIMER, self::START, 0], [1010, self::TIMER, $stopType, 0], [1100, self::TIMER, self::START, 0]];

        return $this->file($power, $events, $hr);
    }

    public function testPausedSecondsAreNoWorkAndNoTime()
    {
        // 40 s riding, a 90 s timer stop, 40 more: long enough for normalised power's 30-s window.
        $power = $this->seconds(1000, 1039, 200) + $this->seconds(1130, 1169, 200);
        $events = [[1000, self::TIMER, self::START, 0], [1040, self::TIMER, self::STOP_ALL, 0], [1130, self::TIMER, self::START, 0]];
        $metrics = $this->parse($this->file($power, $events), ['data_every_second' => true])->powerMetrics(250);

        $this->assertSame(200, $metrics['Average Power']);
        $this->assertSame(16, $metrics['Kilojoules'], '80 s at 200 W, not 170 s');
    }

    public function testPausedSecondsAreNotZeroHeartRate()
    {
        $paused = $this->parse($this->pausedRide(self::STOP_ALL), ['data_every_second' => true]);
        $this->assertContains(null, $paused->data_mesgs['record']['heart_rate'], 'the fixture must have paused seconds');

        $metrics = $paused->hrMetrics(50, 190, 165, 'M');

        $this->assertEquals(round(150 / 165, 2), $metrics['hrIF']);
        $this->assertEquals($this->parse($this->pausedRide(self::STOP_ALL))->hrMetrics(50, 190, 165, 'M'), $metrics);
    }

    public function testAPlainTimerStopPauses()
    {
        $isPaused = $this->parse($this->pausedRide(self::STOP))->isPaused();

        $this->assertFalse($isPaused[1005 + FIT_UNIX_TS_DIFF]);
        $this->assertTrue($isPaused[1050 + FIT_UNIX_TS_DIFF]);
        $this->assertFalse($isPaused[1105 + FIT_UNIX_TS_DIFF]);
    }

    public function testAStartInTheSameSecondAsAStopDoesNotPauseTheRestOfTheRide()
    {
        // Stop and start at 1010 (start written second), then a real pause 1020-1030.
        $events = [[1000, self::TIMER, self::START, 0], [1010, self::TIMER, self::STOP_ALL, 0], [1010, self::TIMER, self::START, 0],
            [1020, self::TIMER, self::STOP_ALL, 0], [1030, self::TIMER, self::START, 0]];
        $isPaused = $this->parse($this->file($this->seconds(1000, 1040, 200), $events))->isPaused();

        $this->assertFalse($isPaused[1015 + FIT_UNIX_TS_DIFF]);
        $this->assertTrue($isPaused[1025 + FIT_UNIX_TS_DIFF]);
        $this->assertFalse($isPaused[1035 + FIT_UNIX_TS_DIFF]);
    }

    public function testTimerEventsBeforeTheFirstRecordStillCount()
    {
        $events = [[990, self::TIMER, self::START, 0], [995, self::TIMER, self::STOP_ALL, 0], [998, self::TIMER, self::START, 0],
            [1010, self::TIMER, self::STOP, 0], [1015, self::TIMER, self::START, 0]];
        $isPaused = $this->parse($this->file($this->seconds(1000, 1020, 200), $events))->isPaused();

        $this->assertFalse($isPaused[1005 + FIT_UNIX_TS_DIFF]);
        $this->assertTrue($isPaused[1012 + FIT_UNIX_TS_DIFF]);
        $this->assertFalse($isPaused[1017 + FIT_UNIX_TS_DIFF]);
    }

    public function testGearChangesInOneSecondOrDuringAPauseAllApply()
    {
        $gear = fn($rearNum, $rear) => $rearNum | ($rear << 8) | (1 << 16) | (50 << 24);
        // A synchro shift: three rear changes at 1005. Two more while paused 1010-1015.
        $events = [[1000, self::TIMER, self::START, 0], [1005, 43, 3, $gear(5, 17)], [1005, 43, 3, $gear(6, 15)], [1005, 43, 3, $gear(7, 13)],
            [1010, self::TIMER, self::STOP_ALL, 0], [1011, 43, 3, $gear(4, 19)], [1012, 43, 3, $gear(3, 21)], [1015, self::TIMER, self::START, 0]];

        $gears = $this->parse($this->file($this->seconds(1000, 1020, 200), $events))->gearChanges();

        $this->assertSame(13, $gears['gears_array'][1006 + FIT_UNIX_TS_DIFF]['rear_gear']);
        $this->assertSame(21, $gears['gears_array'][1015 + FIT_UNIX_TS_DIFF]['rear_gear']);
        $this->assertArrayNotHasKey(15, $gears['rear_gear_summary'], 'the middle of a synchro shift is never ridden');
    }

    public function testAOneRecordFileCanBeFixedAndAnalysed()
    {
        // Its fields are values, not lists (oneElementArrays()): fixData() count()ed one.
        $events = [[1000, self::TIMER, self::START, 0], [1000, 43, 3, 5 | (17 << 8) | (1 << 16) | (50 << 24)]];
        $pFFA = $this->parse($this->file([1000 => 200], $events), ['fix_data' => ['power']]);

        $this->assertSame(200, $pFFA->data_mesgs['record']['power']);
        $this->assertSame([1000 + FIT_UNIX_TS_DIFF => false], $pFFA->isPaused());
        $this->assertSame([], $pFFA->gearChanges(false)['gears_array']);
    }

    public function testAnInvalidFixDataOptionThrowsWhateverTheRecordCount()
    {
        $this->expectExceptionMessage('option not valid');
        $this->parse($this->file([1000 => 200]), ['fix_data' => ['bogus']]);
    }

    public function testAnInvalidFixDataOptionThrowsWithoutRecordsToo()
    {
        $bytes = $this->definition(1, self::EVENT, [[253, 4, self::UINT32], [0, 1, self::ENUM], [1, 1, self::ENUM], [3, 4, self::UINT32]])
            . $this->data(1, pack('VCCV', 1000, self::TIMER, self::START, 0));

        $this->expectExceptionMessage('option not valid');
        $this->parse($this->fitFile($bytes), ['fix_data' => ['bogus']]);
    }

    public function testDataEverySecondFalseAsAStringIsOff()
    {
        // 'false' is off, as false is; with no fix_data it reached array_walk(null).
        $two = $this->parse($this->file([1000 => 200, 1005 => 210]), ['data_every_second' => 'false']);
        $this->assertCount(2, $two->data_mesgs['record']['timestamp']);

        $one = $this->parse($this->file([1000 => 200]), ['data_every_second' => 'false']);
        $this->assertSame(200, $one->data_mesgs['record']['power']);
    }

    public function testGearChangesWithoutRecordsIsAnEmptySummary()
    {
        $bytes = $this->definition(1, self::EVENT, [[253, 4, self::UINT32], [0, 1, self::ENUM], [1, 1, self::ENUM], [3, 4, self::UINT32]])
            . $this->data(1, pack('VCCV', 1000, 43, 3, 5 | (17 << 8))) . $this->data(1, pack('VCCV', 1001, 43, 3, 6 | (15 << 8)));

        $this->assertSame([], $this->parse($this->fitFile($bytes))->gearChanges()['gears_array']);
    }

    public function testStatutePaceOfAStandstillIsZero()
    {
        // speed (6, uint16 mm/s) 0 then 2.5 m/s; 'statute' with 'pace' divided by the speed.
        $bytes = $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [6, 2, self::UINT16]])
            . $this->data(0, pack('Vv', 1000, 0)) . $this->data(0, pack('Vv', 1001, 2500));
        $speed = $this->parse($this->fitFile($bytes), ['units' => 'statute', 'pace' => true])->data_mesgs['record']['speed'];

        $this->assertSame(0, $speed[1000 + FIT_UNIX_TS_DIFF]);
        $this->assertSame(round(60 / 2.23693629 / 2.5, 3), $speed[1001 + FIT_UNIX_TS_DIFF]);
    }

    public function testNoGearChangesIsAnEmptySummary()
    {
        $gears = $this->parse($this->file($this->seconds(1000, 1010, 200)))->gearChanges();

        $this->assertSame(['front_gear_summary' => [], 'rear_gear_summary' => [], 'combined_summary' => [], 'gears_array' => []], $gears);
    }

    public function testTheLastGearChangeIsRiddenIn()
    {
        // rear_gear_change (43): data = rear_gear_num | rear_gear << 8 | front_gear_num << 16 | front_gear << 24
        $gear = fn($rearNum, $rear) => $rearNum | ($rear << 8) | (1 << 16) | (50 << 24);
        $events = [[1000, self::TIMER, self::START, 0], [1005, 43, 3, $gear(5, 17)], [1008, 43, 3, $gear(6, 15)]];

        $gears = $this->parse($this->file($this->seconds(1000, 1020, 200), $events))->gearChanges(false);

        $this->assertSame(17, $gears['gears_array'][1007 + FIT_UNIX_TS_DIFF]['rear_gear']);
        $this->assertSame(15, $gears['gears_array'][1015 + FIT_UNIX_TS_DIFF]['rear_gear']);
        $this->assertSame(3, $gears['rear_gear_summary'][17], 'seconds 1006-1008 only: the change at 1008 is ridden in');
    }
}
