<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * FIT SDK decoding the library lacked:
 * - subfields: a field read under another name when a reference field holds a value (a running
 *   session's total_cycles is its total_strides), on by default ('expand_subfields');
 * - components: a field packing others (speed into enhanced_speed; compressed_speed_distance into
 *   speed and an accumulated distance; event data into gears). 'expand_components' defaults to
 *   false, none; 'enhanced' expands only into enhanced_* fields, true all.
 */
class SubfieldComponentTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const SESSION = 18;
    private const RECORD = 20;
    private const EVENT = 21;
    private const ENUM = 0x00;
    private const UINT8 = 0x02;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;
    private const BYTE = 0x0D;

    private function parse($bytes, array $options = [])
    {
        return new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), $options + ['input_is_data' => true, 'units' => 'raw']);
    }

    private function ts($i)
    {
        return 1000 + $i + FIT_UNIX_TS_DIFF;
    }

    public function testASessionsCyclesAndCadenceAreAlsoReadAsTheSportsOwn()
    {
        $session = $this->parse(
            $this->definition(0, self::SESSION, [[253, 4, self::UINT32], [5, 1, self::ENUM], [10, 4, self::UINT32], [18, 1, self::UINT8]])
            . $this->data(0, pack('VCVC', 1000, 1, 5000, 85))  // running
        )->data_mesgs['session'];

        $this->assertSame(5000, $session['total_cycles']);
        $this->assertSame(5000, $session['total_strides']);
        $this->assertSame(85, $session['avg_running_cadence']);
        $this->assertArrayNotHasKey('total_strokes', $session);
    }

    public function testSubfieldsCanBeTurnedOff()
    {
        $session = $this->parse(
            $this->definition(0, self::SESSION, [[253, 4, self::UINT32], [5, 1, self::ENUM], [10, 4, self::UINT32]])
            . $this->data(0, pack('VCV', 1000, 1, 5000)),
            ['expand_subfields' => false]
        )->data_mesgs['session'];

        $this->assertArrayNotHasKey('total_strides', $session);
    }

    private function speedAltitudeRecords()
    {
        return $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [6, 2, self::UINT16], [2, 2, self::UINT16]])
            . $this->data(0, pack('Vvv', 1000, 2500, 2600))
            . $this->data(0, pack('Vvv', 1001, 2750, 2605))
            // A record carrying enhanced_speed itself keeps it.
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [6, 2, self::UINT16], [73, 4, self::UINT32]])
            . $this->data(1, pack('VvV', 1002, 3000, 3100));
    }

    public function testSpeedAndAltitudeAlsoFillTheEnhancedFieldsOnRequest()
    {
        $record = $this->parse($this->speedAltitudeRecords(), ['expand_components' => 'enhanced'])->data_mesgs['record'];

        $this->assertEqualsWithDelta([$this->ts(0) => 2.5, $this->ts(1) => 2.75, $this->ts(2) => 3.1], $record['enhanced_speed'], 1e-9);
        $this->assertEqualsWithDelta([$this->ts(0) => 20.0, $this->ts(1) => 21.0], $record['enhanced_altitude'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $record['speed'][$this->ts(2)], 1e-9);
    }

    public function testComponentsAreOffByDefault()
    {
        // On, each would be a second copy of the stream, held, filled in and converted.
        $record = $this->parse($this->speedAltitudeRecords())->data_mesgs['record'];

        $this->assertArrayNotHasKey('enhanced_altitude', $record);
        // Only the record that carried it (one value, so unwrapped by oneElementArrays()).
        $this->assertEqualsWithDelta(3.1, $record['enhanced_speed'], 1e-9);
    }

    /** compressed_speed_distance: 12 bits of speed (1/100 m/s), then 12 of distance (1/16 m, accumulated). */
    private function compressed($speed, $distance)
    {
        $bits = $speed | ($distance << 12);

        return pack('CCC', $bits & 0xFF, ($bits >> 8) & 0xFF, ($bits >> 16) & 0xFF);
    }

    public function testCompressedSpeedDistanceExpandsWithTheDistanceAccumulated()
    {
        $bytes = $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [8, 3, self::BYTE]]);
        foreach ([[250, 4000], [300, 100], [320, 1700]] as $i => [$speed, $distance]) {
            $bytes .= $this->data(0, pack('V', 1000 + $i) . $this->compressed($speed, $distance));
        }

        $record = $this->parse($bytes, ['expand_components' => true])->data_mesgs['record'];

        $this->assertEqualsWithDelta([$this->ts(0) => 2.5, $this->ts(1) => 3.0, $this->ts(2) => 3.2], $record['speed'], 1e-9);
        // 4000/16 m; then 100 is past a 12-bit rollover: +196 raw; then +1600 raw.
        $this->assertEqualsWithDelta([$this->ts(0) => 250.0, $this->ts(1) => 262.25, $this->ts(2) => 362.25], $record['distance'], 1e-9);
        // The packed field has a scale per component (100, 16), so it is itself left raw, as the SDK leaves it.
        $bits = 250 | (4000 << 12);
        $this->assertSame([$bits & 0xFF, ($bits >> 8) & 0xFF, ($bits >> 16) & 0xFF], $record['compressed_speed_distance'][$this->ts(0)]);
    }

    private function gearChangeEvent()
    {
        // front_gear_change: rear_gear_num 5, rear_gear 17, front_gear_num 2, front_gear 50.
        return $this->definition(0, self::EVENT, [[253, 4, self::UINT32], [0, 1, self::ENUM], [1, 1, self::ENUM], [3, 4, self::UINT32]])
            . $this->data(0, pack('VCCV', 1000, 42, 3, 5 | (17 << 8) | (2 << 16) | (50 << 24)))
            . $this->data(0, pack('VCCV', 1001, 0, 0, 0));  // timer start: data is a timer_trigger
    }

    public function testEventDataIsReadByEventAndGearsExpandOnRequest()
    {
        $event = $this->parse($this->gearChangeEvent())->data_mesgs['event'];
        $this->assertSame([5 | (17 << 8) | (2 << 16) | (50 << 24), null], $event['gear_change_data']);
        $this->assertSame([null, 0], $event['timer_trigger']);
        $this->assertArrayNotHasKey('rear_gear', $event);

        $event = $this->parse($this->gearChangeEvent(), ['expand_components' => true])->data_mesgs['event'];
        $this->assertSame([17, null], $event['rear_gear']);
        $this->assertSame([5, null], $event['rear_gear_num']);
        $this->assertSame([50, null], $event['front_gear']);
        $this->assertSame([2, null], $event['front_gear_num']);
    }

    public function testStatuteUnitsConvertTheEnhancedAltitudeToo()
    {
        $record = $this->parse($this->speedAltitudeRecords(), ['units' => 'statute', 'expand_components' => 'enhanced'])->data_mesgs['record'];

        $this->assertEqualsWithDelta(round(20.0 * 3.2808399, 1), $record['enhanced_altitude'][$this->ts(0)], 1e-9);
    }
}
