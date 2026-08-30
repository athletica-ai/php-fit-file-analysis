<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}

class BasicTest extends \PHPUnit\Framework\TestCase
{
    private $base_dir;
    private $demo_files = [];
    private $valid_files = ['mountain-biking.fit', 'power-analysis.fit', 'road-cycling.fit', 'swim.fit'];
    
    protected function setUp(): void
    {
        $this->base_dir = __DIR__ . '/../demo/fit_files/';
        // Populated here, not in testDemoFilesExist(): PHPUnit builds a fresh instance per test,
        // so testDemoFileBasics() saw an empty array and its loop never ran. It had never
        // asserted anything (APP-1554).
        $this->demo_files = array_values(array_diff(scandir($this->base_dir), ['..', '.']));
        sort($this->demo_files);
    }
    
    public function testDemoFilesExist()
    {
        sort($this->valid_files);
        $this->assertEquals($this->valid_files, $this->demo_files);
    }
    
    public function testEmptyFilepath()
    {
        $this->expectException(\Exception::class);
        $pFFA = new adriangibbons\phpFITFileAnalysis('');
    }
    
    public function testFileDoesntExist()
    {
        $this->expectException(\Exception::class);
        $pFFA = new adriangibbons\phpFITFileAnalysis('file_doesnt_exist.fit');
    }
    
    public function testInvalidFitFile()
    {
        $this->expectException(\Exception::class);
        $file_path = $this->base_dir . '../composer.json';
        $pFFA = new adriangibbons\phpFITFileAnalysis($file_path);
    }
    
    
    public function testDemoFileBasics()
    {
        foreach($this->demo_files as $filename) {

            $pFFA = new adriangibbons\phpFITFileAnalysis($this->base_dir . $filename);
            
            $this->assertGreaterThan(0, $pFFA->data_mesgs['activity']['timestamp'], 'No Activity timestamp!');

            if (isset($pFFA->data_mesgs['record'])) {
                $this->assertGreaterThan(0, count($pFFA->data_mesgs['record']['timestamp']), 'No Record timestamps!');

                // Check if distance from record messages is +/- 2% of distance from session message
                if (is_array($pFFA->data_mesgs['record']['distance'])) {
                    // No / 1000: with the default units ('metric') setUnits() has already
                    // converted session.total_distance to km, the same unit the record distances
                    // are in. Dividing again compared 20.53 against 0.02053. The library was
                    // consistent all along -- the two agree exactly across all four demo files --
                    // but this test never ran, so nobody saw it (APP-1554).
                    $distance_difference = abs(end($pFFA->data_mesgs['record']['distance']) - $pFFA->data_mesgs['session']['total_distance']);
                    $this->assertLessThan(0.02 * end($pFFA->data_mesgs['record']['distance']), $distance_difference, 'Session distance should be similar to last Record distance');
                }
                
                // Look for big jumps in latitude and longitude
                if (isset($pFFA->data_mesgs['record']['position_lat']) && is_array($pFFA->data_mesgs['record']['position_lat'])) {
                    foreach ($pFFA->data_mesgs['record']['position_lat'] as $key => $value) {
                        if (isset($pFFA->data_mesgs['record']['position_lat'][$key - 1])) {
                            if (abs($pFFA->data_mesgs['record']['position_lat'][$key - 1] - $pFFA->data_mesgs['record']['position_lat'][$key]) > 1) {
                                $this->assertTrue(false, 'Too big a jump in latitude');
                            }
                        }
                    }
                }
                if (isset($pFFA->data_mesgs['record']['position_long']) && is_array($pFFA->data_mesgs['record']['position_long'])) {
                    foreach ($pFFA->data_mesgs['record']['position_long'] as $key => $value) {
                        if (isset($pFFA->data_mesgs['record']['position_long'][$key - 1])) {
                            if (abs($pFFA->data_mesgs['record']['position_long'][$key - 1] - $pFFA->data_mesgs['record']['position_long'][$key]) > 1) {
                                $this->assertTrue(false, 'Too big a jump in longitude');
                            }
                        }
                    }
                }
            }
        }
    }
}
