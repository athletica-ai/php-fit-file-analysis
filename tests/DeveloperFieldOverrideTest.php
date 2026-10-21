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

    /**
     * Only power is overridden. Stryd also declares vertical oscillation (native 20/39), but in
     * centimetres with no scale against the watch's millimetres, and not the same measurement either,
     * so the watch's value stays and Stryd's stays in developer_data.
     */
    public function testADeclaredOverrideOfAFieldOtherThanPowerKeepsTheNativeValue()
    {
        $developerDataId = $this->definition(5, self::DEVELOPER_DATA_ID, [[3, 1, self::UINT8]]);
        $description = $this->definition(0, self::FIELD_DESCRIPTION, [
            [0, 1, self::UINT8], [1, 1, self::UINT8], [2, 1, self::UINT8], [3, 21, self::STRING], [8, 12, self::STRING], [14, 2, self::UINT16], [15, 1, self::UINT8],
        ]);
        $record = $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [39, 2, self::UINT16]], [[0, 1, 0]]);
        $file = $this->fitFile(
            $developerDataId . $this->data(5, pack('C', 0))
            . $description . $this->data(0, pack('CCC', 0, 0, self::UINT8) . "vertical oscillation\0" . "centimeters\0" . pack('vC', self::RECORD, 39))
            . $record
            . $this->data(1, pack('VvC', 1000, 630, 34))
            . $this->data(1, pack('VvC', 1001, 640, 35))
        );

        $pFFA = new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']);

        $this->assertEquals([$this->ts(0) => 63, $this->ts(1) => 64], $pFFA->data_mesgs['record']['vertical_oscillation']);
        $this->assertSame([34, 35], $pFFA->data_mesgs['developer_data']['vertical oscillation']['data']);
    }

    private const LAP = 19;
    private const FLOAT32 = 0x88;

    /** A Stryd-style "power" description (developer index 0, field $number, base type $type) declaring native 20/7. */
    private function powerDescription($localType, $number, $type, $name = "power\0", $scale = null)
    {
        $fields = [[0, 1, self::UINT8], [1, 1, self::UINT8], [2, 1, self::UINT8], [3, strlen($name), self::STRING], [14, 2, self::UINT16], [15, 1, self::UINT8]];
        $payload = pack('CCC', 0, $number, $type) . $name . pack('vC', self::RECORD, 7);
        if ($scale !== null) {
            $fields[] = [6, 1, self::UINT8];
            $payload .= pack('C', $scale);
        }

        return $this->definition($localType, self::FIELD_DESCRIPTION, $fields) . $this->data($localType, $payload);
    }

    private function developerDataId()
    {
        return $this->definition(5, self::DEVELOPER_DATA_ID, [[3, 1, self::UINT8]]) . $this->data(5, pack('C', 0));
    }

    /**
     * Session or lap message definition: timestamp, start_time, avg, max, normalized power. The
     * brick and lap tests write timestamp equal to start_time, as the Stryd reproducer does, so a
     * window must not end at the message's timestamp.
     */
    private function summaryDefinition($localType, $mesg)
    {
        $numbers = $mesg === self::SESSION ? [20, 21, 34] : [19, 20, 33];

        return $this->definition($localType, $mesg, [[253, 4, self::UINT32], [2, 4, self::UINT32], [$numbers[0], 2, self::UINT16], [$numbers[1], 2, self::UINT16], [$numbers[2], 2, self::UINT16]]);
    }

    private function summary($localType, $start, $end, $avg, $max, $np)
    {
        return $this->data($localType, pack('VVvvv', $end, $start, $avg, $max, $np));
    }

    private function parseBytes($bytes)
    {
        return new adriangibbons\phpFITFileAnalysis($this->fitFile($bytes), ['input_is_data' => true, 'units' => 'raw']);
    }

    /**
     * Review finding: the override wiped every later record's power, so a brick whose bike leg has a
     * real power meter and no Stryd lost that leg's power. Two sessions: a run (records 0-39, Stryd
     * 200 beside watch 300) and a ride (records 40-79, power meter 250, no developer field).
     */
    public function testABrickKeepsTheBikeLegsPowerMeterStream()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]])
            . $this->definition(2, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]])
            . $this->summaryDefinition(3, self::SESSION);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, 200));
        }
        $bytes .= $this->summary(3, 1000, 1000, 300, 300, 300);
        for ($i = 40; $i < 80; $i++) {
            $bytes .= $this->data(2, pack('Vv', 1000 + $i, 250));
        }
        $bytes .= $this->summary(3, 1040, 1040, 250, 250, 250);

        $pFFA = $this->parseBytes($bytes);
        $power = $pFFA->data_mesgs['record']['power'];

        $this->assertSame(200, $power[$this->ts(0)], 'the run leg carries Stryd');
        $this->assertSame(200, $power[$this->ts(39)], 'to its last record, though the session timestamp equals its start');
        $this->assertSame(250, $power[$this->ts(40)], 'the bike leg keeps its power meter');
        $this->assertCount(80, $power);
        $this->assertSame([200, 250], $pFFA->data_mesgs['session']['avg_power'], 'each session summarises its own stream');
    }

    /** Review finding: laps kept the watch's avg/max/normalized power while the session moved to Stryd. */
    public function testLapPowerFollowsTheOverridingStream()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]])
            . $this->summaryDefinition(3, self::LAP) . $this->summaryDefinition(4, self::SESSION);
        for ($i = 0; $i < 60; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, $i < 30 ? 200 : 220));
            if ($i === 29 || $i === 59) {
                $bytes .= $this->summary(3, 1000 + $i - 29, 1000 + $i - 29, 300, 300, 300);
            }
        }
        $bytes .= $this->summary(4, 1000, 1000, 300, 300, 300);

        $lap = $this->parseBytes($bytes)->data_mesgs['lap'];

        $this->assertSame([200, 220], $lap['avg_power']);
        $this->assertSame([200, 220], $lap['max_power']);
        $this->assertSame([200, 220], $lap['normalized_power'], 'a constant stream normalises to itself');
    }

    /**
     * Review finding: with the Stryd field installed but no pod paired every developer value is
     * invalid; the stream was emptied while the summary kept the watch's figures. With no valid
     * developer value the file is a watch-only file, stream and summary alike.
     */
    public function testAnOverrideWithNoValidValueLeavesTheWatchStreamAndSummary()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]])
            . $this->summaryDefinition(3, self::SESSION);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, 0xFFFF));
        }
        $bytes .= $this->summary(3, 1000, 1039, 300, 300, 300);

        $pFFA = $this->parseBytes($bytes);

        $this->assertCount(40, $pFFA->data_mesgs['record']['power']);
        $this->assertSame(300, $pFFA->data_mesgs['session']['avg_power']);
    }

    /**
     * Production: some files declare the override and write 0 on every record. Those zeros replaced
     * the watch's power for the whole session; the session is now left as the watch wrote it.
     */
    public function testAnOverrideWritingOnlyZerosLeavesTheWatchStreamAndSummary()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]])
            . $this->summaryDefinition(3, self::SESSION);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, 0));
        }
        $bytes .= $this->summary(3, 1000, 1039, 300, 300, 300);

        $pFFA = $this->parseBytes($bytes);

        $this->assertSame(300, $pFFA->data_mesgs['record']['power'][$this->ts(0)]);
        $this->assertSame(300, $pFFA->data_mesgs['session']['avg_power']);
    }

    /** Review finding: an app declaring power ahead of Stryd and writing 0 hid Stryd's readings. */
    public function testAZeroWritingDeclarerDoesNotHideALaterOnesReadings()
    {
        $bytes = $this->developerDataId()
            . $this->powerDescription(0, 0, self::UINT16, "zero power\0") . $this->powerDescription(4, 1, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0], [1, 2, 0]]);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvvv', 1000 + $i, 300, 0, 200));
        }

        $this->assertSame(200, $this->parseBytes($bytes)->data_mesgs['record']['power'][$this->ts(0)]);
    }

    /**
     * Review finding on #9: the per-record replace-a-0 rule mixed two declarers in one session. Stryd
     * (declared first) stands still at 0 for ten records while another app writes 250 throughout;
     * the session takes Stryd's values only.
     */
    public function testOneDeclarerSuppliesTheWholeSession()
    {
        $bytes = $this->developerDataId()
            . $this->powerDescription(0, 0, self::UINT16) . $this->powerDescription(4, 1, self::UINT16, "other power\0")
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0], [1, 2, 0]]);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvvv', 1000 + $i, 300, $i < 10 ? 0 : 200, 250));
        }

        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];

        $this->assertSame(0, $power[$this->ts(0)], 'Stryd standing still, not the other app');
        $this->assertSame(200, $power[$this->ts(10)]);
    }

    /**
     * Review finding on #9: with Stryd's description after its records, the replayed values arrived
     * after another declarer's and lost to them. The choice is by declaration, not arrival.
     */
    public function testTheChoiceDoesNotDependOnWhereADescriptionSits()
    {
        $bytes = $this->developerDataId()
            . $this->powerDescription(4, 1, self::UINT16, "other power\0")
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0], [1, 2, 0]]);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvvv', 1000 + $i, 300, 200, 250));
            if ($i === 19) {
                $bytes .= $this->powerDescription(0, 0, self::UINT16);
            }
        }

        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];

        $this->assertSame(200, $power[$this->ts(0)], 'replayed before its description, still the first declarer');
        $this->assertSame(200, $power[$this->ts(39)]);
    }

    /** Two records on one timestamp: the later value wins, as for the native fields and developer_data's by_mesg. */
    public function testOnADuplicateTimestampTheLaterValueWins()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]]);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, 200));
        }
        $bytes .= $this->data(1, pack('Vvv', 1005, 310, 150));

        $pFFA = $this->parseBytes($bytes);

        $this->assertSame(150, $pFFA->data_mesgs['record']['power'][$this->ts(5)]);
        $this->assertSame(150, $pFFA->data_mesgs['developer_data']['power']['by_mesg']['record'][$this->ts(5)]);
    }

    /** Zeros inside a session that has Stryd readings (standing still) are Stryd's, not the watch's. */
    public function testZerosBesideStrydReadingsAreKept()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]]);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, $i < 10 ? 0 : 200));
        }

        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];

        $this->assertSame(0, $power[$this->ts(0)]);
        $this->assertSame(200, $power[$this->ts(10)]);
    }

    /** Review finding: below 30 samples normalised power came out as 0 and replaced the device's figure. */
    public function testAShortFilesNormalisedPowerIsTheMeanNotZero()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]])
            . $this->summaryDefinition(3, self::SESSION);
        for ($i = 0; $i < 10; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, 200));
        }
        $bytes .= $this->summary(3, 1000, 1009, 300, 300, 300);

        $this->assertSame(200, $this->parseBytes($bytes)->data_mesgs['session']['normalized_power']);
    }

    /**
     * Review findings: two developer fields declaring power, where the later one is invalid on a
     * record, erased the earlier one's valid value; and records written before the description
     * kept the watch's value inside an overridden session.
     */
    public function testTheFirstValidDeclaredValueWinsAndEarlierRecordsCarryNoWatchValue()
    {
        $bytes = $this->developerDataId()
            . $this->definition(2, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]])
            . $this->data(2, pack('Vv', 1000, 300))
            . $this->powerDescription(0, 0, self::UINT16) . $this->powerDescription(4, 1, self::UINT16, "power2\0")
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0], [1, 2, 0]]);
        for ($i = 1; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvvv', 1000 + $i, 300, 200, $i === 5 ? 0xFFFF : 999));
        }

        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];

        $this->assertArrayNotHasKey($this->ts(0), $power, 'a record before the description carries no watch value');
        $this->assertSame(200, $power[$this->ts(5)], 'a later invalid declarer does not erase the first');
        $this->assertSame(200, $power[$this->ts(6)], 'the first valid declarer wins over a later valid one');
    }

    /**
     * A record carrying the Stryd field before its field_description is decoded when the
     * description arrives, so it takes the Stryd value like the records after it.
     */
    public function testARecordCarryingTheFieldBeforeItsDescriptionIsOverriddenToo()
    {
        $bytes = $this->developerDataId()
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]])
            . $this->data(1, pack('Vvv', 1000, 300, 200))
            . $this->powerDescription(0, 0, self::UINT16);
        for ($i = 1; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, 210));
        }

        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];

        $this->assertSame(200, $power[$this->ts(0)]);
        $this->assertSame(210, $power[$this->ts(1)]);
    }

    /** Review finding: a description with scale 0 divided by zero; a float32 invalid value became NaN. */
    public function testAZeroScaleAndANanValueDoNotBreakTheParse()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16, "power\0", 0)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]]);
        $bytes .= $this->data(1, pack('Vvv', 1000, 300, 200)) . $this->data(1, pack('Vvv', 1001, 300, 210));
        $this->assertSame(200, $this->parseBytes($bytes)->data_mesgs['record']['power'][$this->ts(0)]);

        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::FLOAT32)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 4, 0]]);
        $bytes .= $this->data(1, pack('Vvg', 1000, 300, 200.0)) . $this->data(1, pack('VvV', 1001, 300, 0xFFFFFFFF))
            . $this->data(1, pack('Vvg', 1002, 300, 210.0));
        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];
        $this->assertEquals(200, $power[$this->ts(0)]);
        $this->assertArrayNotHasKey($this->ts(1), $power, 'NaN is no value');
    }

    /** Run records 0-39 (watch 300, Stryd 200) then, when $bike, ride records 40-79 (power meter 250). */
    private function runThenRide($bike = true)
    {
        $bytes = '';
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, 200));
        }
        if ($bike) {
            for ($i = 40; $i < 80; $i++) {
                $bytes .= $this->data(2, pack('Vv', 1000 + $i, 250));
            }
        }

        return $bytes;
    }

    private function runThenRideHeader()
    {
        return $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]])
            . $this->definition(2, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]]);
    }

    /** Second review: a Stryd value on a record the watch had no power for was appended out of time order. */
    public function testTheOverriddenStreamStaysInTimeOrder()
    {
        $bytes = $this->runThenRideHeader();
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, $i < 2 ? 0xFFFF : 300, 200));
        }

        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];

        $this->assertSame(array_map([$this, 'ts'], range(0, 39)), array_keys($power));
    }

    /**
     * Second review: one lap without a valid start_time stopped every lap being re-summarised, and a
     * lap's window ran past its session into the next one. Third review: the padded lap kept the
     * watch's figures; it goes with the timed lap before it and is cleared. Run laps start at 1000 and 1020, a padded
     * lap with no start follows, and the bike's lap starts at 1050 though the bike session starts at
     * 1040, so the run's last lap would otherwise take ten bike samples.
     */
    public function testLapsAreSummarisedPastAMissingStartAndWithinTheirSession()
    {
        $bytes = $this->runThenRideHeader() . $this->summaryDefinition(3, self::LAP) . $this->summaryDefinition(4, self::SESSION)
            . $this->runThenRide()
            . $this->summary(3, 1000, 1000, 300, 300, 300) . $this->summary(3, 1020, 1020, 300, 300, 300)
            . $this->summary(3, 0xFFFFFFFF, 1039, 300, 300, 300) . $this->summary(3, 1050, 1050, 250, 250, 250)
            . $this->summary(4, 1000, 1000, 300, 300, 300) . $this->summary(4, 1040, 1040, 250, 250, 250);

        $lap = $this->parseBytes($bytes)->data_mesgs['lap'];

        $this->assertSame([200, 200, null, 250], $lap['avg_power'], 'run laps re-derived, the padded run lap cleared, the bike lap untouched');
    }

    /** Second review: a session without start_time made the brick one window and wiped the bike leg. */
    public function testABrickWhoseSessionsCannotBeToldApartIsLeftAsItIs()
    {
        $bytes = $this->runThenRideHeader() . $this->summaryDefinition(4, self::SESSION)
            . $this->runThenRide()
            . $this->summary(4, 1000, 1000, 300, 300, 300) . $this->summary(4, 0xFFFFFFFF, 1040, 250, 250, 250);

        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];

        $this->assertSame(300, $power[$this->ts(0)]);
        $this->assertSame(250, $power[$this->ts(40)]);
    }

    /** Sessions whose start times run backwards cannot be told apart either: the brick is left alone. */
    public function testABrickWhoseSessionsAreOutOfOrderIsLeftAsItIs()
    {
        $bytes = $this->runThenRideHeader() . $this->summaryDefinition(4, self::SESSION)
            . $this->runThenRide()
            . $this->summary(4, 1040, 1040, 250, 250, 250) . $this->summary(4, 1000, 1000, 300, 300, 300);

        $power = $this->parseBytes($bytes)->data_mesgs['record']['power'];

        $this->assertSame(300, $power[$this->ts(0)]);
        $this->assertSame(250, $power[$this->ts(40)]);
    }

    /** A declarer named other than "power" is not also copied in under its own name once it has replaced power. */
    public function testAnAppliedOverrideIsNotAlsoCopiedUnderItsOwnName()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16, "stryd power\0")
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]], [[0, 2, 0]]);
        for ($i = 0; $i < 40; $i++) {
            $bytes .= $this->data(1, pack('Vvv', 1000 + $i, 300, 200));
        }

        $record = $this->parseBytes($bytes)->data_mesgs['record'];

        $this->assertSame(200, $record['power'][$this->ts(0)]);
        $this->assertArrayNotHasKey('stryd power', $record);
    }

    /** Session message with timestamp, start_time, avg/max power and, when $laps is set, first_lap_index / num_laps. */
    private function sessionWithLaps($localType, $start, $avg, $firstLap, $numLaps)
    {
        return $this->definition($localType, self::SESSION, [[253, 4, self::UINT32], [2, 4, self::UINT32], [20, 2, self::UINT16], [21, 2, self::UINT16], [25, 2, self::UINT16], [26, 2, self::UINT16]])
            . $this->data($localType, pack('VVvvvv', $start, $start, $avg, $avg, $firstLap, $numLaps));
    }

    /**
     * Third review: sessions were counted by their timestamp list, so a brick whose bike session has
     * an invalid timestamp counted as one session and the whole file was overridden.
     */
    public function testABrickWithAnInvalidSessionTimestampStillKeepsTheBikeLeg()
    {
        $bytes = $this->runThenRideHeader() . $this->summaryDefinition(4, self::SESSION)
            . $this->runThenRide()
            . $this->summary(4, 1000, 1000, 300, 300, 300) . $this->summary(4, 1040, 0xFFFFFFFF, 250, 250, 250);

        $pFFA = $this->parseBytes($bytes);

        $this->assertSame(200, $pFFA->data_mesgs['record']['power'][$this->ts(39)]);
        $this->assertSame(250, $pFFA->data_mesgs['record']['power'][$this->ts(40)]);
        $this->assertSame([200, 250], $pFFA->data_mesgs['session']['avg_power']);
    }

    /**
     * Third review: a multi-session file the override leaves alone lost Stryd power altogether when
     * the watch wrote none, because the old copy skipped the power declaration too.
     */
    public function testAFileTheOverrideLeavesAloneStillGetsTheOldCopy()
    {
        $bytes = $this->developerDataId() . $this->powerDescription(0, 0, self::UINT16)
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32]], [[0, 2, 0]]) . $this->summaryDefinition(4, self::SESSION);
        for ($i = 0; $i < 4; $i++) {
            $bytes .= $this->data(1, pack('Vv', 1000 + $i, 200));
        }
        $bytes .= $this->summary(4, 1000, 1000, 0, 0, 0) . $this->summary(4, 0xFFFFFFFF, 1002, 0, 0, 0);

        $this->assertSame([200, 200, 200, 200], $this->parseBytes($bytes)->data_mesgs['record']['power']);
    }

    /**
     * Third review: a session whose definition lacks avg/max power left the lists shorter than the
     * session count, and the overridden session's summary was silently skipped. A swim session with
     * no power fields precedes the Stryd run. Session fields are now padded one entry per session,
     * so the swim's is null.
     */
    public function testASessionWithoutPowerFieldsDoesNotStopTheNextBeingSummarised()
    {
        $swim = $this->definition(6, self::SESSION, [[253, 4, self::UINT32], [2, 4, self::UINT32]]);
        $bytes = $this->runThenRideHeader() . $swim . $this->summaryDefinition(4, self::SESSION)
            . $this->data(6, pack('VV', 990, 990)) . $this->runThenRide(false) . $this->summary(4, 1000, 1000, 300, 300, 300);

        $this->assertSame([null, 200], $this->parseBytes($bytes)->data_mesgs['session']['avg_power'], 'the run\'s, not the watch\'s 300');
    }

    /**
     * Third review: a lap was placed by its start_time alone, so one starting a second before its
     * session went to the previous session. A session's first_lap_index / num_laps place it instead.
     */
    public function testLapsArePlacedByTheSessionsLapIndexWhenItHasOne()
    {
        $bytes = $this->runThenRideHeader() . $this->summaryDefinition(3, self::LAP)
            . $this->runThenRide()
            . $this->summary(3, 999, 999, 300, 300, 300) . $this->summary(3, 1039, 1039, 250, 250, 250)
            . $this->sessionWithLaps(4, 1000, 300, 0, 1) . $this->sessionWithLaps(5, 1040, 250, 1, 1);

        $lap = $this->parseBytes($bytes)->data_mesgs['lap'];

        $this->assertSame([200, 250], $lap['avg_power'], 'the run lap starting at 999 is the run session\'s; the bike lap starting at 1039 is the bike\'s');
    }

    /** Second review: TSS, IF and total work kept describing the watch's stream. */
    public function testPowerDerivedFiguresTheLibraryDoesNotRederiveAreCleared()
    {
        $session = $this->definition(3, self::SESSION, [[253, 4, self::UINT32], [20, 2, self::UINT16], [35, 2, self::UINT16], [36, 2, self::UINT16], [48, 4, self::UINT32]]);
        $bytes = $this->runThenRideHeader() . $session . $this->runThenRide(false) . $this->data(3, pack('VvvvV', 1040, 300, 1500, 900, 600000));

        $summary = $this->parseBytes($bytes)->data_mesgs['session'];

        $this->assertSame(200, $summary['avg_power']);
        $this->assertNull($summary['training_stress_score']);
        $this->assertNull($summary['intensity_factor']);
        $this->assertNull($summary['total_work']);
    }

    /**
     * Second review: before CX-87 a declared developer field was copied into the record stream under
     * its own name when the watch wrote no such field; Stryd's cadence arrives that way.
     */
    public function testANonPowerDeclarationTheWatchLacksIsStillCopiedAsBefore()
    {
        $cadence = $this->definition(0, self::FIELD_DESCRIPTION, [[0, 1, self::UINT8], [1, 1, self::UINT8], [2, 1, self::UINT8], [3, 8, self::STRING], [14, 2, self::UINT16], [15, 1, self::UINT8]])
            . $this->data(0, pack('CCC', 0, 2, self::UINT8) . "cadence\0" . pack('vC', self::RECORD, 4));
        $bytes = $this->developerDataId() . $cadence
            . $this->definition(1, self::RECORD, [[253, 4, self::UINT32]], [[2, 1, 0]])
            . $this->data(1, pack('VC', 1000, 85)) . $this->data(1, pack('VC', 1001, 86));

        $this->assertSame([85, 86], $this->parseBytes($bytes)->data_mesgs['record']['cadence']);
    }

    /** powerMetrics() threw on a stream too short for one window before CX-87 shared the formula; it still does. */
    public function testPowerMetricsStillRefusesAStreamTooShortForOneWindow()
    {
        $bytes = $this->runThenRideHeader();
        for ($i = 0; $i < 10; $i++) {
            $bytes .= $this->data(2, pack('Vv', 1000 + $i, 250));
        }

        $this->expectException(\Exception::class);
        $this->parseBytes($bytes)->powerMetrics(250);
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
