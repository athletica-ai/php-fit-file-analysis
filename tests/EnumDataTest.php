<?php
error_reporting(E_ALL);
if(!class_exists('adriangibbons\phpFITFileAnalysis')) {
    require __DIR__ . '/../src/phpFITFileAnalysis.php';
}

class EnumDataTest extends \PHPUnit\Framework\TestCase
{
    private $base_dir;
    private $filename = 'swim.fit';
    private $pFFA;
    
    protected function setUp(): void
    {
        $this->base_dir = __DIR__ . '/../demo/fit_files/';
        $this->pFFA = new adriangibbons\phpFITFileAnalysis($this->base_dir . $this->filename, ['units' => 'raw']);
    }
    
    public function testEnumData_manufacturer()
    {
        $this->assertEquals('Garmin', $this->pFFA->manufacturer());
    }
    
    public function testEnumData_product()
    {
        $this->assertEquals('Forerunner 910XT', $this->pFFA->product());
    }
    
    public function testEnumData_newer_garmin_products()
    {
        $this->assertEquals('Forerunner 970', $this->pFFA->enumData('product', 4565));
        $this->assertEquals('Forerunner 570 47mm', $this->pFFA->enumData('product', 4570));
        $this->assertEquals('Forerunner 570 42mm', $this->pFFA->enumData('product', 4574));
        $this->assertEquals('Venu X1', $this->pFFA->enumData('product', 4603));
        $this->assertEquals('Fenix 9', $this->pFFA->enumData('product', 5134));
        $this->assertEquals('Enduro 4', $this->pFFA->enumData('product', 5169));
    }

    public function testEnumData_backfilled_garmin_products()
    {
        $this->assertEquals('Forerunner 735XT', $this->pFFA->enumData('product', 2158));
        $this->assertEquals('Forerunner 245 Music', $this->pFFA->enumData('product', 3077));
        $this->assertEquals('Fenix 7 APAC', $this->pFFA->enumData('product', 3909));
        $this->assertEquals('Tacx Neo 2T Smart', $this->pFFA->enumData('product', 4267));
        $this->assertEquals('TrainingPeaks Virtual iOS', $this->pFFA->enumData('product', 30059));
    }

    public function testEnumData_product_ids_shared_with_other_manufacturers_stay_unknown()
    {
        // Suunto, Polar and Wahoo use these IDs; callers name those devices only when this returns 'unknown'.
        foreach ([13, 15, 16, 22] as $product_id) {
            $this->assertEquals('unknown', $this->pFFA->enumData('product', $product_id));
        }
    }

    public function testEnumData_sport()
    {
        $this->assertEquals('Swimming', $this->pFFA->sport());
    }
}
