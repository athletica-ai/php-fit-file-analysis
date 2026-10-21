<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}
require_once __DIR__ . '/FitFileBuilder.php';

/**
 * Values that disagreed with the FIT SDK profile: device_info scales, sport 24, and product names
 * looked up in Garmin's product table whatever the manufacturer.
 */
class ProfileValueTest extends \PHPUnit\Framework\TestCase
{
    use FitFileBuilder;

    private const DEVICE_INFO = 23;
    private const UINT16 = 0x84;
    private const UINT32 = 0x86;

    private function deviceInfo($manufacturer, $product, $softwareVersion = 1020, $batteryVoltage = 1024)
    {
        $file = $this->fitFile(
            $this->definition(0, self::DEVICE_INFO, [[253, 4, self::UINT32], [2, 2, self::UINT16], [4, 2, self::UINT16], [5, 2, self::UINT16], [10, 2, self::UINT16]])
            . $this->data(0, pack('Vvvvv', 1000, $manufacturer, $product, $softwareVersion, $batteryVoltage))
        );

        return new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']);
    }

    public function testDeviceInfoVersionAndVoltageAreScaled()
    {
        $deviceInfo = $this->deviceInfo(1, 1124)->data_mesgs['device_info'];

        $this->assertEqualsWithDelta(10.2, $deviceInfo['software_version'], 1e-9);
        $this->assertEqualsWithDelta(4.0, $deviceInfo['battery_voltage'], 1e-9);
    }

    public function testAGarminFamilyProductIsNamed()
    {
        $this->assertSame('Forerunner 110', $this->deviceInfo(1, 1124)->product());
        $this->assertSame('Forerunner 110', $this->deviceInfo(89, 1124)->product(), 'Tacx uses Garmin product numbers');
    }

    public function testAnotherManufacturersProductIsNotGivenAGarminName()
    {
        $this->assertSame('unknown', $this->deviceInfo(32, 1124)->product());  // Wahoo
    }

    public function testSport24IsDriving()
    {
        $this->assertSame('Driving', $this->deviceInfo(1, 1124)->enumData('sport', 24));
    }

    /**
     * A date_time field named timestamp that is not field 253 -- course_point (32) field 1, set
     * (225) field 254 -- was shifted to Unix time twice: by name, then by type.
     */
    public function testATimestampThatIsNotField253IsShiftedOnce()
    {
        $file = $this->fitFile(
            $this->definition(0, 32, [[1, 4, self::UINT32]]) . $this->data(0, pack('V', 1000))
            . $this->definition(1, 225, [[254, 4, self::UINT32]]) . $this->data(1, pack('V', 2000))
        );
        $data = (new adriangibbons\phpFITFileAnalysis($file, ['input_is_data' => true, 'units' => 'raw']))->data_mesgs;

        $this->assertSame(1000 + FIT_UNIX_TS_DIFF, $data['course_point']['timestamp']);
        $this->assertSame(2000 + FIT_UNIX_TS_DIFF, $data['set']['timestamp']);
    }
}
