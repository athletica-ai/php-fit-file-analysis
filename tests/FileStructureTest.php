<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * File-level structure: CRCs (checked only with 'validate_crc'), chained FIT files (several
 * Header . Data . CRC runs back to back, all read), and files the decoder cannot make sense of
 * (a FitParseException rather than warnings and garbage).
 */
class FileStructureTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const RECORD = 20;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    private function records(array $timestamps, $localType = 0)
    {
        $bytes = $this->definition($localType, self::RECORD, [[253, 4, self::UINT32], [7, 2, self::UINT16]]);
        foreach ($timestamps as $ts) {
            $bytes .= $this->data($localType, pack('Vv', $ts, 200));
        }

        return $bytes;
    }

    private function parse($file, array $options = [])
    {
        return new adriangibbons\phpFITFileAnalysis($file, $options + ['input_is_data' => true, 'units' => 'raw']);
    }

    public function testCrc16IsTheFitCrc()
    {
        // CRC-16/ARC check value, which the FIT CRC is.
        $this->assertSame(0xBB3D, adriangibbons\phpFITFileAnalysis::crc16('123456789'));
    }

    public function testAFileWithCorrectCrcsValidates()
    {
        $pFFA = $this->parse($this->fitFile($this->records([1000, 1001]), true), ['validate_crc' => true]);

        $this->assertCount(2, $pFFA->data_mesgs['record']['timestamp']);
    }

    public function testACorruptFileFailsValidation()
    {
        $file = $this->fitFile($this->records([1000, 1001]), true);
        $file[strlen($file) - 4] = chr(ord($file[strlen($file) - 4]) ^ 0x01);  // flip a bit in the last power value

        $this->expectException(adriangibbons\FitParseException::class);
        $this->expectExceptionMessage('file CRC');
        $this->parse($file, ['validate_crc' => true]);
    }

    public function testAHeaderCrcMismatchFailsValidation()
    {
        $file = $this->fitFile($this->records([1000]), true);
        $file[12] = chr(ord($file[12]) ^ 0x01);

        $this->expectException(adriangibbons\FitParseException::class);
        $this->expectExceptionMessage('header CRC');
        $this->parse($file, ['validate_crc' => true]);
    }

    public function testCrcsAreNotCheckedByDefault()
    {
        $pFFA = $this->parse($this->fitFile($this->records([1000])));

        $this->assertSame(1000 + FIT_UNIX_TS_DIFF, $pFFA->data_mesgs['record']['timestamp']);
    }

    public function testEveryFileOfAChainedFileIsRead()
    {
        // The second file reuses local type 0 for a different layout: definitions do not carry over.
        $second = $this->definition(0, self::RECORD, [[7, 2, self::UINT16], [253, 4, self::UINT32]])
            . $this->data(0, pack('vV', 300, 2000));
        $file = $this->fitFile($this->records([1000, 1001]), true) . $this->fitFile($second, true);

        $pFFA = $this->parse($file, ['validate_crc' => true]);

        $this->assertSame([1000 + FIT_UNIX_TS_DIFF, 1001 + FIT_UNIX_TS_DIFF, 2000 + FIT_UNIX_TS_DIFF], array_values($pFFA->data_mesgs['record']['timestamp']));
        $this->assertSame(300, $pFFA->data_mesgs['record']['power'][2000 + FIT_UNIX_TS_DIFF]);
    }

    public function testADataMessageWithoutADefinitionIsAParseError()
    {
        $this->expectException(adriangibbons\FitParseException::class);
        $this->expectExceptionMessage('undefined local message type 3');
        $this->parse($this->fitFile($this->records([1000]) . $this->data(3, pack('Vv', 1001, 200))));
    }

    public function testATruncatedFileIsAParseError()
    {
        $file = $this->fitFile($this->records([1000, 1001]));

        $this->expectException(adriangibbons\FitParseException::class);
        $this->parse(substr($file, 0, strlen($file) - 5));
    }

    public function testAFileTruncatedInItsHeaderIsAParseError()
    {
        // A 14-byte header cut to 13 bytes was a TypeError (unpack() returned false).
        $file = $this->fitFile($this->records([1000]));

        $this->expectException(adriangibbons\FitParseException::class);
        $this->parse(substr($file, 0, 13));
    }

    public function testParseErrorsAreStillExceptionsForExistingCallers()
    {
        $this->expectException(\Exception::class);
        $this->parse('not a fit file at all');
    }

    public function testAnUnknownMessageIsSkippedButItsTimestampAnchorsCompressedOnes()
    {
        $unknown = $this->definition(1, 0xFF00, [[253, 4, self::UINT32], [0, 2, self::UINT16]])
            . $this->data(1, pack('Vv', 2000, 7));
        $record = $this->definition(0, self::RECORD, [[7, 2, self::UINT16]])
            . $this->compressed(0, 2003 & 0x1F, pack('v', 210))
            . $this->compressed(0, 2004 & 0x1F, pack('v', 220));

        $pFFA = $this->parse($this->fitFile($unknown . $record));

        $this->assertSame([2003 + FIT_UNIX_TS_DIFF => 210, 2004 + FIT_UNIX_TS_DIFF => 220], $pFFA->data_mesgs['record']['power']);
    }
}
