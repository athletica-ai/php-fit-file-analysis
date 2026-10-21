<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * A data message is decoded with the architecture of ITS definition. It used to be decoded with
 * whichever definition was read last, so a file mixing little- and big-endian definitions misread
 * every message of the other kind; floats ignored the architecture altogether.
 */
class EndiannessTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const RECORD = 20;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;
    private const FLOAT32 = 0x88;

    private function ts($i)
    {
        return 1000 + $i + FIT_UNIX_TS_DIFF;
    }

    public function testInterleavedArchitecturesAreEachDecodedWithTheirOwnDefinition()
    {
        $fields = [[253, 4, self::UINT32], [7, 2, self::UINT16]];
        $file = $this->fitFile(
            $this->definition(0, self::RECORD, $fields)
            . $this->definition(1, self::RECORD, $fields, [], true)
            . $this->data(0, pack('Vv', 1000, 250))
            . $this->data(1, pack('Nn', 1001, 260))
            . $this->data(0, pack('Vv', 1002, 270))
        );

        $power = (new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['record']['power'];

        $this->assertSame([$this->ts(0) => 250, $this->ts(1) => 260, $this->ts(2) => 270], $power);
    }

    public function testBigEndianFloatsAreDecodedBigEndian()
    {
        $file = $this->fitFile(
            $this->definition(0, self::RECORD, [[253, 4, self::UINT32], [6, 4, self::FLOAT32]], [], true)
            . $this->data(0, pack('N', 1000) . pack('G', 2500.0))
            . $this->data(0, pack('N', 1001) . pack('G', -1250.0))
        );

        $speed = (new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['record']['speed'];

        $this->assertEqualsWithDelta(2.5, $speed[$this->ts(0)], 1e-9);
        $this->assertEqualsWithDelta(-1.25, $speed[$this->ts(1)], 1e-9);
    }

    public function testAnUnknownArchitectureIsAParseError()
    {
        $definition = $this->definition(0, self::RECORD, [[253, 4, self::UINT32]]);
        $definition[2] = "\x02";  // architecture byte

        $this->expectException(adriangibbons\FitParseException::class);
        new adriangibbons\phpFITFileAnalysis($this->fitFile($definition), ['input_is_data' => true]);
    }
}
