<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * Strings are returned as written: up to the null terminator, as UTF-8. They went through the
 * deprecated FILTER_SANITIZE_STRING, which stripped tags and HTML-encoded quotes ("Steve's" became
 * "Steve&#39;s"); escaping is the caller's business.
 */
class StringFieldTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const SPORT = 12;
    private const STRING = 0x07;

    private function sportName($bytes)
    {
        $file = $this->fitFile(
            $this->definition(0, self::SPORT, [[3, strlen($bytes), self::STRING]])
            . $this->data(0, $bytes)
        );

        return (new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']))->data_mesgs['sport']['name'];
    }

    public function testQuotesAndAngleBracketsAreNotEncoded()
    {
        $this->assertSame("Steve's <Long> Ride & \"Tempo\"", $this->sportName("Steve's <Long> Ride & \"Tempo\"\0"));
    }

    public function testTheStringEndsAtTheFirstNull()
    {
        $this->assertSame('Run', $this->sportName("Run\0junk after the terminator\0"));
    }

    public function testMultibyteUtf8IsKept()
    {
        $this->assertSame('Pāua 🐚 Ōtautahi', $this->sportName("Pāua 🐚 Ōtautahi\0\0\0"));
    }

    public function testInvalidUtf8IsReplacedRatherThanPassedOn()
    {
        $name = $this->sportName("Bad \xC3\x28 byte\0");

        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
        $this->assertStringStartsWith('Bad ', $name);
    }
}
