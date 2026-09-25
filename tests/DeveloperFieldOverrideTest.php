<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * CX-87: a developer field whose description declares native_mesg_num / native_field_num replaces
 * that native record field -- per record, keyed by the record's timestamp like any native field,
 * and the session's avg/max (and normalized power) then describe the stream that survived.
 *
 * The real reproducer is a Garmin running FIT recorded through the Stryd app: the watch writes its
 * own power estimate in record field 7 and Stryd writes a developer field named "power" declaring
 * native message 20, field 7. The two are not on one scale (the watch reads ~1.3x higher), so a
 * profile built from the wrong one moves every threshold. That file carries an athlete's GPS and
 * this repository is public, so it lives in the app repository's test data; this file is synthetic.
 */
class DeveloperFieldOverrideTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const SESSION = 18;
    private const RECORD = 20;
    private const FIELD_DESCRIPTION = 206;
    private const DEVELOPER_DATA_ID = 207;
    private const UINT8 = 0x02;
    private const STRING = 0x07;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    private const WATCH_POWER = 300;
    private const RECORDS = 40;
    private const INVALID_DEV_RECORD = 10;   // developer value present but invalid (0xFFFF)
    private const NATIVE_ONLY_RECORD = 20;   // written with a definition that has no developer fields
    private const HIGHER_DEV_RECORDS = [30, 31];

    private function ts($i)
    {
        return 1000 + $i + FIT_UNIX_TS_DIFF;
    }

    /**
     * Forty one-second records, the watch's power 300 on every one. A Stryd-style developer field
     * "power" (uint16, declaring native 20/7) reads 200, except: record 10 carries the invalid value,
     * record 20 is written with a definition that has no developer fields at all, and records 30-31
     * read 210. A second developer field "form power" declares no native field. The session message
     * carries the watch's own summary: avg 300, max 300, normalized 300.
     */
    private function strydStyleFile()
    {
        $powerDescription = $this->definition(0, self::FIELD_DESCRIPTION, [
            [0, 1, self::UINT8], [1, 1, self::UINT8], [2, 1, self::UINT8], [3, 6, self::STRING], [8, 6, self::STRING], [14, 2, self::UINT16], [15, 1, self::UINT8],
        ]);
        $formPowerDescription = $this->definition(4, self::FIELD_DESCRIPTION, [
            [0, 1, self::UINT8], [1, 1, self::UINT8], [2, 1, self::UINT8], [3, 11, self::STRING],
        ]);
        $developerDataId = $this->definition(5, self::DEVELOPER_DATA_ID, [[3, 1, self::UINT8]]);
        $recordWithDev = $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0], [1, 2, 0]]);
        $recordNativeOnly = $this->definition(2, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]]);
        $session = $this->definition(3, self::SESSION, [[253, 4, self::UINT32], [20, 2, self::UINT16], [21, 2, self::UINT16], [34, 2, self::UINT16]]);

        $bytes = $developerDataId . $this->data(5, pack('C', 0))
            . $powerDescription . $this->data(0, pack('CCC', 0, 0, self::UINT16) . "power\0" . "watts\0" . pack('vC', self::RECORD, 7))
            . $formPowerDescription . $this->data(4, pack('CCC', 0, 1, self::UINT16) . "form power\0")
            . $recordWithDev . $recordNativeOnly . $session;

        for ($i = 0; $i < self::RECORDS; $i++) {
            if ($i === self::NATIVE_ONLY_RECORD) {
                $bytes .= $this->data(2, pack('Vv', 1000 + $i, self::WATCH_POWER));
                continue;
            }
            $devPower = $i === self::INVALID_DEV_RECORD ? 0xFFFF : (in_array($i, self::HIGHER_DEV_RECORDS, true) ? 210 : 200);
            $bytes .= $this->data(1, pack('Vvvv', 1000 + $i, self::WATCH_POWER, $devPower, 50));
        }
        $bytes .= $this->data(3, pack('Vvvv', 1000 + self::RECORDS, self::WATCH_POWER, self::WATCH_POWER, self::WATCH_POWER));

        return $this->fitFile($bytes);
    }

    private function parse(array $options = [])
    {
        return new adriangibbons\phpFITFileAnalysis($this->strydStyleFile(), $options + ['input_is_data' => true, 'units' => 'raw']);
    }

    public function testADeclaredDeveloperFieldReplacesTheNativeRecordField()
    {
        $power = $this->parse()->data_mesgs['record']['power'];

        $this->assertSame(200, $power[$this->ts(0)], 'the developer value must be stored under the native name, keyed by timestamp');
        $this->assertSame(210, $power[$this->ts(30)]);
        $this->assertNotContains(self::WATCH_POWER, $power, 'the watch\'s own value must not survive anywhere in the stream');
        $this->assertCount(self::RECORDS - 2, $power);
    }

    public function testARecordWithoutAUsableDeveloperValueCarriesNoPowerRatherThanTheWatchValue()
    {
        $power = $this->parse()->data_mesgs['record']['power'];

        // Invalid developer value, and a record written without the developer field at all: the two
        // streams are not on one scale, so a single native sample would be a foreign reading.
        $this->assertArrayNotHasKey($this->ts(self::INVALID_DEV_RECORD), $power);
        $this->assertArrayNotHasKey($this->ts(self::NATIVE_ONLY_RECORD), $power);
    }

    public function testDeveloperDataIsStillExposedUnderItsOwnNameAndUndeclaredFieldsStayThere()
    {
        $pFFA = $this->parse();
        $developer = $pFFA->data_mesgs['developer_data'];

        $this->assertCount(self::RECORDS - 1, $developer['power']['data']);
        $this->assertSame(0xFFFF, $developer['power']['data'][self::INVALID_DEV_RECORD], 'developer_data keeps the raw value');
        $this->assertSame('watts', $developer['power']['units']);
        $this->assertCount(self::RECORDS - 1, $developer['form power']['data']);
        $this->assertArrayNotHasKey('form power', $pFFA->data_mesgs['record'], 'a developer field that declares no native field is not promoted');
    }

    public function testTheSessionPowerSummaryFollowsTheOverridingStream()
    {
        $session = $this->parse()->data_mesgs['session'];

        // 36 records at 200 and 2 at 210 -> 200.53, rounded as the device writes it.
        $this->assertSame(201, $session['avg_power']);
        $this->assertSame(210, $session['max_power']);
        // Normalised power of a stream that never leaves [200, 210] cannot leave it either. That is
        // asserted as a bound rather than a number so the test is not a copy of the formula.
        $this->assertGreaterThanOrEqual(200, $session['normalized_power']);
        $this->assertLessThanOrEqual(210, $session['normalized_power']);
        $this->assertNotSame(self::WATCH_POWER, $session['normalized_power']);
    }

    public function testTheOverrideCanBeSwitchedOffToKeepTheWatchStream()
    {
        $pFFA = $this->parse(['overwrite_with_dev_data' => false]);

        $this->assertSame(array_fill(0, self::RECORDS, self::WATCH_POWER), array_values($pFFA->data_mesgs['record']['power']));
        $this->assertSame(self::WATCH_POWER, $pFFA->data_mesgs['session']['avg_power']);
        $this->assertSame(self::WATCH_POWER, $pFFA->data_mesgs['session']['normalized_power']);
        $this->assertCount(self::RECORDS - 1, $pFFA->data_mesgs['developer_data']['power']['data'], 'the developer data is still read');
    }

    /** Before CX-87 a developer field with no field description was skipped without advancing the read pointer. */
    public function testAnUndescribedDeveloperFieldIsSkippedWithoutMisreadingWhatFollows()
    {
        $developerDataId = $this->definition(5, self::DEVELOPER_DATA_ID, [[3, 1, self::UINT8]]);
        $record = $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[9, 2, 0]]);
        $file = $this->fitFile(
            $developerDataId . $this->data(5, pack('C', 0))
            . $record
            . $this->data(1, pack('Vvv', 1000, 300, 0xBEEF))
            . $this->data(1, pack('Vvv', 1001, 310, 0xBEEF))
        );

        $power = (new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['record']['power'];

        $this->assertSame([$this->ts(0) => 300, $this->ts(1) => 310], $power);
    }
}
