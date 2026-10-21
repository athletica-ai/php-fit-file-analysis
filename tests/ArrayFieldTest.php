<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * Multi-value fields keep every value. A record's array field (power phase: start and end angle)
 * used to keep its first element only, and a developer field its first value.
 */
class ArrayFieldTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const RECORD = 20;
    private const FIELD_DESCRIPTION = 206;
    private const DEVELOPER_DATA_ID = 207;
    private const UINT8 = 0x02;
    private const STRING = 0x07;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    private function ts($i)
    {
        return 1000 + $i + FIT_UNIX_TS_DIFF;
    }

    public function testARecordArrayFieldKeepsEveryElement()
    {
        // left_power_phase (69): uint8[2], scale 0.7111111 (256 units per turn).
        $file = $this->fitFile(
            $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [69, 2, self::UINT8]])
            . $this->data(0, pack('VCC', 1000, 64, 128))
            . $this->data(0, pack('VCC', 1001, 32, 0xFF))
        );

        $phase = (new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['record']['left_power_phase'];

        $this->assertEqualsWithDelta([90.0, 180.0], $phase[$this->ts(0)], 0.001);
        $this->assertEqualsWithDelta(45.0, $phase[$this->ts(1)][0], 0.001);
        $this->assertNull($phase[$this->ts(1)][1]);
    }

    /**
     * A developer field "balance" (uint16[2], scale 10) on two records, the second with its second
     * value invalid.
     */
    private function developerArrayFile()
    {
        return $this->fitFile(
            $this->definition(5, self::DEVELOPER_DATA_ID, [[3, 1, self::UINT8]]) . $this->data(5, pack('C', 0))
            . $this->definition(4, self::FIELD_DESCRIPTION, [[0, 1, self::UINT8], [1, 1, self::UINT8], [2, 1, self::UINT8], [3, 8, self::STRING], [6, 1, self::UINT8]])
            . $this->data(4, pack('CCC', 0, 0, self::UINT16) . "Balance\0" . pack('C', 10))
            . $this->definition(0, self::RECORD, [[253, 4, self::UINT32]], [[0, 4, 0]])
            . $this->data(0, pack('Vvv', 1000, 495, 505))
            . $this->data(0, pack('Vvv', 1001, 480, 0xFFFF))
        );
    }

    public function testADeveloperArrayFieldKeepsEveryRawValue()
    {
        $developer = (new adriangibbons\phpFITFileAnalysis($this->developerArrayFile(), ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['developer_data'];

        $this->assertSame([[495, 505], [480, 0xFFFF]], $developer['balance']['data']);
    }

    public function testDeveloperValuesByMessageAreScaledAndKeyedLikeNativeFields()
    {
        $developer = (new adriangibbons\phpFITFileAnalysis($this->developerArrayFile(), ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['developer_data'];

        $byRecord = $developer['balance']['by_mesg']['record'];
        $this->assertEqualsWithDelta([49.5, 50.5], $byRecord[$this->ts(0)], 1e-9);
        $this->assertEqualsWithDelta(48.0, $byRecord[$this->ts(1)][0], 1e-9);
        $this->assertNull($byRecord[$this->ts(1)][1]);
    }
}
