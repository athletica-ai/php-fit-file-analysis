<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * Each base type's invalid value means "no value": the field is dropped (a record) or null (a
 * padded message), and inside an array the element is null. Float and 64-bit invalid values never
 * matched before (all-ones unpacks to NaN, and uint64's constant was a float), and an array was
 * checked by its first element only.
 */
class InvalidValueTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const SESSION = 18;
    private const RECORD = 20;
    private const UINT8 = 0x02;
    private const UINT32 = 0x86;
    private const FLOAT32 = 0x88;
    private const FLOAT64 = 0x89;
    private const UINT64 = 0x8F;

    private function parse($bytes)
    {
        return new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw']);
    }

    private function ts($i)
    {
        return 1000 + $i + FIT_UNIX_TS_DIFF;
    }

    public function testFloatInvalidValuesAreDroppedAndValidFloatsScaled()
    {
        // speed (6) as float32 and altitude (2) as float64, as a definition may declare them.
        $record = $this->parse(
            $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [6, 4, self::FLOAT32], [2, 8, self::FLOAT64]])
            . $this->data(0, pack('V', 1000) . pack('g', 2500.0) . pack('e', 3000.0))
            . $this->data(0, pack('V', 1001) . str_repeat("\xFF", 4) . str_repeat("\xFF", 8))
            . $this->data(0, pack('V', 1002) . pack('g', 3000.0) . pack('e', 3005.0))
        )->data_mesgs['record'];

        $this->assertEqualsWithDelta(2.5, $record['speed'][$this->ts(0)], 1e-9);
        $this->assertEqualsWithDelta(3000.0 / 5 - 500, $record['altitude'][$this->ts(0)], 1e-9);
        $this->assertSame([$this->ts(0), $this->ts(2)], array_keys($record['speed']));
        $this->assertSame([$this->ts(0), $this->ts(2)], array_keys($record['altitude']));
    }

    public function testUint64InvalidValueIsDropped()
    {
        // power (7) declared uint64.
        $record = $this->parse(
            $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [7, 8, self::UINT64]])
            . $this->data(0, pack('VP', 1000, 250))
            . $this->data(0, pack('V', 1001) . str_repeat("\xFF", 8))
            . $this->data(0, pack('VP', 1002, 260))
        )->data_mesgs['record'];

        $this->assertSame([$this->ts(0) => 250, $this->ts(2) => 260], $record['power']);
    }

    public function testAnInvalidArrayElementIsNullAndTheOthersAreKept()
    {
        // time_in_hr_zone (65): uint32[3], scale 1000.
        $session = $this->parse(
            $this->definition(0, self::SESSION, [[253, 4, self::UINT32], [65, 12, self::UINT32]])
            . $this->data(0, pack('VVVV', 1000, 60000, 0xFFFFFFFF, 120000))
        )->data_mesgs['session'];

        $this->assertSame([60, null, 120], $session['time_in_hr_zone']);
    }

    public function testAnArrayWithEveryElementInvalidIsNoValue()
    {
        $session = $this->parse(
            $this->definition(0, self::SESSION, [[253, 4, self::UINT32], [65, 8, self::UINT32], [16, 1, self::UINT8]])
            . $this->data(0, pack('VVVC', 1000, 0xFFFFFFFF, 0xFFFFFFFF, 140))
        )->data_mesgs['session'];

        $this->assertNull($session['time_in_hr_zone']);
        $this->assertSame(140, $session['avg_heart_rate']);
    }

    public function testAUint64zValueWithTheHighBitSetIsPositive()
    {
        // A developer field "serial" (uint64z, 0x90) declaring no native field; 2^63 + 5.
        $bytes = $this->definition(5, 207, [[3, 1, self::UINT8]]) . $this->data(5, pack('C', 0))
            . $this->definition(1, 206, [[0, 1, self::UINT8], [1, 1, self::UINT8], [2, 1, self::UINT8], [3, 7, 0x07]])
            . $this->data(1, pack('CCC', 0, 0, 0x90) . "serial\0")
            . $this->definition(0, self::RECORD, [[253, 4, self::UINT32]], [[0, 8, 0]])
            . $this->data(0, pack('VP', 1000, PHP_INT_MIN + 5));

        $serial = $this->parse($bytes)->data_mesgs['developer_data']['serial']['data'][0];

        $this->assertEqualsWithDelta(9223372036854775813.0, $serial, 1e4);
    }
}
