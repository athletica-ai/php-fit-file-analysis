<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * developer_data[name]: 'units', 'data' (every raw value in file order) and 'by_mesg' (per message,
 * scaled, null when invalid: keyed by timestamp for records, one entry per message otherwise).
 * A developer field declaring a native field is copied into the record stream under its own name
 * when the record has no field of that name, as it always has been.
 */
class DeveloperDataTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const LAP = 19;
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

    private function developerDataId()
    {
        return $this->definition(5, self::DEVELOPER_DATA_ID, [[3, 1, self::UINT8]]) . $this->data(5, pack('C', 0));
    }

    /** A uint16 developer field $name, declaring record field $nativeField when given. */
    private function description($localType, $number, $name, $nativeField = null)
    {
        $fields = [[0, 1, self::UINT8], [1, 1, self::UINT8], [2, 1, self::UINT8], [3, strlen($name), self::STRING]];
        $payload = pack('CCC', 0, $number, self::UINT16) . $name;
        if ($nativeField !== null) {
            $fields[] = [14, 2, self::UINT16];
            $fields[] = [15, 1, self::UINT8];
            $payload .= pack('vC', self::RECORD, $nativeField);
        }

        return $this->definition($localType, self::FIELD_DESCRIPTION, $fields) . $this->data($localType, $payload);
    }

    private function parse($bytes)
    {
        return new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw']);
    }

    public function testRecordValuesAreKeyedByTimestampAndScaled()
    {
        $bytes = $this->developerDataId() . $this->description(0, 0, "form power\0")
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32]], [[0, 2, 0]])
            . $this->data(1, pack('Vv', 1000, 50)) . $this->data(1, pack('Vv', 1001, 0xFFFF));

        $developer = $this->parse($bytes)->data_mesgs['developer_data']['form power'];

        $this->assertSame([50, 0xFFFF], $developer['data'], 'raw, invalid included');
        $this->assertSame([$this->ts(0) => 50, $this->ts(1) => null], $developer['by_mesg']['record']);
    }

    /** Records that carried the field before its description get their values when it arrives. */
    public function testRecordsCarryingTheFieldBeforeItsDescriptionAreDecoded()
    {
        $bytes = $this->developerDataId()
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32]], [[0, 2, 0]]);
        for ($i = 0; $i < 10; $i++) {
            if ($i === 5) {
                $bytes .= $this->description(0, 0, "form power\0");
            }
            $bytes .= $this->data(1, pack('Vv', 1000 + $i, 50 + $i));
        }

        $expected = [];
        for ($i = 0; $i < 10; $i++) {
            $expected[$this->ts($i)] = 50 + $i;
        }
        $this->assertSame($expected, $this->parse($bytes)->data_mesgs['developer_data']['form power']['by_mesg']['record']);
    }

    public function testDeveloperValuesOfLapsLineUpWithTheLaps()
    {
        $lapWithDev = $this->definition(1, self::LAP, [[253, 4, self::UINT32]], [[0, 2, 0]]);
        $lapWithout = $this->definition(2, self::LAP, [[253, 4, self::UINT32]]);
        $bytes = $lapWithDev . $lapWithout . $this->developerDataId()
            . $this->data(2, pack('V', 1000))  // lap 0: before the description too
            . $this->description(0, 0, "lap note\0")
            . $this->data(1, pack('Vv', 1010, 210))
            . $this->data(2, pack('V', 1020))
            . $this->data(1, pack('Vv', 1030, 230))
            . $this->data(2, pack('V', 1040));

        $this->assertSame([null, 210, null, 230, null], $this->parse($bytes)->data_mesgs['developer_data']['lap note']['by_mesg']['lap']);
    }

    /** Two developer fields of one name in a lap (two apps' "power") keep one entry per lap: the first valid. */
    public function testTwoDeveloperFieldsOfOneNameKeepOneEntryPerLap()
    {
        $bytes = $this->developerDataId()
            . $this->description(0, 0, "power\0") . $this->description(4, 1, "power\0")
            . $this->definition(1, self::LAP, [[253, 4, self::UINT32]], [[0, 2, 0], [1, 2, 0]])
            . $this->data(1, pack('Vvv', 1000, 0xFFFF, 211))
            . $this->data(1, pack('Vvv', 1010, 220, 221))
            . $this->data(1, pack('Vvv', 1020, 230, 231));

        $this->assertSame([211, 220, 230], $this->parse($bytes)->data_mesgs['developer_data']['power']['by_mesg']['lap']);
    }

    public function testADeclaredFieldIsCopiedUnderItsNameOnlyWhenTheRecordLacksIt()
    {
        // "power" declares record field 7, which the records carry: kept (with the CX-87 override
        // off; by default it replaces power, see DeveloperFieldOverrideTest). "stryd cadence"
        // declares cadence (4), which they do not: copied in, as developer_data's raw list.
        $bytes = $this->developerDataId()
            . $this->description(0, 0, "power\0", 7) . $this->description(4, 1, "stryd cadence\0", 4)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0], [1, 2, 0]])
            . $this->data(1, pack('Vvvv', 1000, 300, 200, 85)) . $this->data(1, pack('Vvvv', 1001, 310, 210, 86));

        $record = (new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw', 'overwrite_with_dev_data' => false]))->data_mesgs['record'];

        $this->assertSame([$this->ts(0) => 300, $this->ts(1) => 310], $record['power']);
        $this->assertSame([85, 86], $record['stryd cadence']);
    }
}
