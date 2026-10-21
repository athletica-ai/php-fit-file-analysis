<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * Signed integers are sign-extended as they are decoded, before scale and offset. They used to be
 * unpacked unsigned, scaled, and only then "sign-fixed" by comparing against 0x7FFF -- which a
 * scaled value never exceeds, so a -5 % grade read as 650.36.
 */
class SignedFieldTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const RECORD = 20;
    private const SINT8 = 0x01;
    private const SINT16 = 0x83;
    private const SINT32 = 0x85;
    private const UINT32 = 0x86;

    private function recordFile($bigEndian)
    {
        $fields = [[253, 4, self::UINT32], [9, 2, self::SINT16], [32, 2, self::SINT16], [13, 1, self::SINT8], [0, 4, self::SINT32]];
        $u16 = $bigEndian ? 'n' : 'v';
        $u32 = $bigEndian ? 'N' : 'V';
        $record = fn($ts, $grade, $verticalSpeed, $temperature, $lat) => $this->data(0, pack($u32, $ts) . pack($u16, $grade & 0xFFFF) . pack($u16, $verticalSpeed & 0xFFFF) . pack('C', $temperature & 0xFF) . pack($u32, $lat & 0xFFFFFFFF));

        return $this->fitFile(
            $this->definition(0, self::RECORD, $fields, [], $bigEndian)
            . $record(1000, -500, -250, -3, -123456789)
            . $record(1001, 300, 100, 21, 123456789)
            . $record(1002, 0x7FFF, 0x7FFF, 0x7F, 0x7FFFFFFF)  // every field invalid
        );
    }

    private function record($bigEndian)
    {
        return (new adriangibbons\phpFITFileAnalysis($this->recordFile($bigEndian), ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['record'];
    }

    public static function architectures()
    {
        return ['little-endian' => [false], 'big-endian' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('architectures')]
    public function testNegativeScaledValuesKeepTheirSign($bigEndian)
    {
        $record = $this->record($bigEndian);
        $ts = 1000 + FIT_UNIX_TS_DIFF;

        $this->assertEqualsWithDelta(-5.0, $record['grade'][$ts], 1e-9);
        $this->assertEqualsWithDelta(-0.25, $record['vertical_speed'][$ts], 1e-9);
        $this->assertSame(-3, $record['temperature'][$ts]);
        $this->assertSame(-123456789, $record['position_lat'][$ts]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('architectures')]
    public function testPositiveValuesAreUnchanged($bigEndian)
    {
        $record = $this->record($bigEndian);
        $ts = 1001 + FIT_UNIX_TS_DIFF;

        $this->assertEqualsWithDelta(3.0, $record['grade'][$ts], 1e-9);
        $this->assertEqualsWithDelta(0.1, $record['vertical_speed'][$ts], 1e-9);
        $this->assertSame(21, $record['temperature'][$ts]);
        $this->assertSame(123456789, $record['position_lat'][$ts]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('architectures')]
    public function testTheSignedInvalidValueIsDropped($bigEndian)
    {
        $record = $this->record($bigEndian);
        $ts = 1002 + FIT_UNIX_TS_DIFF;

        foreach (['grade', 'vertical_speed', 'temperature', 'position_lat'] as $field) {
            $this->assertArrayNotHasKey($ts, $record[$field], $field);
        }
    }

    public function testDegreesAreSignedAfterUnitConversion()
    {
        $record = (new adriangibbons\phpFITFileAnalysis($this->recordFile(false), ['input_is_data' => true]))->data_mesgs['record'];

        $this->assertEqualsWithDelta(-123456789 * 180 / 2 ** 31, $record['position_lat'][1000 + FIT_UNIX_TS_DIFF], 1e-5);
    }
}
