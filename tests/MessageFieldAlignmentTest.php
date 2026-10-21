<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * APP-1788 kept lap fields one entry per lap. Sessions and lengths had the same bug: a field
 * invalid on one message was skipped, shifting every later value onto the wrong session -- a brick
 * whose run leg has no power showed the bike's power on the run -- or the wrong swim length.
 */
class MessageFieldAlignmentTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const SESSION = 18;
    private const LENGTH = 101;
    private const UINT8 = 0x02;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    private function parse($bytes)
    {
        return new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw']);
    }

    public function testEachSessionKeepsItsOwnValues()
    {
        $session = $this->parse(
            $this->definition(0, self::SESSION, [[253, 4, self::UINT32], [16, 1, self::UINT8], [20, 2, self::UINT16], [9, 4, self::UINT32]])
            . $this->data(0, pack('VCvV', 1000, 140, 0xFFFF, 100000))
            . $this->data(0, pack('VCvV', 2000, 0xFF, 200, 0xFFFFFFFF))
        )->data_mesgs['session'];

        $this->assertSame([140, null], $session['avg_heart_rate']);
        $this->assertSame([null, 200], $session['avg_power']);
        $this->assertSame([1000, null], $session['total_distance']);
    }

    public function testTheSessionFieldsCallersRelyOnArePresentEvenWithoutAValue()
    {
        $session = $this->parse(
            $this->definition(0, self::SESSION, [[253, 4, self::UINT32], [16, 1, self::UINT8]])
            . $this->data(0, pack('VC', 1000, 140))
        )->data_mesgs['session'];

        $this->assertArrayHasKey('avg_power', $session);
        $this->assertNull($session['avg_power']);
        $this->assertSame(140, $session['avg_heart_rate']);
    }

    public function testEachLengthKeepsItsOwnValues()
    {
        // avg_speed (6, uint16 scale 1000): an idle length in the middle has none.
        $length = $this->parse(
            $this->definition(0, self::LENGTH, [[253, 4, self::UINT32], [6, 2, self::UINT16]])
            . $this->data(0, pack('Vv', 1000, 1200))
            . $this->data(0, pack('Vv', 1030, 0xFFFF))
            . $this->data(0, pack('Vv', 1060, 1100))
        )->data_mesgs['length'];

        $this->assertSame([1.2, null, 1.1], $length['avg_speed']);
    }

    public function testWorkoutFeelAndRpeAreTheSdkFieldsUnscaled()
    {
        $session = $this->parse(
            $this->definition(0, self::SESSION, [[253, 4, self::UINT32], [192, 1, self::UINT8], [193, 1, self::UINT8]])
            . $this->data(0, pack('VCC', 1000, 75, 70))
        )->data_mesgs['session'];

        $this->assertSame(75, $session['workout_feel']);
        $this->assertSame(70, $session['workout_rpe']);
        // Once returned as 'feel' / 'rpe' at 1/10th scale.
        $this->assertArrayNotHasKey('feel', $session);
        $this->assertArrayNotHasKey('rpe', $session);
    }
}
