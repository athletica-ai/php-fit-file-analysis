<?php
namespace adriangibbons;

/**
 * phpFITFileAnalysis
 * =====================
 * A PHP class for Analysing FIT files created by Garmin GPS devices.
 * Adrian Gibbons, 2015
 * Adrian.GitHub@gmail.com
 *
 * G Frogley edits:
 * Added code to generate TRIMPexp and hrIF (Intensity Factor) value to measure session if Power is not present (June 2015).
 * Added code to generate Quadrant Analysis data (September 2015).
 *
 * Rafael Nájera edits:
 * Added code to support compressed timestamps (March 2017).
 *
 * https://github.com/adriangibbons/phpFITFileAnalysis
 * http://www.thisisant.com/resources/fit
 */

// For callers that require this file directly rather than through Composer's autoloader.
foreach (['FitParseException', 'FitBitStream', 'Profile'] as $class) {
    if (!class_exists(__NAMESPACE__ . '\\' . $class)) {
        require_once __DIR__ . '/' . $class . '.php';
    }
}
unset($class);

// Global constants kept for callers that use them; the class uses its own constants.
// DEFINITION_MESSAGE and DATA_MESSAGE are no longer used by the library.
if (!defined('DEFINITION_MESSAGE')) {
    define('DEFINITION_MESSAGE', 1);
}
if (!defined('DATA_MESSAGE')) {
    define('DATA_MESSAGE', 0);
}
if (!defined('FIT_UNIX_TS_DIFF')) {
    define('FIT_UNIX_TS_DIFF', phpFITFileAnalysis::FIT_UNIX_TS_DIFF);
}

class phpFITFileAnalysis
{
    /*
     * This is the number of seconds difference between FIT and Unix timestamps.
     * FIT timestamps are seconds since UTC 00:00:00 Dec 31 1989 (source FIT SDK)
     * Unix time is the number of seconds since UTC 00:00:00 Jan 01 1970
     */
    public const FIT_UNIX_TS_DIFF = 631065600;

    private const OVERRIDABLE_RECORD_FIELDS = [7];  // CX-87: record field numbers a developer field may replace (power).

    /** @var array<string, mixed> Used to store the data read from the file in associative arrays. */
    public $data_mesgs = [];
    private array $dev_field_descriptions = [];
    private array $hr_messages = [];               // hr (132) messages as read: timestamp, event_timestamp, event_timestamp_12, filtered_bpm; see processHrMessages().
    private array $undescribed_dev_values = [];    // Record developer fields read before their field_description: [index][number] => [[pos, size, architecture, record timestamp], ...].
    private array $dev_override_streams = [];      // CX-87: [native record field][declarer (developer data index * 256 + field number)][record timestamp] => valid developer value.
    private array $applied_overrides = [];         // CX-87: [native record field][declarer] => true for each declarer that supplied some session.
    private array $options = [];                   // Options provided to __construct(), with defaults filled in.
    private string $file_contents = '';            // The whole FIT file, as read. See __construct().
    private int $file_pointer = 0;                 // Points to the location in the file that shall be read next.
    private array $defn_mesgs = [];                // Array of FIT 'Definition Messages', which describe the architecture, format, and fields of 'Data Messages'.
    private array $defn_mesgs_all = [];            // Keeps a record of all Definition Messages as index ($local_mesg_type) of $defn_mesgs may be reused in file.
    private array $file_header = [];               // Contains information about the FIT file such as the Protocol version, Profile version, and Data Size.
    private bool $php_trader_ext_loaded = false;   // Is the PHP Trader extension loaded? Use $this->sma() algorithm if not available.
    private int $data_end = 0;                     // Offset just past the data records of the FIT file being read (its CRC follows).
    private ?int $last_timestamp = null;           // Latest timestamp of any message (FIT epoch): the reference for compressed timestamp headers.
    private ?int $max_record_timestamp = null;     // APP-1554: running maximum of record timestamps.
    private array $message_counts = [];            // Messages read so far, by name, for padMessageFields().
    private array $accumulators = [];              // FIT SDK accumulated fields: [global mesg num][field num] => [total, last raw value].
    private bool $garmin_timestamps = false;       // By default self::FIT_UNIX_TS_DIFF will be added to timestamps.

    // Enumerated data looked up by enumData().
    // Values from 'Profile.xls' contained within the FIT SDK.
    private array $enum_data = [
        'activity' => [0 => 'manual', 1 => 'auto_multi_sport'],
        'ant_network' => [0 => 'public', 1 => 'antplus', 2 => 'antfs', 3 => 'private'],
        'battery_status' => [1 => 'new', 2 => 'good', 3 => 'ok', 4 => 'low', 5 => 'critical', 7 => 'unknown'],
        'body_location' => [
            0 => 'left_leg',
            1 => 'left_calf',
            2 => 'left_shin',
            3 => 'left_hamstring',
            4 => 'left_quad',
            5 => 'left_glute',
            6 => 'right_leg',
            7 => 'right_calf',
            8 => 'right_shin',
            9 => 'right_hamstring',
            10 => 'right_quad',
            11 => 'right_glute',
            12 => 'torso_back',
            13 => 'left_lower_back',
            14 => 'left_upper_back',
            15 => 'right_lower_back',
            16 => 'right_upper_back',
            17 => 'torso_front',
            18 => 'left_abdomen',
            19 => 'left_chest',
            20 => 'right_abdomen',
            21 => 'right_chest',
            22 => 'left_arm',
            23 => 'left_shoulder',
            24 => 'left_bicep',
            25 => 'left_tricep',
            26 => 'left_brachioradialis',
            27 => 'left_forearm_extensors',
            28 => 'right_arm',
            29 => 'right_shoulder',
            30 => 'right_bicep',
            31 => 'right_tricep',
            32 => 'right_brachioradialis',
            33 => 'right_forearm_extensors',
            34 => 'neck',
            35 => 'throat'
        ],
        'display_heart' => [0 => 'bpm', 1 => 'max', 2 => 'reserve'],
        'display_measure' => [0 => 'metric', 1 => 'statute'],
        'display_position' => [
            0 => 'degree',                //dd.dddddd
            1 => 'degree_minute',         //dddmm.mmm
            2 => 'degree_minute_second',  //dddmmss
            3 => 'austrian_grid',   //Austrian Grid (BMN)
            4 => 'british_grid',    //British National Grid
            5 => 'dutch_grid',      //Dutch grid system
            6 => 'hungarian_grid',  //Hungarian grid system
            7 => 'finnish_grid',    //Finnish grid system Zone3 KKJ27
            8 => 'german_grid',     //Gausss Krueger (German)
            9 => 'icelandic_grid',  //Icelandic Grid
            10 => 'indonesian_equatorial',  //Indonesian Equatorial LCO
            11 => 'indonesian_irian',       //Indonesian Irian LCO
            12 => 'indonesian_southern',    //Indonesian Southern LCO
            13 => 'india_zone_0',      //India zone 0
            14 => 'india_zone_IA',     //India zone IA
            15 => 'india_zone_IB',     //India zone IB
            16 => 'india_zone_IIA',    //India zone IIA
            17 => 'india_zone_IIB',    //India zone IIB
            18 => 'india_zone_IIIA',   //India zone IIIA
            19 => 'india_zone_IIIB',   //India zone IIIB
            20 => 'india_zone_IVA',    //India zone IVA
            21 => 'india_zone_IVB',    //India zone IVB
            22 => 'irish_transverse',  //Irish Transverse Mercator
            23 => 'irish_grid',        //Irish Grid
            24 => 'loran',             //Loran TD
            25 => 'maidenhead_grid',   //Maidenhead grid system
            26 => 'mgrs_grid',         //MGRS grid system
            27 => 'new_zealand_grid',  //New Zealand grid system
            28 => 'new_zealand_transverse',  //New Zealand Transverse Mercator
            29 => 'qatar_grid',              //Qatar National Grid
            30 => 'modified_swedish_grid',   //Modified RT-90 (Sweden)
            31 => 'swedish_grid',            //RT-90 (Sweden)
            32 => 'south_african_grid',      //South African Grid
            33 => 'swiss_grid',              //Swiss CH-1903 grid
            34 => 'taiwan_grid',             //Taiwan Grid
            35 => 'united_states_grid',      //United States National Grid
            36 => 'utm_ups_grid',            //UTM/UPS grid system
            37 => 'west_malayan',            //West Malayan RSO
            38 => 'borneo_rso',              //Borneo RSO
            39 => 'estonian_grid',           //Estonian grid system
            40 => 'latvian_grid',            //Latvian Transverse Mercator
            41 => 'swedish_ref_99_grid',     //Reference Grid 99 TM (Swedish)
        ],
        'display_power' => [0 => 'watts', 1 => 'percent_ftp'],
        'event' => [
            0 => 'timer',
            3 => 'workout',
            4 => 'workout_step',
            5 => 'power_down',
            6 => 'power_up',
            7 => 'off_course',
            8 => 'session',
            9 => 'lap',
            10 => 'course_point',
            11 => 'battery',
            12 => 'virtual_partner_pace',
            13 => 'hr_high_alert',
            14 => 'hr_low_alert',
            15 => 'speed_high_alert',
            16 => 'speed_low_alert',
            17 => 'cad_high_alert',
            18 => 'cad_low_alert',
            19 => 'power_high_alert',
            20 => 'power_low_alert',
            21 => 'recovery_hr',
            22 => 'battery_low',
            23 => 'time_duration_alert',
            24 => 'distance_duration_alert',
            25 => 'calorie_duration_alert',
            26 => 'activity',
            27 => 'fitness_equipment',
            28 => 'length',
            32 => 'user_marker',
            33 => 'sport_point',
            36 => 'calibration',
            42 => 'front_gear_change',
            43 => 'rear_gear_change',
            44 => 'rider_position_change',
            45 => 'elev_high_alert',
            46 => 'elev_low_alert',
            47 => 'comm_timeout'
        ],
        'event_type' => [
            0 => 'start',
            1 => 'stop',
            2 => 'consecutive_depreciated',
            3 => 'marker',
            4 => 'stop_all',
            5 => 'begin_depreciated',
            6 => 'end_depreciated',
            7 => 'end_all_depreciated',
            8 => 'stop_disable',
            9 => 'stop_disable_all'
        ],
        'file' => [
            1 => 'device',
            2 => 'settings',
            3 => 'sport',
            4 => 'activity',
            5 => 'workout',
            6 => 'course',
            7 => 'schedules',
            9 => 'weight',
            10 => 'totals',
            11 => 'goals',
            14 => 'blood_pressure',
            15 => 'monitoring_a',
            20 => 'activity_summary',
            28 => 'monitoring_daily',
            32 => 'monitoring_b',
            // APP-1344: the table stopped at 32, so these three standard FIT file types came back
            // as 'unknown' and a diagnostic over Athletica's parked-orphan directory could not say
            // what the files were. Purely additive -- nothing in the library or in Athletica
            // branches on these names; enumData() simply stops answering 'unknown' for them.
            34 => 'segment',
            35 => 'segment_list',
            40 => 'exd_configuration',
            0xF7 => 'mfg_range_min',
            0xFE => 'mfg_range_max'
        ],
        'gender' => [0 => 'female', 1 => 'male'],
        'hr_zone_calc' => [0 => 'custom', 1 => 'percent_max_hr', 2 => 'percent_hrr'],
        'intensity' => [0 => 'active', 1 => 'rest', 2 => 'warmup', 3 => 'cooldown'],
        'language' => [
            0 => 'english',
            1 => 'french',
            2 => 'italian',
            3 => 'german',
            4 => 'spanish',
            5 => 'croatian',
            6 => 'czech',
            7 => 'danish',
            8 => 'dutch',
            9 => 'finnish',
            10 => 'greek',
            11 => 'hungarian',
            12 => 'norwegian',
            13 => 'polish',
            14 => 'portuguese',
            15 => 'slovakian',
            16 => 'slovenian',
            17 => 'swedish',
            18 => 'russian',
            19 => 'turkish',
            20 => 'latvian',
            21 => 'ukrainian',
            22 => 'arabic',
            23 => 'farsi',
            24 => 'bulgarian',
            25 => 'romanian',
            254 => 'custom'
        ],
        'length_type' => [0 => 'idle', 1 => 'active'],
        'manufacturer' => [  // Have capitalised select manufacturers
            1 => 'Garmin',
            2 => 'garmin_fr405_antfs',
            3 => 'zephyr',
            4 => 'dayton',
            5 => 'idt',
            6 => 'SRM',
            7 => 'Quarq',
            8 => 'iBike',
            9 => 'saris',
            10 => 'spark_hk',
            11 => 'Tanita',
            12 => 'Echowell',
            13 => 'dynastream_oem',
            14 => 'nautilus',
            15 => 'dynastream',
            16 => 'Timex',
            17 => 'metrigear',
            18 => 'xelic',
            19 => 'beurer',
            20 => 'cardiosport',
            21 => 'a_and_d',
            22 => 'hmm',
            23 => 'Suunto',
            24 => 'thita_elektronik',
            25 => 'gpulse',
            26 => 'clean_mobile',
            27 => 'pedal_brain',
            28 => 'peaksware',
            29 => 'saxonar',
            30 => 'lemond_fitness',
            31 => 'dexcom',
            32 => 'Wahoo Fitness',
            33 => 'octane_fitness',
            34 => 'archinoetics',
            35 => 'the_hurt_box',
            36 => 'citizen_systems',
            37 => 'Magellan',
            38 => 'osynce',
            39 => 'holux',
            40 => 'concept2',
            42 => 'one_giant_leap',
            43 => 'ace_sensor',
            44 => 'brim_brothers',
            45 => 'xplova',
            46 => 'perception_digital',
            47 => 'bf1systems',
            48 => 'pioneer',
            49 => 'spantec',
            50 => 'metalogics',
            51 => '4iiiis',
            52 => 'seiko_epson',
            53 => 'seiko_epson_oem',
            54 => 'ifor_powell',
            55 => 'maxwell_guider',
            56 => 'star_trac',
            57 => 'breakaway',
            58 => 'alatech_technology_ltd',
            59 => 'mio_technology_europe',
            60 => 'Rotor',
            61 => 'geonaute',
            62 => 'id_bike',
            63 => 'Specialized',
            64 => 'wtek',
            65 => 'physical_enterprises',
            66 => 'north_pole_engineering',
            67 => 'BKOOL',
            68 => 'Cateye',
            69 => 'Stages Cycling',
            70 => 'Sigmasport',
            71 => 'TomTom',
            72 => 'peripedal',
            73 => 'Wattbike',
            76 => 'moxy',
            77 => 'ciclosport',
            78 => 'powerbahn',
            79 => 'acorn_projects_aps',
            80 => 'lifebeam',
            81 => 'Bontrager',
            82 => 'wellgo',
            83 => 'scosche',
            84 => 'magura',
            85 => 'woodway',
            86 => 'elite',
            87 => 'nielsen_kellerman',
            88 => 'dk_city',
            89 => 'Tacx',
            90 => 'direction_technology',
            91 => 'magtonic',
            92 => '1partcarbon',
            93 => 'inside_ride_technologies',
            94 => 'sound_of_motion',
            95 => 'stryd',
            96 => 'icg',
            97 => 'MiPulse',
            98 => 'bsx_athletics',
            99 => 'look',
            100 => 'campagnolo_srl',
            101 => 'body_bike_smart',
            102 => 'praxisworks',
            103 => 'limits_technology',
            104 => 'topaction_technology',
            105 => 'cosinuss',
            106 => 'fitcare',
            107 => 'magene',
            108 => 'giant_manufacturing_co',
            109 => 'tigrasport',
            110 => 'salutron',
            111 => 'technogym',
            112 => 'bryton_sensors',
            113 => 'latitude_limited',
            114 => 'soaring_technology',
            115 => 'igpsport',
            116 => 'thinkrider',
            117 => 'gopher_sport',
            118 => 'waterrower',
            119 => 'orangetheory',
            120 => 'inpeak',
            121 => 'kinetic',
            122 => 'johnson_health_tech',
            123 => 'polar_electro',
            124 => 'seesense',
            125 => 'nci_technology',
            126 => 'iqsquare',
            127 => 'leomo',
            128 => 'ifit_com',
            255 => 'development',
            257 => 'healthandlife',
            258 => 'Lezyne',
            259 => 'scribe_labs',
            260 => 'Zwift',
            261 => 'watteam',
            262 => 'recon',
            263 => 'favero_electronics',
            264 => 'dynovelo',
            265 => 'Strava',
            266 => 'precor',
            267 => 'Bryton',
            268 => 'sram',
            269 => 'navman',
            270 => 'cobi',
            271 => 'spivi',
            272 => 'mio_magellan',
            273 => 'evesports',
            274 => 'sensitivus_gauge',
            275 => 'podoon',
            276 => 'life_time_fitness',
            277 => 'falco_e_motors',
            278 => 'minoura',
            279 => 'cycliq',
            280 => 'luxottica',
            281 => 'trainer_road',
            282 => 'the_sufferfest',
            283 => 'fullspeedahead',
            284 => 'virtualtraining',
            285 => 'feedbacksports',
            286 => 'omata',
            287 => 'vdo',
            288 => 'magneticdays',
            289 => 'hammerhead',
            290 => 'kinetic_by_kurt',
            291 => 'shapelog',
            292 => 'dabuziduo',
            293 => 'jetblack',
            294 => 'coros',
            295 => 'virtugo',
            296 => 'velosense',
            297 => 'cycligentinc',
            298 => 'trailforks',
            299 => 'mahle_ebikemotion',
            5759 => 'actigraphcorp'
        ],
        'pwr_zone_calc' => [0 => 'custom', 1 => 'percent_ftp'],
        // Source of truth for product IDs: garminProduct in Garmin's FIT SDK profile
        // (https://github.com/garmin/fit-javascript-sdk/blob/main/src/profile.js). Last checked against v21.218.0.
        // SDK IDs 13, 15, 16 and 22 (single-byte ANT+ sensor IDs) are left out on purpose: this table is looked up
        // whatever the manufacturer, and those IDs are Suunto, Polar and Wahoo watches, which callers name only
        // when this returns 'unknown'.
        'product' => [  // Have formatted for devices known to use FIT format. (Original text commented-out).
            1 => 'hrm1',
            2 => 'axh01',
            3 => 'axb01',
            4 => 'axb02',
            5 => 'hrm2ss',
            6 => 'dsi_alf02',
            7 => 'hrm3ss',
            8 => 'hrm_run_single_byte_product_id',
            9 => 'bsm',
            10 => 'bcm',
            11 => 'axs01',
            12 => 'HRM-Tri',                    // 'hrm_tri_single_byte_product_id',
            14 => 'Forerunner 225',             // 'fr225_single_byte_product_id',
            255 => 'Optical HR',
            473 => 'Forerunner 301',            // 'fr301_china',
            474 => 'Forerunner 301',            // 'fr301_japan',
            475 => 'Forerunner 301',            // 'fr301_korea',
            494 => 'Forerunner 301',            // 'fr301_taiwan',
            717 => 'Forerunner 405',            // 'fr405',
            782 => 'Forerunner 50',             // 'fr50',
            987 => 'Forerunner 405',            // 'fr405_japan',
            988 => 'Forerunner 60',             // 'fr60',
            1011 => 'dsi_alf01',
            1018 => 'Forerunner 310XT',         // 'fr310xt',
            1036 => 'Edge 500',                 // 'edge500',
            1124 => 'Forerunner 110',           // 'fr110',
            1169 => 'Edge 800',                 // 'edge800',
            1199 => 'Edge 500',                 // 'edge500_taiwan',
            1213 => 'Edge 500',                 // 'edge500_japan',
            1253 => 'chirp',
            1274 => 'Forerunner 110',           // 'fr110_japan',
            1325 => 'edge200',
            1328 => 'Forerunner 910XT',         // 'fr910xt',
            1333 => 'Edge 800',                 // 'edge800_taiwan',
            1334 => 'Edge 800',                 // 'edge800_japan',
            1341 => 'alf04',
            1345 => 'Forerunner 610',           // 'fr610',
            1360 => 'Forerunner 210',           // 'fr210_japan',
            1380 => 'Vector 2S',                // vector_ss
            1381 => 'Vector 2',                 // vector_cp
            1386 => 'Edge 800',                 // 'edge800_china',
            1387 => 'Edge 500',                 // 'edge500_china',
            1405 => 'Approach G10',
            1410 => 'Forerunner 610',           // 'fr610_japan',
            1422 => 'Edge 500',                 // 'edge500_korea',
            1436 => 'Forerunner 70',            // 'fr70',
            1446 => 'Forerunner 310XT',         // 'fr310xt_4t',
            1461 => 'amx',
            1482 => 'Forerunner 10',            // 'fr10',
            1497 => 'Edge 800',                 // 'edge800_korea',
            1499 => 'swim',
            1537 => 'Forerunner 910XT',         // 'fr910xt_china',
            1551 => 'Fenix',                    // fenix
            1555 => 'edge200_taiwan',
            1561 => 'Edge 510',                 // 'edge510',
            1567 => 'Edge 810',                 // 'edge810',
            1570 => 'tempe',
            1600 => 'Forerunner 910XT',         // 'fr910xt_japan',
            1623 => 'Forerunner 620',           // 'fr620',
            1632 => 'Forerunner 220',           // 'fr220',
            1664 => 'Forerunner 910XT',         // 'fr910xt_korea',
            1688 => 'Forerunner 10',            // 'fr10_japan',
            1721 => 'Edge 810',                 // 'edge810_japan',
            1735 => 'virb_elite',
            1736 => 'edge_touring',
            1742 => 'Edge 510',                 // 'edge510_japan',
            1743 => 'HRM-Tri',                  // 'hrm_tri',
            1752 => 'hrm_run',
            1765 => 'Forerunner 920XT',         // 'fr920xt',
            1821 => 'Edge 510',                 // 'edge510_asia',
            1822 => 'Edge 810',                 // 'edge810_china',
            1823 => 'Edge 810',                 // 'edge810_taiwan',
            1836 => 'Edge 1000',                // 'edge1000',
            1837 => 'vivo_fit',
            1853 => 'virb_remote',
            1885 => 'vivo_ki',
            1903 => 'Forerunner 15',            // 'fr15',
            1907 => 'vivoactive',               // 'vivo_active',
            1918 => 'Edge 510',                 // 'edge510_korea',
            1928 => 'Forerunner 620',           // 'fr620_japan',
            1929 => 'Forerunner 620',           // 'fr620_china',
            1930 => 'Forerunner 220',           // 'fr220_japan',
            1931 => 'Forerunner 220',           // 'fr220_china',
            1936 => 'Approach S6',              // 'approach_s6'
            1956 => 'vívosmart',                // 'vivo_smart',
            1967 => 'Fenix 2',                  // fenix2
            1988 => 'epix',
            2050 => 'Fenix 3',                  // fenix3
            2052 => 'Edge 1000',                // edge1000_taiwan
            2053 => 'Edge 1000',                // edge1000_japan
            2061 => 'Forerunner 15',            // fr15_japan
            2067 => 'Edge 520',                 // edge520
            2070 => 'Edge 1000',                // edge1000_china
            2072 => 'Forerunner 620',           // fr620_russia
            2073 => 'Forerunner 220',           // fr220_russia
            2079 => 'Vector S',                 // vector_s
            2100 => 'Edge 1000',                // edge1000_korea
            2130 => 'Forerunner 920',           // fr920xt_taiwan
            2131 => 'Forerunner 920',           // fr920xt_china
            2132 => 'Forerunner 920',           // fr920xt_japan
            2134 => 'virbx',
            2135 => 'vívosmart',                // vivo_smart_apac',
            2140 => 'etrex_touch',
            2147 => 'Edge 25',                  // edge25
            2148 => 'Forerunner 25',            // fr25
            2150 => 'vivo_fit2',
            2153 => 'Forerunner 225',           // fr225
            2156 => 'Forerunner 630',           // fr630
            2157 => 'Forerunner 230',           // fr230
            2158 => 'Forerunner 735XT',
            2160 => 'vivo_active_apac',
            2161 => 'Vector 2',                 // vector_2
            2162 => 'Vector 2S',                // vector_2s
            2172 => 'virbxe',
            2173 => 'Forerunner 620',           // fr620_taiwan
            2174 => 'Forerunner 220',           // fr220_taiwan
            2175 => 'truswing',
            2187 => 'D2 Air Venu',
            2188 => 'Fenix 3',                  // fenix3_china
            2189 => 'Fenix 3',                  // fenix3_twn
            2192 => 'varia_headlight',
            2193 => 'varia_taillight_old',
            2204 => 'Edge Explore 1000',        // edge_explore_1000
            2219 => 'Forerunner 225',           // fr225_asia
            2225 => 'varia_radar_taillight',
            2226 => 'varia_radar_display',
            2238 => 'Edge 20',                  // edge20
            2260 => 'Edge 520 Asia',
            2261 => 'Edge 520 Japan',
            2262 => 'D2 Bravo',                 // d2_bravo
            2266 => 'approach_s20',
            2271 => 'Vivosmart 2',
            2274 => 'Edge 1000 Thai',
            2276 => 'varia_remote',
            2288 => 'Edge 25 Asia',
            2289 => 'Edge 25 Japan',
            2290 => 'Edge 20 Asia',
            2292 => 'Approach X40',
            2293 => 'Fenix 3 Japan',
            2294 => 'Vivosmart EMEA',
            2310 => 'Forerunner 630 Asia',
            2311 => 'Forerunner 630 Japan',
            2313 => 'Forerunner 230 Japan',
            2327 => 'hrm4_run',
            2332 => 'Epix Japan',
            2337 => 'vivo_active_hr',
            2347 => 'vivo_smart_gps_hr',
            2348 => 'vivo_smart_hr',
            2361 => 'Vivosmart HR Asia',
            2362 => 'Vivosmart GPS HR Asia',
            2368 => 'vivo_move',
            2379 => 'Varia Taillight',
            2396 => 'Forerunner 235 Asia',
            2397 => 'Forerunner 235 Japan',
            2398 => 'varia_vision',
            2406 => 'vivo_fit3',
            2407 => 'Fenix 3 Korea',
            2408 => 'Fenix 3 SEA',
            2413 => 'Fenix 3 HR',               // fenix3_hr
            2417 => 'Virb Ultra 30',            // virb_ultra_30
            2429 => 'index_smart_scale',
            2431 => 'Forerunner 235',           // fr235
            2432 => 'Fenix 3 Chronos',          // fenix3_chronos
            2441 => 'oregon7xx',
            2444 => 'rino7xx',
            2457 => 'Epix Korea',
            2473 => 'Fenix 3 HR China',
            2474 => 'Fenix 3 HR Taiwan',
            2475 => 'Fenix 3 HR Japan',
            2476 => 'Fenix 3 HR SEA',
            2477 => 'Fenix 3 HR Korea',
            2496 => 'nautix',
            2497 => 'Vivoactive HR APAC',
            2503 => 'Forerunner 35',
            2512 => 'Oregon 7xx',
            2530 => 'Edge 820',                 // edge_820
            2531 => 'Edge Explore 820',         // edge_explore_820
            2533 => 'Forerunner 735XT APAC',
            2534 => 'Forerunner 735XT Japan',
            2544 => 'fenix5s',
            2547 => 'D2 Bravo Titanium',        // d2_bravo_titanium
            2567 => 'Varia UT800',
            2593 => 'Running Dynamics Pod',     // running_dynamics_pod
            2599 => 'Edge 820 China',
            2600 => 'Edge 820 Japan',
            2604 => 'Fenix 5x',                 // fenix5x
            2606 => 'vivofit jr',               // vivo_fit_jr
            2622 => 'Vivosmart 3',
            2623 => 'Vivosport',
            2628 => 'Edge 820 Taiwan',
            2629 => 'Edge 820 Korea',
            2630 => 'Edge 820 SEA',
            2650 => 'Forerunner 35 Hebrew',
            2656 => 'Approach S60',
            2667 => 'Forerunner 35 APAC',
            2668 => 'Forerunner 35 Japan',
            2675 => 'Fenix 3 Chronos Asia',
            2687 => 'Virb 360',
            2691 => 'Forerunner 935',           // fr935
            2697 => 'Fenix 5',                  // fenix5
            2700 => 'vivoactive3',
            2769 => 'foretrex_601_701',
            2772 => 'vivo_move_hr',
            2787 => 'Vector 3',
            2796 => 'Fenix 5 Asia',
            2797 => 'Fenix 5S Asia',
            2798 => 'Fenix 5X Asia',
            2713 => 'Edge 1030',                // edge_1030
            2727 => 'Forerunner 35 SEA',
            2733 => 'Forerunner 235 China NFC',
            2806 => 'approach_z80',
            2814 => 'Forerunner 35 Korea',
            2819 => 'D2 Charlie',
            2831 => 'vivo_smart3_apac',
            2832 => 'vivo_sport_apac',
            2833 => 'Forerunner 935 Asia',
            2859 => 'descent',
            2878 => 'Vivofit 4',
            2886 => 'Forerunner 645',           // fr645
            2888 => 'Forerunner 645',           // fr645m
            2891 => 'Forerunner 30',
            2900 => 'Fenix 5S Plus',            // fenix5s_plus
            2909 => 'Edge 130',                 // Edge_130
            2924 => 'Edge 1030 Asia',
            2927 => 'vivosmart_4',
            2945 => 'Vivomove HR Asia',
            2962 => 'approach_x10',
            2977 => 'Forerunner 30 Asia',
            2988 => 'vivoactive3m_w',
            3003 => 'Forerunner 645 Asia',
            3004 => 'Forerunner 645M Asia',
            3011 => 'edge_explore',
            3028 => 'gpsmap66',
            3049 => 'approach_s10',
            3066 => 'vivoactive3m_l',
            3076 => 'Forerunner 245',
            3077 => 'Forerunner 245 Music',
            3085 => 'approach_g80',
            3092 => 'Edge 130 Asia',
            3095 => 'Edge 1030 Bontrager',
            3110 => 'Fenix 5 Plus',             // fenix5_plus
            3111 => 'Fenix 5X Plus',            // fenix5x_plus
            3112 => 'Edge 520 Plus',            // edge_520_plus
            3113 => 'Forerunner 945',
            3121 => 'Edge 530',
            3122 => 'Edge 830',
            3126 => 'Instinct Esports',
            3134 => 'Fenix 5S Plus APAC',
            3135 => 'Fenix 5X Plus APAC',
            3142 => 'Edge 520 Plus APAC',
            3143 => 'Descent T1',
            3144 => 'Forerunner 235L Asia',
            3145 => 'Forerunner 245 Asia',
            3163 => 'Vivoactive 3M APAC',
            3192 => 'Bike Speed Sensor',        // gen3_bsm
            3193 => 'Bike Cadence Sensor',      // gen3_bcm
            3218 => 'Vivosmart 4 Asia',
            3224 => 'Vivoactive 4S',
            3225 => 'Vivoactive 4',
            3226 => 'Venu',
            3246 => 'MARQ Driver',
            3247 => 'MARQ Aviator',
            3248 => 'MARQ Captain',
            3249 => 'MARQ Commander',
            3250 => 'MARQ Expedition',
            3251 => 'MARQ Athlete',
            3258 => 'Descent Mk2',
            3282 => 'Forerunner 45',
            3284 => 'GPSMAP 66i',
            3287 => 'Fenix 6S Sport',
            3288 => 'Fenix 6S',
            3289 => 'Fenix 6 Sport',
            3290 => 'Fenix 6',
            3291 => 'Fenix 6X',
            3299 => 'hrm_dual',
            3300 => 'HRM Pro',
            3308 => 'Vivomove 3 Premium',
            3314 => 'approach_s40',

            // Newer Garmin products (2019+) - Source: Garmin FIT SDK Profile.xlsx
            3321 => 'Forerunner 245M Asia',
            3349 => 'Edge 530 APAC',
            3350 => 'Edge 830 APAC',
            3378 => 'Vivomove 3',
            3387 => 'Vivoactive 4S Asia',
            3388 => 'Vivoactive 4 Asia',
            3389 => 'Vivoactive 4 OLED Asia',
            3405 => 'Swim 2',
            3420 => 'MARQ Driver Asia',
            3421 => 'MARQ Aviator Asia',
            3422 => 'Vivomove 3 Asia',
            3441 => 'Forerunner 945 Asia',
            3446 => 'Vivoactive 3T China',
            3448 => 'MARQ Captain Asia',
            3449 => 'MARQ Commander Asia',
            3450 => 'MARQ Expedition Asia',
            3451 => 'MARQ Athlete Asia',
            3461 => 'Index Smart Scale 2',
            3466 => 'Instinct Solar',
            3469 => 'Forerunner 45 Asia',
            3473 => 'Vivoactive 3 Daimler',
            3498 => 'Legacy Rey',
            3499 => 'Legacy Darth Vader',
            3500 => 'Legacy Captain Marvel',
            3501 => 'Legacy First Avenger',
            3512 => 'Fenix 6S Sport Asia',
            3513 => 'Fenix 6S Asia',
            3514 => 'Fenix 6 Sport Asia',
            3515 => 'Fenix 6 Asia',
            3516 => 'Fenix 6X Asia',
            3535 => 'Legacy Captain Marvel Asia',
            3536 => 'Legacy First Avenger Asia',
            3537 => 'Legacy Rey Asia',
            3538 => 'Legacy Darth Vader Asia',
            3542 => 'Descent Mk2S',
            3558 => 'Edge 130 Plus',
            3570 => 'Edge 1030 Plus',
            3578 => 'Rally 200',
            3589 => 'Forerunner 745',
            3596 => 'Venu Sq Music',
            3599 => 'Venu Sq Music V2',
            3600 => 'Venu Sq',
            3615 => 'Lily',
            3624 => 'MARQ Adventurer',
            3638 => 'Enduro',
            3639 => 'Swim 2 APAC',
            3648 => 'MARQ Adventurer Asia',
            3652 => 'Forerunner 945 LTE',
            3702 => 'Descent Mk2 Asia',
            3703 => 'Venu 2',
            3704 => 'Venu 2S',
            3737 => 'Venu Daimler Asia',
            3739 => 'MARQ Golfer',
            3740 => 'Venu Daimler',
            3794 => 'Forerunner 745 Asia',
            3808 => 'Varia RCT715',
            3809 => 'Lily Asia',
            3812 => 'Edge 1030 Plus Asia',
            3813 => 'Edge 130 Plus Asia',
            3823 => 'Approach S12',
            3837 => 'Venu Sq Asia',
            3843 => 'Edge 1040',
            3850 => 'MARQ Golfer Asia',
            3851 => 'Venu 2 Plus',
            3865 => 'GNSS',                     // Airoha AG3335M chip
            3869 => 'Forerunner 55',
            3872 => 'Enduro Asia',
            3888 => 'Instinct 2',
            3889 => 'Instinct 2S',
            3905 => 'Fenix 7S',
            3906 => 'Fenix 7',
            3907 => 'Fenix 7X',
            3908 => 'Fenix 7S APAC',
            3909 => 'Fenix 7 APAC',
            3910 => 'Fenix 7X APAC',
            3927 => 'Approach G12',
            3930 => 'Descent Mk2S Asia',
            3934 => 'Approach S42',
            3943 => 'Epix Gen 2',
            3944 => 'Epix Gen 2 APAC',
            3949 => 'Venu 2S Asia',
            3950 => 'Venu 2 Asia',
            3978 => 'Forerunner 945 LTE Asia',
            3982 => 'Vivomove Sport',
            3983 => 'Vivomove Trend',
            3986 => 'Approach S12 Asia',
            3990 => 'Forerunner 255 Music',
            3991 => 'Forerunner 255S Music',
            3992 => 'Forerunner 255',
            3993 => 'Forerunner 255S',
            4001 => 'Approach G12 Asia',
            4002 => 'Approach S42 Asia',
            4005 => 'Descent G1',
            4017 => 'Venu 2 Plus Asia',
            4024 => 'Forerunner 955',
            4033 => 'Forerunner 55 Asia',
            4061 => 'Edge 540',
            4062 => 'Edge 840',
            4063 => 'Vivosmart 5',
            4071 => 'Instinct 2 Asia',
            4105 => 'MARQ Gen 2',
            4115 => 'Venu Sq 2',
            4116 => 'Venu Sq 2 Music',
            4124 => 'MARQ Gen 2 Aviator',
            4125 => 'D2 Air X10',
            4130 => 'HRM-Pro Plus',
            4132 => 'Descent G1 Asia',
            4135 => 'Tactix 7',
            4155 => 'Instinct Crossover',
            4169 => 'Edge Explore 2',
            4222 => 'Descent Mk3',
            4223 => 'Descent Mk3i',
            4233 => 'Approach S70',
            4257 => 'Forerunner 265',
            4258 => 'Forerunner 265S',
            4260 => 'Venu 3',
            4261 => 'Venu 3S',
            4265 => 'Tacx Neo Smart',
            4266 => 'Tacx Neo 2 Smart',
            4267 => 'Tacx Neo 2T Smart',
            4268 => 'Tacx Neo Smart Bike',
            4269 => 'Tacx Satori Smart',
            4270 => 'Tacx Flow Smart',
            4271 => 'Tacx Vortex Smart',
            4272 => 'Tacx Bushido Smart',
            4273 => 'Tacx Genius Smart',
            4274 => 'Tacx Flux/Flux S Smart',
            4275 => 'Tacx Flux 2 Smart',
            4276 => 'Tacx Magnum',
            4305 => 'Edge 1040 Asia',
            4312 => 'Epix Gen 2 Pro 42mm',
            4313 => 'Epix Gen 2 Pro 47mm',
            4314 => 'Epix Gen 2 Pro 51mm',
            4315 => 'Forerunner 965',
            4341 => 'Enduro 2',
            4374 => 'Fenix 7S Pro Solar',
            4375 => 'Fenix 7 Pro Solar',
            4376 => 'Fenix 7X Pro Solar',
            4380 => 'Lily 2',
            4394 => 'Instinct 2X',
            4426 => 'Vivoactive 5',
            4432 => 'Forerunner 165',
            4433 => 'Forerunner 165 Music',
            4440 => 'Edge 1050',
            4442 => 'Descent T2',
            4446 => 'HRM-Fit',
            4472 => 'MARQ Gen 2 Commander',
            4477 => 'Lily Athlete',
            4525 => 'Rally X10',
            4532 => 'Fenix 8 Solar 47mm',
            4533 => 'Fenix 8 Solar 51mm',
            4534 => 'Fenix 8 43mm',
            4536 => 'Fenix 8 47mm',
            4556 => 'D2 Mach 1 Pro',
            4565 => 'Forerunner 970',
            4570 => 'Forerunner 570 47mm',
            4574 => 'Forerunner 570 42mm',
            4575 => 'Enduro 3',
            4583 => 'Instinct E 40mm',
            4584 => 'Instinct E 45mm',
            4585 => 'Instinct 3 Solar 45mm',
            4586 => 'Instinct 3 AMOLED 45mm',
            4587 => 'Instinct 3 AMOLED 50mm',
            4588 => 'Descent G2',
            4595 => 'Fenix 7 Pro Solar No Wi-Fi',
            4603 => 'Venu X1',
            4606 => 'HRM-200',
            4625 => 'Vivoactive 6',
            4631 => 'Fenix 8 Pro',
            4633 => 'Edge 550',
            4634 => 'Edge 850',
            4643 => 'Venu 4',
            4644 => 'Venu 4S',
            4647 => 'Approach S44',
            4655 => 'Edge MTB',
            4656 => 'Approach S50',
            4666 => 'Fenix E',
            4678 => 'Instinct Crossover AMOLED',
            4745 => 'Bounce 2',
            4759 => 'Instinct 3 Solar 50mm',
            4775 => 'Tactix 8 AMOLED',
            4776 => 'Tactix 8 Solar',
            4814 => 'Forerunner 170 Music',
            4815 => 'Forerunner 170',
            4825 => 'Approach J1',
            4874 => 'Approach S72 43mm',
            4875 => 'Approach S72 47mm',
            4879 => 'D2 Mach 2',
            4916 => 'Forerunner 70 (2026)',
            4944 => 'D2 Air X15',
            4952 => 'Fenix 9 Pro 43mm',
            4953 => 'Fenix 9 Pro 47mm',
            4954 => 'Fenix 9 Pro 51mm',
            4955 => 'Fenix 9 Pro Solar 47mm',
            4956 => 'Fenix 9 Pro Solar 51mm',
            5019 => 'Cirqa Smart Band',
            5056 => 'D2 Mach 2 Pro',
            5133 => 'Fenix 9 43mm',
            5134 => 'Fenix 9',                  // 47mm & 57mm
            5169 => 'Enduro 4',

            10007 => 'SDM4 footpod',            // sdm4
            10014 => 'edge_remote',
            20119 => 'training_center',
            20533 => 'Tacx Training App Windows',
            20534 => 'Tacx Training App Mac',
            20565 => 'Tacx Training App Mac Catalyst',
            30045 => 'Tacx Training App Android',
            30046 => 'Tacx Training App iOS',
            30047 => 'Tacx Training App Legacy',
            30059 => 'TrainingPeaks Virtual iOS',
            30060 => 'TrainingPeaks Virtual Android',
            30061 => 'TrainingPeaks Virtual Windows',
            30062 => 'TrainingPeaks Virtual macOS',
            30063 => 'TrainingPeaks Virtual Apple TV',
            65531 => 'connectiq_simulator',
            65532 => 'android_antplus_plugin',
            65534 => 'Garmin Connect website'   // connect
        ],
        'sport' => [  // Have capitalised and replaced underscores with spaces.
            0 => 'Other',
            1 => 'Running',
            2 => 'Cycling',
            3 => 'Transition',
            4 => 'Fitness equipment',
            5 => 'Swimming',
            6 => 'Basketball',
            7 => 'Soccer',
            8 => 'Tennis',
            9 => 'American football',
            10 => 'Training',
            11 => 'Walking',
            12 => 'Cross country skiing',
            13 => 'Alpine skiing',
            14 => 'Snowboarding',
            15 => 'Rowing',
            16 => 'Mountaineering',
            17 => 'Hiking',
            18 => 'Multisport',
            19 => 'Paddling',
            20 => 'Flying',
            21 => 'E-Biking',
            22 => 'Motorcycling',
            23 => 'Boating',
            24 => 'Driving',  // was 'Diving' (53 is diving); the FIT SDK value is 'driving'
            25 => 'Golf',
            26 => 'Hang gliding',
            27 => 'Horseback riding',
            28 => 'Hunting',
            29 => 'Fishing',
            30 => 'Inline skating',
            31 => 'Rock climbing',
            32 => 'Sailing',
            33 => 'Ice skating',
            34 => 'Sky diving',
            35 => 'Snowshoeing',
            36 => 'Snowmobiling',
            37 => 'Stand up paddleboarding',
            38 => 'Surfing',
            39 => 'Wakeboarding',
            40 => 'Water skiing',
            41 => 'Kayaking',
            42 => 'Rafting',
            43 => 'Windsurfing',
            44 => 'Kitesurfing',
            45 => 'Tactical',
            46 => 'Jumpmaster',
            47 => 'Boxing',
            48 => 'Floor climbing',
            53 => 'Diving',
            62 => 'HIIT',
            64 => 'Racket',
            65 => 'Wheelchair push walk',
            66 => 'Wheelchair push run',
            67 => 'Meditation',
            76 => 'Water tubing',
            77 => 'Wakesurfing',
            84 => 'Jump rope',
            254 => 'All'
        ],
        'sub_sport' => [  // Have capitalised and replaced underscores with spaces.
            0 => 'Other',
            1 => 'Treadmill',
            2 => 'Street',
            3 => 'Trail',
            4 => 'Track',
            5 => 'Spin',
            6 => 'Indoor cycling',
            7 => 'Road',
            8 => 'Mountain',
            9 => 'Downhill',
            10 => 'Recumbent',
            11 => 'Cyclocross',
            12 => 'Hand cycling',
            13 => 'Track cycling',
            14 => 'Indoor rowing',
            15 => 'Elliptical',
            16 => 'Stair climbing',
            17 => 'Lap swimming',
            18 => 'Open water',
            19 => 'Flexibility training',
            20 => 'Strength training',
            21 => 'Warm up',
            22 => 'Match',
            23 => 'Exercise',
            24 => 'Challenge',
            25 => 'Indoor skiing',
            26 => 'Cardio training',
            27 => 'Indoor walking',
            28 => 'E-Bike Fitness',
            29 => 'BMX',
            30 => 'Casual walking',
            31 => 'Speed walking',
            32 => 'Bike to run transition',
            33 => 'Run to bike transition',
            34 => 'Swim to bike transition',
            35 => 'ATV',
            36 => 'Motocross',
            37 => 'Backcountry skiing',
            38 => 'Resort skiing',
            39 => 'RC Drone',
            40 => 'Wingsuit',
            41 => 'Whitewater Kayaking',
            42 => 'Skate skiing',
            43 => 'Yoga',
            44 => 'Pilates',
            45 => 'Indoor running',
            46 => 'Gravel cycling',
            47 => 'Mountain Ebike',
            48 => 'Commuting cycling',
            49 => 'Mixed surface cycling',
            50 => 'Navigate',
            51 => 'Track me',
            52 => 'Map',
            53 => 'Single gas diving',
            54 => 'Multi gas diving',
            55 => 'Gauge diving',
            56 => 'Apnea diving',
            57 => 'Apnea hunting',
            58 => 'Virtual activity',
            59 => 'Obstacle',
            62 => 'Breathing',
            65 => 'Sail race',
            67 => 'Ultra',
            68 => 'Indoor climbing',
            69 => 'Bouldering',
            70 => 'Hiit',
            73 => 'Amrap',
            74 => 'Emom',
            75 => 'Tabata',
            84 => 'Pickleball',
            85 => 'Padel',
            86 => 'Indoor wheelchair walk',
            87 => 'Indoor wheelchair run',
            88 => 'Indoor hand cycling',
            110 => 'Fly canopy',
            111 => 'Fly paraglide',
            112 => 'Fly paramotor',
            113 => 'Fly pressurized',
            114 => 'Fly navigate',
            115 => 'Fly timer',
            116 => 'Fly altimeter',
            117 => 'Fly wx',
            118 => 'Fly vfr',
            119 => 'Fly ifr',
            254 => 'All'
        ],
        'session_trigger' => [0 => 'activity_end', 1 => 'manual', 2 => 'auto_multi_sport', 3 => 'fitness_equipment'],
        'source_type' => [
            0 => 'ant',  //External device connected with ANT
            1 => 'antplus',  //External device connected with ANT+
            2 => 'bluetooth',  //External device connected with BT
            3 => 'bluetooth_low_energy',  //External device connected with BLE
            4 => 'wifi',  //External device connected with Wifi
            5 => 'local',  //Onboard device
        ],
        'swim_stroke' => [0 => 'Freestyle', 1 => 'Backstroke', 2 => 'Breaststroke', 3 => 'Butterfly', 4 => 'Drill', 5 => 'Mixed', 6 => 'IM'],  // Have capitalised.
        'water_type' => [0 => 'fresh', 1 => 'salt', 2 => 'en13319', 3 => 'custom'],
        'tissue_model_type' => [0 => 'zhl_16c'],
        'dive_gas_status' => [0 => 'disabled', 1 => 'enabled', 2 => 'backup_only'],
        'dive_alarm_type' => [0 => 'depth', 1 => 'time'],
        'dive_backlight_mode' => [0 => 'at_depth', 1 => 'always_on'],
    ];

    /**
     * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 2.2.pdf
     * Table 4-6. FIT Base Types and Invalid Values
     *
     * Per base type: its size, the unpack() code for little- and big-endian data, the bit width a
     * signed type is sign-extended from, and its invalid value as unpacked. Integers are unpacked
     * unsigned and sign-extended afterwards, before any scale or offset, so the invalid value is
     * compared unsigned. 64-bit codes unpack to PHP's signed int, so uint64's all-ones is -1; a
     * float's all-ones unpacks to NaN, so any NaN is invalid ('float'). Strings (0x07) are handled
     * apart, and byte (0x0D) arrays are invalid only when every byte is 0xFF.
     */
    private const BASE_TYPES = [
        0x00 => ['bytes' => 1, 'le' => 'C', 'be' => 'C', 'sign_bits' => 0,  'invalid' => 0xFF],                // enum
        0x01 => ['bytes' => 1, 'le' => 'C', 'be' => 'C', 'sign_bits' => 8,  'invalid' => 0x7F],                // sint8
        0x02 => ['bytes' => 1, 'le' => 'C', 'be' => 'C', 'sign_bits' => 0,  'invalid' => 0xFF],                // uint8
        0x83 => ['bytes' => 2, 'le' => 'v', 'be' => 'n', 'sign_bits' => 16, 'invalid' => 0x7FFF],              // sint16
        0x84 => ['bytes' => 2, 'le' => 'v', 'be' => 'n', 'sign_bits' => 0,  'invalid' => 0xFFFF],              // uint16
        0x85 => ['bytes' => 4, 'le' => 'V', 'be' => 'N', 'sign_bits' => 32, 'invalid' => 0x7FFFFFFF],          // sint32
        0x86 => ['bytes' => 4, 'le' => 'V', 'be' => 'N', 'sign_bits' => 0,  'invalid' => 0xFFFFFFFF],          // uint32
        0x88 => ['bytes' => 4, 'le' => 'g', 'be' => 'G', 'sign_bits' => 0,  'float' => true],  // float32
        0x89 => ['bytes' => 8, 'le' => 'e', 'be' => 'E', 'sign_bits' => 0,  'float' => true],  // float64
        0x0A => ['bytes' => 1, 'le' => 'C', 'be' => 'C', 'sign_bits' => 0,  'invalid' => 0],                   // uint8z
        0x8B => ['bytes' => 2, 'le' => 'v', 'be' => 'n', 'sign_bits' => 0,  'invalid' => 0],                   // uint16z
        0x8C => ['bytes' => 4, 'le' => 'V', 'be' => 'N', 'sign_bits' => 0,  'invalid' => 0],                   // uint32z
        0x0D => ['bytes' => 1, 'le' => 'C', 'be' => 'C', 'sign_bits' => 0,  'invalid' => 0xFF],                // byte
        0x8E => ['bytes' => 8, 'le' => 'P', 'be' => 'J', 'sign_bits' => 0,  'invalid' => PHP_INT_MAX],         // sint64 (unpacks signed already)
        0x8F => ['bytes' => 8, 'le' => 'P', 'be' => 'J', 'sign_bits' => 0,  'invalid' => -1],                  // uint64
        0x90 => ['bytes' => 8, 'le' => 'P', 'be' => 'J', 'sign_bits' => 0,  'invalid' => 0],                   // uint64z
    ];
    // Manufacturers whose product numbers are garmin_product values (FIT SDK subfield map):
    // garmin, dynastream, dynastream_oem, tacx.
    private const GARMIN_PRODUCT_MANUFACTURERS = [1, 15, 13, 89];

    // enumData() type names that differ from the FIT SDK's, and the types whose SDK names it
    // capitalises with spaces for underscores, as $enum_data does for them.
    private const ENUM_TYPE_ALIASES = ['product' => 'garmin_product'];
    private const CAPITALISED_ENUM_TYPES = ['sport', 'sub_sport'];
    // APP-2016: single-byte ANT+ sensor product IDs, left 'unknown' rather than named from the SDK.
    // They are Suunto, Polar and Wahoo watches too, which athletica-app names only when this
    // returns 'unknown' (see the comment on $enum_data['product']).
    private const UNNAMED_PRODUCT_IDS = [13, 15, 16, 22];

    private const BASE_TYPE_STRING = 0x07;
    private const BASE_TYPE_BYTE = 0x0D;
    private const BASE_TYPE_UINT64 = 0x8F;
    private const BASE_TYPE_UINT64Z = 0x90;

    // Every other message's field lists hold one entry per message, null where a message has no
    // valid value (APP-1788 for laps; any message had the same misalignment otherwise). Records are
    // keyed by timestamp instead, and hr arrays are decoded together by processHrMessages().
    private const UNPADDED_MESSAGES = ['record', 'hr'];

    // Session fields present on every session even when no session carries a valid value; callers
    // read these without isset() (they were the only session fields padded before).
    private const ALWAYS_PRESENT_SESSION_FIELDS = [
        'avg_heart_rate', 'max_heart_rate', 'avg_power', 'max_power', 'normalized_power', 'total_work', 'total_cycles',
        'avg_cadence', 'max_cadence', 'avg_fractional_cadence', 'max_fractional_cadence', 'training_stress_score',
        'intensity_factor', 'threshold_power', 'time_in_hr_zone', 'total_training_effect', 'total_ascent', 'total_descent',
    ];

    /**
     * Messages and fields, by global message number: the FIT SDK profile (Profile::MESSAGES,
     * generated by bin/generate-profile.php) with FIELD_OVERRIDES applied. See messageInfo().
     */
    private array $data_mesg_info = [];

    /**
     * Where this library deliberately departs from the FIT SDK profile, per message and field
     * number: a field kept raw for code that decodes it by hand. (Session 192/193, once returned as
     * 'feel' and 'rpe' at 1/10th scale, are now only the SDK's workout_feel and workout_rpe.)
     */
    private const FIELD_OVERRIDES = [
        132 => [  // hr: processHrMessages() decodes these in 1/1024 s itself
            9 => ['scale' => 1],                       // event_timestamp
            10 => ['scale' => 1, 'components' => []],  // event_timestamp_12
        ],
    ];

    /**
     * Profile::MESSAGES in the shape this class reads -- mesg_name, and field_defns with
     * field_name, type, scale, offset, units and, where the profile has them, components,
     * accumulated, accumulate_from and subfields -- with FIELD_OVERRIDES applied. Built once per
     * process.
     */
    private static function messageInfo(): array
    {
        static $info = null;
        if ($info !== null) {
            return $info;
        }

        $info = [];
        foreach (Profile::MESSAGES as $num => $message) {
            $fields = [];
            foreach ($message['fields'] as $field_num => $field) {
                $field = array_replace(['field_name' => $field['name'], 'num' => $field_num] + $field, self::FIELD_OVERRIDES[$num][$field_num] ?? []);
                foreach ($field['subfields'] ?? [] as $i => $subfield) {
                    $field['subfields'][$i]['field_name'] = $subfield['name'];
                }
                $fields[$field_num] = $field;
            }
            // An accumulated field is seeded, when a message carries it outright, in the units of
            // the component that accumulates into it.
            foreach ($fields as $field) {
                foreach ($field['components'] ?? [] as [$target, , $scale, $offset]) {
                    if (!empty($fields[$target]['accumulated']) && !isset($fields[$target]['accumulate_from'])) {
                        $fields[$target]['accumulate_from'] = [$scale, $offset];
                    }
                }
            }
            $info[$num] = ['mesg_name' => $message['name'], 'field_defns' => $fields];
        }

        return $info;
    }

    // PHP Constructor - called when an object of the class is instantiated.
    public function __construct($file_path_or_data, $options = null)
    {
        if( isset( $options['input_is_data'] ) ){
            $this->file_contents = $file_path_or_data;
        }else{
            if (empty($file_path_or_data)) {
                throw new \Exception('phpFITFileAnalysis->__construct(): file_path is empty!');
            }
            if (!file_exists($file_path_or_data)) {
                throw new \Exception('phpFITFileAnalysis->__construct(): file \''.$file_path_or_data.'\' does not exist!');
            }
            /**
             * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 1.7.pdf
             * 3.3 FIT File Structure
             * Header . Data Records . CRC
             */
            $contents = file_get_contents($file_path_or_data);  // Read the entire file into a string
            if ($contents === false) {
                throw new \Exception('phpFITFileAnalysis->__construct(): file \''.$file_path_or_data.'\' could not be read!');
            }
            $this->file_contents = $contents;
        }

        $this->options = is_array($options) ? $options : [];
        if (isset($options['garmin_timestamps']) && $options['garmin_timestamps'] == true) {
            $this->garmin_timestamps = true;
        }
        // FIT SDK decoding: subfields on by default. Components off by default: expanding speed into
        // enhanced_speed (and altitude into enhanced_altitude) would hold, fill in and convert a
        // second copy of each stream. 'enhanced' expands into enhanced_* fields only, true into
        // every component. See namedValues().
        $this->options['expand_subfields'] = $options['expand_subfields'] ?? true;
        $this->options['expand_components'] = $options['expand_components'] ?? false;
        // CX-87: a developer field declaring record power (Stryd's) replaces the watch's power, one
        // session at a time; see applyDeveloperOverrides(). False reads the file as before.
        $this->options['overwrite_with_dev_data'] = $options['overwrite_with_dev_data'] ?? true;
        $this->data_mesg_info = self::messageInfo();
        $this->php_trader_ext_loaded = extension_loaded('trader');

        // Process the file contents.
        $this->readFile();

        // Process HR messages. Before oneElementArrays(): with a single hr message carrying
        // event_timestamp_12 that would unwrap the list of byte arrays into one byte array.
        $this->processHrMessages();
        // 'always_arrays' => true keeps every field a list (record fields keyed by timestamp), so a
        // file with one session reads like a file with two. By default a one-value field is that
        // value, which callers have relied on.
        if (empty($this->options['always_arrays'])) {
            $this->oneElementArrays();
        }

        // Handle options.
        $this->fixData($this->options);
        $this->setUnits($this->options);
    }

    /**
     * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 1.7.pdf
     * Table 3-1. Byte Description of File Header
     *
     * Reads the header at the file pointer. A chained FIT file is several Header . Data . CRC runs
     * back to back, so this is called once per run; see readFile().
     */
    private function readHeader(): void
    {
        $file_start = $this->file_pointer;
        if (strlen($this->file_contents) - $file_start < 12) {
            throw new FitParseException('phpFITFileAnalysis->readHeader(): not a valid header size!');
        }
        $header_size = ord($this->file_contents[$file_start]);

        if ($header_size < 12) {
            throw new FitParseException('phpFITFileAnalysis->readHeader(): not a valid header size!');
        }
        if (strlen($this->file_contents) - $file_start < $header_size) {
            throw new FitParseException('phpFITFileAnalysis->readHeader(): file is truncated in its header!');
        }

        $header_fields = 'C1protocol_version/' .
            'v1profile_version/' .
            'V1data_size/' .
            'C4data_type';
        if ($header_size >= 14) {
            $header_fields .= '/v1crc';
        }
        $this->file_header = unpack($header_fields, substr($this->file_contents, $file_start + 1, min($header_size, 14) - 1));
        $this->file_header['header_size'] = $header_size;

        $file_extension = sprintf('%c%c%c%c', $this->file_header['data_type1'], $this->file_header['data_type2'], $this->file_header['data_type3'], $this->file_header['data_type4']);

        if ($file_extension != '.FIT' || $this->file_header['data_size'] <= 0) {
            throw new FitParseException('phpFITFileAnalysis->readHeader(): not a valid FIT file!');
        }

        $this->file_pointer = $file_start + $header_size;
        $this->data_end = $this->file_pointer + $this->file_header['data_size'];

        if (!empty($this->options['validate_crc'])) {
            $this->validateCrc($file_start, $header_size);
        }
    }

    /**
     * Header . Data Records . CRC, repeated for a chained file. Local message types are scoped to
     * one run, so definitions are forgotten between runs; everything decoded accumulates.
     */
    private function readFile(): void
    {
        do {
            $this->readHeader();
            $this->readDataRecords();
            $this->defn_mesgs = [];
            $this->file_pointer = $this->data_end + 2;  // past the file CRC
        } while ($this->anotherFileFollows());
        $this->padDeveloperValues();
        // CX-87: before the copy, which skips the declarations the override applied.
        $this->applyDeveloperOverrides();
        $this->copyDeveloperDeclarations();
    }

    private function anotherFileFollows(): bool
    {
        $remaining = strlen($this->file_contents) - $this->file_pointer;

        return $remaining >= 12
            && ord($this->file_contents[$this->file_pointer]) >= 12
            && substr($this->file_contents, $this->file_pointer + 8, 4) === '.FIT';
    }

    /**
     * Opt-in ('validate_crc' => true): the header CRC when a 14-byte header carries one (0 means
     * not computed), and the file CRC over header and data.
     */
    private function validateCrc(int $file_start, int $header_size): void
    {
        if ($header_size >= 14 && $this->file_header['crc'] !== 0
            && self::crc16(substr($this->file_contents, $file_start, 12)) !== $this->file_header['crc']) {
            throw new FitParseException('phpFITFileAnalysis->validateCrc(): header CRC does not match!');
        }
        $crc_bytes = substr($this->file_contents, $this->data_end, 2);
        if (strlen($crc_bytes) < 2) {
            throw new FitParseException('phpFITFileAnalysis->validateCrc(): file is truncated, no CRC!');
        }
        if (self::crc16(substr($this->file_contents, $file_start, $this->data_end - $file_start)) !== unpack('v', $crc_bytes)[1]) {
            throw new FitParseException('phpFITFileAnalysis->validateCrc(): file CRC does not match!');
        }
    }

    /**
     * FIT CRC-16 (FIT Protocol, 3.3.2), a nibble at a time.
     */
    public static function crc16(string $data): int
    {
        static $table = [0x0000, 0xCC01, 0xD801, 0x1400, 0xF001, 0x3C00, 0x2800, 0xE401, 0xA001, 0x6C00, 0x7800, 0xB401, 0x5000, 0x9C01, 0x8801, 0x4400];

        $crc = 0;
        $length = strlen($data);
        for ($i = 0; $i < $length; ++$i) {
            $byte = ord($data[$i]);
            $tmp = $table[$crc & 0xF];
            $crc = (($crc >> 4) & 0x0FFF) ^ $tmp ^ $table[$byte & 0xF];
            $tmp = $table[$crc & 0xF];
            $crc = (($crc >> 4) & 0x0FFF) ^ $tmp ^ $table[($byte >> 4) & 0xF];
        }

        return $crc;
    }

    /**
     * Reads the data records of the current run of $this->file_contents and stores the data in the
     * $this->data_mesgs array.
     */
    private function readDataRecords(): void
    {
        while ($this->file_pointer < $this->data_end) {
            if ($this->file_pointer >= strlen($this->file_contents)) {
                throw new FitParseException('phpFITFileAnalysis->readDataRecords(): file is truncated at offset ' . $this->file_pointer . '!');
            }
            $record_header_byte = ord($this->file_contents[$this->file_pointer]);
            $this->file_pointer++;

            /**
             * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 2.2.pdf
             * Table 4-1. Normal Header Bit Field Description
             * Table 4-2. Compressed Timestamp Header Bit Field Description
             */
            if ($record_header_byte & 0x80) {
                // Compressed timestamp header: always a data message, no developer data.
                $local_mesg_type = ($record_header_byte >> 5) & 0x03;
                $this->readDataMessage($local_mesg_type, $record_header_byte & 0x1F);
            } elseif ($record_header_byte & 0x40) {
                $this->readDefinitionMessage($record_header_byte & 0x0F, (bool) ($record_header_byte & 0x20));
            } else {
                $this->readDataMessage($record_header_byte & 0x0F, null);
            }
        }
    }

    /**
     * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 2.2.pdf
     * Table 4-3. Definition Message Contents
     */
    private function readDefinitionMessage(int $local_mesg_type, bool $has_developer_data): void
    {
        $fixed = substr($this->file_contents, $this->file_pointer, 5);
        if (strlen($fixed) < 5) {
            throw new FitParseException('phpFITFileAnalysis->readDefinitionMessage(): file is truncated at offset ' . $this->file_pointer . '!');
        }
        // Byte 0 is reserved.
        $architecture = ord($fixed[1]);
        if ($architecture > 1) {
            throw new FitParseException('phpFITFileAnalysis->readDefinitionMessage(): unknown architecture ' . $architecture . ' at offset ' . $this->file_pointer . '!');
        }
        $global_mesg_num = unpack($architecture === 0 ? 'v' : 'n', substr($fixed, 2, 2))[1];
        $num_fields = ord($fixed[4]);
        $this->file_pointer += 5;

        $field_definitions = $this->readFieldDefinitions($num_fields, 'base_type');
        $total_size = array_sum(array_column($field_definitions, 'size'));

        $num_dev_fields = 0;
        $dev_field_definitions = [];
        if ($has_developer_data) {
            $num_dev_fields = ord(substr($this->file_contents, $this->file_pointer, 1));
            $this->file_pointer++;
            $dev_field_definitions = $this->readFieldDefinitions($num_dev_fields, 'developer_data_index');
            $total_size += array_sum(array_column($dev_field_definitions, 'size'));
        }

        $definition = [
            'global_mesg_num' => $global_mesg_num,
            'architecture' => $architecture,
            'num_fields' => $num_fields,
            'field_defns' => $field_definitions,
            'num_dev_fields' => $num_dev_fields,
            'dev_field_definitions' => $dev_field_definitions,
            'total_size' => $total_size
        ];
        $this->defn_mesgs_all[] = $definition;
        $definition['plan'] = $this->compileDefinition($definition);
        $this->defn_mesgs[$local_mesg_type] = $definition;
    }

    /**
     * $count triples of field definition number, size, and $third (a native field's base type, or
     * a developer field's developer data index).
     */
    private function readFieldDefinitions(int $count, string $third): array
    {
        $bytes = substr($this->file_contents, $this->file_pointer, $count * 3);
        if (strlen($bytes) < $count * 3) {
            throw new FitParseException('phpFITFileAnalysis->readFieldDefinitions(): file is truncated at offset ' . $this->file_pointer . '!');
        }
        $this->file_pointer += $count * 3;

        $definitions = [];
        for ($i = 0; $i < $count; ++$i) {
            $definitions[] = [
                'field_definition_number' => ord($bytes[$i * 3]),
                'size' => ord($bytes[$i * 3 + 1]),
                $third => ord($bytes[$i * 3 + 2]),
            ];
        }

        return $definitions;
    }

    /**
     * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 2.2.pdf
     * 4.2 Data Messages
     *
     * @param int|null $time_offset the 5-bit offset of a compressed timestamp header, else null
     */
    private function readDataMessage(int $local_mesg_type, ?int $time_offset): void
    {
        if (!isset($this->defn_mesgs[$local_mesg_type])) {
            // Without its definition the message's size is unknown, so nothing after it can be read.
            throw new FitParseException('phpFITFileAnalysis->readDataMessage(): data message for undefined local message type ' . $local_mesg_type . ' at offset ' . ($this->file_pointer - 1) . '!');
        }
        $definition = $this->defn_mesgs[$local_mesg_type];
        $start = $this->file_pointer;
        if (strlen($this->file_contents) < $start + $definition['total_size']) {
            throw new FitParseException('phpFITFileAnalysis->readDataMessage(): file is truncated at offset ' . $start . '!');
        }
        $this->file_pointer += $definition['total_size'];

        $global_mesg_num = $definition['global_mesg_num'];
        $architecture = $definition['architecture'];
        $mesg_info = $this->data_mesg_info[$global_mesg_num] ?? null;

        // Native fields, raw. The timestamp (253) is read from every message, known or not: it is
        // the reference a later compressed timestamp header counts from.
        $raw_values = [];
        $base_types = [];
        $timestamp = null;  // FIT epoch
        $plan = $definition['plan'];
        $unpacked = $plan['format'] !== '' ? unpack($plan['format'], $this->file_contents, $start) : [];
        foreach ($plan['fields'] as [$number, $kind, $key, $count, $base_type, $field_pos, $size]) {
            if ($kind === 'n') {
                if ($count === 1) {
                    $values = [$unpacked[$key]];
                } else {
                    $values = [];
                    for ($j = 1; $j <= $count; ++$j) {
                        $values[] = $unpacked[$key . $j];
                    }
                }
                $value = $this->checkValues($values, $base_type);
                // hr (132) arrays line up element by element with the message's other arrays (beats
                // with their times): one with no valid element keeps its slots.
                if ($value === null && $count > 1 && $global_mesg_num === 132) {
                    $value = array_fill(0, $count, null);
                }
            } elseif ($kind === 's') {
                $value = self::cleanString(substr($this->file_contents, $start + $field_pos, $size));
            } else {
                $value = null;
            }
            if ($number === 253 && is_int($value)) {
                $timestamp = $value;
            }
            if (isset($mesg_info['field_defns'][$number])) {
                $raw_values[$number] = $value;
                $base_types[$number] = $base_type;
            }
        }
        $pos = $start + $plan['native_size'];  // developer fields follow the native ones

        // The header's 5-bit offset counts on from the last timestamp of any message, rolling over
        // every 32 s; the result is this message's timestamp and the reference for the next one.
        $has_compressed_timestamp = false;
        if ($time_offset !== null && $this->last_timestamp !== null) {
            $timestamp = $this->last_timestamp - ($this->last_timestamp & 0x1F) + $time_offset;
            if ($time_offset < ($this->last_timestamp & 0x1F)) {
                $timestamp += 32;
            }
            $has_compressed_timestamp = true;
        }
        if ($timestamp !== null) {
            $this->last_timestamp = $timestamp;
        }

        if ($mesg_info === null) {
            return;  // Unknown message: skipped (pointer already advanced past it).
        }
        $mesg_name = $mesg_info['mesg_name'];

        if ($global_mesg_num === 206) {
            $this->storeFieldDescription($mesg_info, $raw_values);
        }

        // Developer fields: description, raw value as decoded, and the value with the description's
        // scale/offset applied (null when invalid).
        $dev_values = [];
        $dev_declarers = [];  // CX-87: each of $dev_values' declarer, see dev_override_streams
        $undescribed = [];  // [index, number, pos, size] of fields with no description yet
        foreach ($definition['dev_field_definitions'] as $field_defn) {
            if (!isset($this->data_mesgs['developer_data'])) {
                $this->data_mesgs['developer_data'] = [];
            }
            $description = $this->dev_field_descriptions[$field_defn['developer_data_index']][$field_defn['field_definition_number']] ?? null;
            if ($description !== null && isset($description['field_name'])) {
                $dev_values[] = $this->developerValues($description, $field_defn['size'], $pos, $architecture);
                $dev_declarers[] = self::declarerKey($field_defn['developer_data_index'], $field_defn['field_definition_number']);
            } elseif ($global_mesg_num === 20) {
                $undescribed[] = [$field_defn['developer_data_index'], $field_defn['field_definition_number'], $pos, $field_defn['size']];
            }
            $pos += $field_defn['size'];
        }

        if ($global_mesg_num === 206) {
            return;
        }

        // A record keys its values by the compressed timestamp itself (storeRecord()); any other
        // message gets it as its timestamp field.
        if ($has_compressed_timestamp && $global_mesg_num !== 20 && isset($mesg_info['field_defns'][253])) {
            $raw_values[253] = $timestamp;
        }
        $named = $this->namedValues($global_mesg_num, $mesg_info, $raw_values, $base_types);

        if ($global_mesg_num === 20) {
            $record_timestamp = $this->storeRecord($named, $raw_values, $timestamp, $has_compressed_timestamp, $dev_values);
            // A record's developer field written before its field_description is decoded when the
            // description arrives (storeFieldDescription()), so an override still covers it.
            if ($record_timestamp !== null) {
                foreach ($dev_values as $i => [$description, , $value]) {
                    $this->collectOverrideValue($description, $dev_declarers[$i], $value, $record_timestamp);
                }
                foreach ($undescribed as [$index, $number, $field_pos, $size]) {
                    $this->undescribed_dev_values[$index][$number][] = [$field_pos, $size, $architecture, $record_timestamp];
                }
            }
            return;
        }

        $padded = !in_array($mesg_name, self::UNPADDED_MESSAGES, true);
        if ($padded) {
            $counts_before = array_map('count', $this->data_mesgs[$mesg_name] ?? []);
            if ($mesg_name === 'session') {
                foreach (self::ALWAYS_PRESENT_SESSION_FIELDS as $field_name) {
                    $this->data_mesgs['session'][$field_name] ??= [];
                }
            }
        }

        foreach ($named as $name => [$value, $field_info]) {
            // hr (132) arrays line up element by element with the message's other arrays, so an
            // invalid element is kept as null, never dropped.
            if (!$this->isValid($value) && !($global_mesg_num === 132 && is_array($value))) {
                continue;
            }
            // Only the timestamp field (253) here. Any other date_time field -- course_point's field
            // 1 is named timestamp too -- is shifted once, by its type, in fixData().
            if (($field_info['num'] ?? null) === 253 && !$this->garmin_timestamps) {
                $value += self::FIT_UNIX_TS_DIFF;
            }
            $value = $this->scale($value, $field_info);
            $this->data_mesgs[$mesg_name][$name][] = $value;
        }

        // hr (132): each message's own beats and times, kept together for processHrMessages(). A
        // field the message carries is a list (one invalid value is [null]); null when it does not.
        if ($global_mesg_num === 132) {
            $list = fn($number) => array_key_exists($number, $raw_values)
                ? (is_array($raw_values[$number]) ? $raw_values[$number] : [$raw_values[$number]])
                : null;
            $this->hr_messages[] = [
                'timestamp' => isset($raw_values[253]) ? $raw_values[253] + ($this->garmin_timestamps ? 0 : self::FIT_UNIX_TS_DIFF) : null,
                'event_timestamp' => $list(9),
                'event_timestamp_12' => $list(10),
                'filtered_bpm' => $list(6) ?? [],
            ];
        }

        // A padded message's developer values are stored at its index (padDeveloperValues() fills
        // in the messages without one), so by_mesg[$mesg_name][i] is message i's value.
        $index = $padded ? ($this->message_counts[$mesg_name] ?? 0) : null;
        foreach ($dev_values as [$description, $raw, $value]) {
            $this->storeDeveloperValue($description, $raw, $value, $mesg_name, null, $index);
        }

        if ($padded) {
            $this->message_counts[$mesg_name] = $index + 1;
            $this->padMessageFields($mesg_name, $index + 1, $counts_before);
        }
    }

    /**
     * After reading: each padded message's developer values as a list with one entry per message,
     * null for a message without the field.
     */
    private function padDeveloperValues(): void
    {
        foreach ($this->data_mesgs['developer_data'] ?? [] as $name => $developer_field) {
            foreach ($developer_field['by_mesg'] ?? [] as $mesg_name => $values) {
                $count = $this->message_counts[$mesg_name] ?? null;
                if ($mesg_name === 'record' || $count === null) {
                    continue;
                }
                $values += array_fill(0, $count, null);
                ksort($values);
                $this->data_mesgs['developer_data'][$name]['by_mesg'][$mesg_name] = $values;
            }
        }
    }

    /**
     * A record (20) message: every field is keyed by the record's timestamp. A record without one
     * (and no compressed timestamp) goes one second after the latest record so far. Returns the
     * timestamp it is stored at, or null when there was nothing to store.
     */
    private function storeRecord(array $named, array $raw_values, ?int $timestamp, bool $has_compressed_timestamp, array $dev_values): ?int
    {
        $values = [];
        foreach ($named as $name => [$value, $field_info]) {
            if ($name !== 'timestamp' && $this->isValid($value)) {
                $values[$name] = $this->scale($value, $field_info);
            }
        }
        // Not even a timestamp: nothing to store (a compressed timestamp alone does not count).
        if ($values === [] && !(isset($raw_values[253]) && $this->isValid($raw_values[253]))) {
            return null;
        }

        $shift = $this->garmin_timestamps ? 0 : self::FIT_UNIX_TS_DIFF;
        // APP-1554: was max($this->data_mesgs['record']['timestamp']) + 1 -- an O(n) scan per record,
        // so O(n^2) per parse. The running maximum is equivalent because the array is appended at
        // one place only, just below.
        $record_timestamp = $this->max_record_timestamp !== null ? $this->max_record_timestamp + 1 : 0;
        if ($timestamp !== null && ($has_compressed_timestamp || $timestamp + $shift > 0)) {
            $record_timestamp = $timestamp + $shift;
        }

        $this->data_mesgs['record']['timestamp'][] = $record_timestamp;
        // Timestamps are not guaranteed monotonic (see OutOfOrderTimestampsError), so track the
        // maximum rather than assuming the last append is the largest.
        if ($this->max_record_timestamp === null || $record_timestamp > $this->max_record_timestamp) {
            $this->max_record_timestamp = $record_timestamp;
        }

        foreach ($values as $key => $value) {
            $this->data_mesgs['record'][$key][$record_timestamp] = $value;
        }

        foreach ($dev_values as [$description, $raw, $value]) {
            $this->storeDeveloperValue($description, $raw, $value, 'record', $record_timestamp);
        }

        return $record_timestamp;
    }

    /**
     * A message's values by name, each [raw value, field info]: its native fields, then -- as the
     * FIT SDK decoder does -- each subfield whose reference field holds a value its map names
     * ('expand_subfields', default on), and the fields a field's components expand into
     * ('expand_components': default false, none; 'enhanced' only into enhanced_* fields such as
     * speed into enhanced_speed; true for all). A component never replaces a field the
     * message carries itself; its value is already scaled (info scale 1, offset 0).
     */
    private function namedValues(int $global_mesg_num, array $mesg_info, array $raw_values, array $base_types): array
    {
        $fields = $mesg_info['field_defns'];
        $named = [];
        foreach ($raw_values as $number => $value) {
            $named[$fields[$number]['field_name']] = [$value, $fields[$number]];
        }

        $to_expand = [];
        $mode = $this->options['expand_components'];
        foreach ($raw_values as $number => $value) {
            $info = $fields[$number];
            if ($value === null || (!isset($info['subfields']) && !isset($info['components']) && !isset($info['accumulate_from']))
                || (is_array($value) && !$this->isValid($value))) {
                continue;
            }
            // Accumulators only move when every component is expanded (no enhanced_* target accumulates).
            if ($mode === true && isset($info['accumulate_from']) && is_int($value)) {
                // Carried outright: re-seed its accumulator, in the component's units.
                [$scale, $offset] = $info['accumulate_from'];
                $seed = (int) round(($value / $info['scale'] - $info['offset'] + $offset) * $scale);
                $this->accumulators[$global_mesg_num][$number] = [$seed, $seed];
            }
            if (isset($info['subfields']) && $this->options['expand_subfields']) {
                foreach ($info['subfields'] as $subfield) {
                    foreach ($subfield['map'] as [$reference, $reference_value]) {
                        if (($raw_values[$reference] ?? null) === $reference_value) {
                            $named[$subfield['field_name']] ??= [$value, $subfield];
                            if (isset($subfield['components'])) {
                                $to_expand[] = [$value, $subfield, $base_types[$number]];
                            }
                            break;
                        }
                    }
                }
            }
            if (isset($info['components'])) {
                $to_expand[] = [$value, $info, $base_types[$number]];
            }
        }

        if ($mode === false || $to_expand === []) {
            return $named;
        }
        $expanded = [];
        while ([$value, $info, $base_type] = array_shift($to_expand)) {
            // A single integer is read by shifting (speed into enhanced_speed is one 16-bit read on
            // every record); an array through a bit stream.
            $element_bits = (self::BASE_TYPES[$base_type]['bytes'] ?? 1) * 8;
            $bits = is_int($value) && $element_bits < 64 ? null : new FitBitStream($value, $element_bits);
            $shift = 0;
            foreach ($info['components'] as [$target, $width, $scale, $offset]) {
                if ($bits === null) {
                    if ($element_bits - $shift < $width) {
                        break;
                    }
                    $component = ($value >> $shift) & ((1 << $width) - 1);
                    $shift += $width;
                } elseif ($bits->available() < $width) {
                    break;
                } else {
                    $component = $bits->read($width);
                }
                $target_info = $fields[$target] ?? null;
                $target_name = $target_info['field_name'] ?? null;
                if ($target_info === null || ($mode !== true && strpos($target_name, 'enhanced_') !== 0)
                    || (isset($named[$target_name]) && $this->isValid($named[$target_name][0]) && !isset($expanded[$target_name]))) {
                    continue;
                }
                if (!empty($target_info['accumulated'])) {
                    $component = $this->accumulate($global_mesg_num, $target, $component, $width);
                }
                $expanded[$target_info['field_name']][] = $component / $scale - $offset;
                $named[$target_info['field_name']] = [null, ['field_name' => $target_info['field_name'], 'scale' => 1, 'offset' => 0]];
                if (isset($target_info['components'])) {
                    $to_expand[] = [(int) round(($component / $scale - $offset + $target_info['offset']) * $target_info['scale']), $target_info, $target_info['base_type'] ?? 0x86];
                }
            }
        }
        foreach ($expanded as $name => $values) {
            $named[$name][0] = count($values) === 1 ? $values[0] : $values;
        }

        return $named;
    }

    /**
     * FIT SDK accumulator: a component carrying the low $bits of a running total (cycles, distance,
     * power, ...) adds what it moved on by, modulo 2^$bits, to that field's total so far.
     */
    private function accumulate(int $global_mesg_num, int $field_num, int $value, int $bits): int
    {
        [$total, $last] = $this->accumulators[$global_mesg_num][$field_num] ?? [$value, $value];
        $total += ($value - $last) & ((1 << $bits) - 1);
        $this->accumulators[$global_mesg_num][$field_num] = [$total, $value];

        return $total;
    }

    /**
     * A field_description (206) message describes a developer field for later messages. It is kept
     * aside, not stored in data_mesgs.
     */
    private function storeFieldDescription(array $mesg_info, array $raw_values): void
    {
        $description = [];
        foreach ($raw_values as $number => $value) {
            if ($this->isValid($value)) {
                $description[$mesg_info['field_defns'][$number]['field_name']] = $value;
            }
        }
        if (!isset($description['developer_data_index'], $description['field_definition_number'])) {
            return;
        }
        $developer_data_index = $description['developer_data_index'];
        $field_definition_number = $description['field_definition_number'];
        unset($description['developer_data_index'], $description['field_definition_number']);
        if (isset($description['field_name'])) {
            $description['field_name'] = strtolower($description['field_name']);
        }
        if (isset($description['units'])) {
            $description['units'] = strtolower($description['units']);
        }
        $this->dev_field_descriptions[$developer_data_index][$field_definition_number] = $description;

        // Records that carried this field before it was described (see readDataMessage()).
        if (isset($description['field_name'])) {
            foreach ($this->undescribed_dev_values[$developer_data_index][$field_definition_number] ?? [] as [$pos, $size, $architecture, $record_timestamp]) {
                [, $raw, $value] = $this->developerValues($description, $size, $pos, $architecture);
                $this->storeDeveloperValue($description, $raw, $value, 'record', $record_timestamp);
                $this->collectOverrideValue($description, self::declarerKey($developer_data_index, $field_definition_number), $value, $record_timestamp);
            }
        }
        unset($this->undescribed_dev_values[$developer_data_index][$field_definition_number]);
    }

    /**
     * A developer field's [description, raw value as decoded, value with the description's
     * scale/offset applied (null when invalid)]. The bytes are unpacked once; checkValues() makes
     * the raw value (invalid kept) and the checked one from the same list.
     *
     * A value is an int or float, a string, an array when the field holds several values, or null.
     * Signed types are sign-extended. In the checked value invalid values are null -- per element
     * in an array, and the whole field when every element is invalid. Null too for an unknown base
     * type or a size the type does not divide. Native fields go through compileDefinition().
     */
    private function developerValues(array $description, int $size, int $pos, int $architecture): array
    {
        $base_type = $description['fit_base_type_id'] ?? null;
        if ($base_type === self::BASE_TYPE_STRING) {
            $string = self::cleanString(substr($this->file_contents, $pos, $size));

            return [$description, $string, $this->developerValueOrNull($description, $string)];
        }
        $type = self::BASE_TYPES[$base_type] ?? null;
        if ($type === null || $size === 0 || $size % $type['bytes'] !== 0) {
            return [$description, null, null];
        }
        $count = intdiv($size, $type['bytes']);
        $values = array_values(unpack(($architecture === 1 ? $type['be'] : $type['le']) . $count, $this->file_contents, $pos));

        return [
            $description,
            $this->checkValues($values, $base_type, true),
            $this->developerValueOrNull($description, $this->checkValues($values, $base_type)),
        ];
    }

    /**
     * developer_data[name]: 'units', 'data' (every raw value in file order, kept as it always was:
     * one flat list across records, laps and every other message, so its index is no message's
     * index -- use by_mesg for that), and 'by_mesg' (per message name, the scaled value or null when
     * invalid; keyed by timestamp for records, by message index for padded messages, in order
     * otherwise). Two fields of one name in one padded message (two apps' "power"): the first
     * valid value is kept, so the message still has one entry.
     */
    private function storeDeveloperValue(array $description, mixed $raw, mixed $value, string $mesg_name, ?int $record_timestamp, ?int $mesg_index = null): void
    {
        $name = $description['field_name'];
        $this->data_mesgs['developer_data'][$name]['units'] = $description['units'] ?? null;
        $this->data_mesgs['developer_data'][$name]['data'][] = $raw;
        if ($record_timestamp !== null) {
            $this->data_mesgs['developer_data'][$name]['by_mesg'][$mesg_name][$record_timestamp] = $value;
        } elseif ($mesg_index !== null) {
            if (!isset($this->data_mesgs['developer_data'][$name]['by_mesg'][$mesg_name][$mesg_index])) {
                $this->data_mesgs['developer_data'][$name]['by_mesg'][$mesg_name][$mesg_index] = $value;
            }
        } else {
            $this->data_mesgs['developer_data'][$name]['by_mesg'][$mesg_name][] = $value;
        }
    }

    /**
     * CX-87: a record's valid value for the native field its declaration replaces, kept per
     * declarer so applyDeveloperOverrides() can take one declarer for a whole session. Two records
     * on one timestamp: the later valid value wins, as the later value does for native record
     * fields (an invalid one is no value here, where by_mesg stores it as null).
     */
    /** CX-87: one developer field, as a sortable key: developer data index, then field number. */
    private static function declarerKey(int $developer_data_index, int $field_definition_number): int
    {
        return $developer_data_index * 256 + $field_definition_number;
    }

    private function collectOverrideValue(array $description, int $declarer, mixed $value, int $record_timestamp): void
    {
        if (!is_int($value) && !is_float($value)) {
            return;
        }
        $native = $this->nativeRecordFieldOverriddenBy($description);
        if ($native !== null) {
            $this->dev_override_streams[$native][$declarer][$record_timestamp] = $value;
        }
    }

    /**
     * Unpacked values of one field (a list) made into the field's value; see developerValues(). A
     * float's invalid value (all ones) unpacks to NaN, and any NaN is no value.
     */
    private function checkValues(array $values, int $base_type, bool $keep_invalid = false): mixed
    {
        $type = self::BASE_TYPES[$base_type];
        $count = count($values);

        if ($base_type === self::BASE_TYPE_BYTE) {
            if (!$keep_invalid && count(array_keys($values, 0xFF, true)) === $count) {
                return null;
            }

            return $count === 1 ? $values[0] : $values;
        }

        $any_valid = false;
        $sign_bits = $type['sign_bits'];
        foreach ($values as &$value) {
            if (($type['float'] ?? false) ? is_nan($value) : $value === $type['invalid']) {
                if (!$keep_invalid) {
                    $value = null;
                    continue;
                }
            } elseif ($sign_bits && $value >= 1 << ($sign_bits - 1)) {
                $value -= 1 << $sign_bits;
            } elseif (($base_type === self::BASE_TYPE_UINT64 || $base_type === self::BASE_TYPE_UINT64Z) && $value < 0) {
                $value += 18446744073709551616.0;  // beyond PHP_INT_MAX: as float
            }
            $any_valid = true;
        }
        unset($value);

        if (!$any_valid) {
            return null;
        }

        return $count === 1 ? $values[0] : $values;
    }

    /**
     * A definition's native fields as one unpack() call, compiled when the definition is read: each
     * field this library decodes (in the profile, or 253, read from every message) is [field number,
     * kind ('n' numbers, 's' a string, null not decodable), unpack() key, count, base type, offset
     * in the message, size].
     */
    private function compileDefinition(array $definition): array
    {
        $mesg_info = $this->data_mesg_info[$definition['global_mesg_num']] ?? null;
        $format = [];
        $fields = [];
        $pos = 0;
        foreach ($definition['field_defns'] as $i => $field_defn) {
            $number = $field_defn['field_definition_number'];
            $size = $field_defn['size'];
            if ($number === 253 || isset($mesg_info['field_defns'][$number])) {
                $base_type = $field_defn['base_type'];
                $type = self::BASE_TYPES[$base_type] ?? null;
                if ($base_type === self::BASE_TYPE_STRING) {
                    $fields[] = [$number, 's', null, 0, $base_type, $pos, $size];
                } elseif ($type !== null && $size > 0 && $size % $type['bytes'] === 0) {
                    $count = intdiv($size, $type['bytes']);
                    $key = 'f' . $i . '_';
                    $format[] = '@' . $pos . '/' . ($definition['architecture'] === 1 ? $type['be'] : $type['le']) . $count . $key;
                    $fields[] = [$number, 'n', $key, $count, $base_type, $pos, $size];
                } else {
                    $fields[] = [$number, null, null, 0, $base_type, $pos, $size];
                }
            }
            $pos += $size;
        }

        return ['format' => implode('/', $format), 'fields' => $fields, 'native_size' => $pos];
    }

    /**
     * A FIT string is null-terminated and UTF-8. The text up to the first null, with any invalid
     * UTF-8 replaced; no HTML escaping, which is the caller's business. (It went through the
     * deprecated FILTER_SANITIZE_STRING, which stripped tags and made "Steve's" "Steve&#39;s".)
     */
    private static function cleanString(string $data): string
    {
        $end = strpos($data, "\0");
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }

        return mb_scrub($data, 'UTF-8');
    }

    /** A decoded value that is not null and, for an array, has at least one non-null element. */
    private function isValid(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $element) {
                if ($element !== null) {
                    return true;
                }
            }

            return false;
        }

        return $value !== null;
    }

    /**
     * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 1.7.pdf
     * 4.4 Scale/Offset
     * When specified, the binary quantity is divided by the scale factor and then the offset is
     * subtracted, yielding a floating point quantity. Strings and nulls pass through.
     */
    private function scale(mixed $value, array $field_info): mixed
    {
        if (is_array($value)) {
            foreach ($value as &$element) {
                $element = $this->scale($element, $field_info);
            }

            return $value;
        }
        if (!is_int($value) && !is_float($value)) {
            return $value;
        }

        return $value / $field_info['scale'] - $field_info['offset'];
    }

    /**
     * A developer value (as checkValues() returns it, invalid already null) with the
     * description's own scale/offset applied, or null when it is not a number (a float field can
     * carry NaN other than the invalid pattern). Arrays element by element; strings pass through.
     * A scale of 0 is not a usable scale and is read as 1 rather than dividing by it.
     */
    private function developerValueOrNull(array $description, mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as &$element) {
                $element = $this->developerValueOrNull($description, $element);
            }

            return $value;
        }
        if (!is_int($value) && !is_float($value)) {
            return $value;
        }
        if (is_float($value) && !is_finite($value)) {
            return null;
        }
        $scale = $description['scale'] ?? 1;

        return $value / ($scale ?: 1) - ($description['offset'] ?? 0);
    }

    /**
     * A developer field declaring a native field (native_field_num) is copied into the record
     * stream under its own name when the record carries no field of that name, as this library has
     * always done. The copy is developer_data's flat 'data' list, raw. Stryd's cadence on a watch
     * without native cadence arrives this way, and athletica-app's FitFileParser::getMetricArray()
     * reads such lists. A declarer the CX-87 override took for some session is not copied: its
     * values are already in the native stream. One it never took is copied as before.
     */
    private function copyDeveloperDeclarations(): void
    {
        foreach ($this->dev_field_descriptions as $developer_data_index => $fields) {
            foreach ($fields as $field_definition_number => $description) {
                if (!isset($description['native_field_num'], $description['field_name'])) {
                    continue;
                }
                if (isset($this->applied_overrides[$this->nativeRecordFieldOverriddenBy($description) ?? ''][self::declarerKey($developer_data_index, $field_definition_number)])) {
                    continue;
                }
                if (isset($this->data_mesgs['record'][$description['field_name']])) {
                    continue;
                }
                $this->data_mesgs['record'][$description['field_name']] = $this->data_mesgs['developer_data'][$description['field_name']]['data'] ?? [];
            }
        }
    }

    /**
     * CX-87: the native record field a developer field replaces, or null when it declares none.
     * A declaration names a message number and a field number; only record (20) overrides are
     * honoured here, and only for power (field 7). Stryd also declares cadence, ground time and
     * vertical oscillation, but its ground time and vertical oscillation are not the watch's
     * quantity in the watch's units (603 ms against 320 ms, 3.4 cm with no scale against 63 mm on
     * the reproducer), so promoting them would put a foreign value under the native name. They
     * stay in developer_data.
     */
    private function nativeRecordFieldOverriddenBy(array $description): ?string
    {
        if (!$this->options['overwrite_with_dev_data']) {
            return null;
        }
        if (($description['native_mesg_num'] ?? null) !== 20 || !in_array($description['native_field_num'] ?? null, self::OVERRIDABLE_RECORD_FIELDS, true)) {
            return null;
        }

        return $this->data_mesg_info[20]['field_defns'][$description['native_field_num']]['field_name'] ?? null;
    }

    /**
     * CX-87: replace a native record field with the developer values that declare it, one session
     * at a time.
     *
     * A session is overridden only when a declarer carries at least one value above 0 inside it:
     * some apps declare the override and write 0 on every record (185 of 782 dual files in
     * production), which would otherwise zero the session's power. One declarer supplies the whole
     * session, so two apps' power never mix: the one with the most readings above 0 in it (an app
     * writing 0 with a stray reading does not beat Stryd recording throughout), ties going to the
     * lower developer data index, then field number. Where the chosen declarer has no value, the
     * record has no power, even if another declarer has one.
     * Within such a session every record carries that declarer's value or nothing -- never the
     * watch's own value, because the two are not on one scale. That includes, deliberately, the rest
     * of a session after a pod drops out: a power-duration profile must come from one source, and
     * missing samples leave a best unchanged where foreign ones would lift it. Outside an overridden
     * session the native stream is untouched: a brick whose bike leg has a real power meter and no
     * Stryd keeps that leg's power, and a file where Stryd never wrote a valid value (field
     * installed, no pod paired) stays a watch-only file, stream and summary alike. A multi-session
     * file whose sessions cannot be told apart is not overridden; copyDeveloperDeclarations() then
     * treats it as it always did.
     *
     * The session's and its laps' avg_/max_ (and normalized_power for power) are then re-derived
     * from the stream that survives, rounded to whole units as the device writes them.
     */
    private function applyDeveloperOverrides(): void
    {
        if ($this->dev_override_streams === [] || empty($this->data_mesgs['record']['timestamp'])) {
            return;
        }

        $sessions = $this->sessionWindows();
        if ($sessions === null) {
            return;
        }
        $timestamps = array_values(array_unique($this->data_mesgs['record']['timestamp']));
        sort($timestamps);

        foreach ($this->dev_override_streams as $field => $declarers) {
            ksort($declarers);
            $overridden = [];
            foreach ($sessions as $session_index => [$from, $to]) {
                $in_window = $this->keysInWindow($timestamps, $from, $to);
                $dev_stream = null;
                $chosen = null;
                $most = 0;
                foreach ($declarers as $declarer => $stream) {
                    $readings = 0;
                    foreach ($in_window as $ts) {
                        if (($stream[$ts] ?? 0) > 0) {
                            ++$readings;
                        }
                    }
                    if ($readings > $most) {
                        [$dev_stream, $chosen, $most] = [$stream, $declarer, $readings];
                    }
                }
                if ($dev_stream === null) {
                    continue;
                }
                $this->applied_overrides[$field][$chosen] = true;

                foreach ($in_window as $ts) {
                    if (isset($dev_stream[$ts])) {
                        $this->data_mesgs['record'][$field][$ts] = $dev_stream[$ts];
                    } else {
                        unset($this->data_mesgs['record'][$field][$ts]);
                    }
                }
                $overridden[$session_index] = [$from, $to];
            }
            if ($overridden === []) {
                continue;
            }

            // A timestamp the native stream had no value for was appended at the end; restore time order.
            ksort($this->data_mesgs['record'][$field]);
            $keys = array_keys($this->data_mesgs['record'][$field]);

            foreach ($overridden as $session_index => [$from, $to]) {
                if ($session_index >= 0) {
                    $this->summariseMessage('session', $session_index, $field, $this->keysInWindow($keys, $from, $to));
                }
                foreach ($this->lapWindows($session_index, $from, $to) as $lap_index => $window) {
                    $this->summariseMessage('lap', $lap_index, $field, $window === null ? [] : $this->keysInWindow($keys, $window[0], $window[1]));
                }
            }
        }
    }

    /**
     * CX-87: [from, to] in record timestamps for each session message, by message index. One session
     * (or none, as -1) is the whole file. Several run from each start_time until the next session's;
     * the first is open at the front and the last at the back, so no record falls between them. The
     * message's own timestamp is not used as its end: the Stryd reproducer writes it equal to
     * start_time. Null when several sessions do not all carry a start_time in order.
     */
    private function sessionWindows(): ?array
    {
        $count = $this->message_counts['session'] ?? 0;
        if ($count <= 1) {
            return [($count === 1 ? 0 : -1) => [PHP_INT_MIN, PHP_INT_MAX]];
        }

        $starts = [];
        for ($i = 0; $i < $count; ++$i) {
            $start = $this->data_mesgs['session']['start_time'][$i] ?? null;
            if ($start === null || ($i > 0 && $start + $this->timestampShift() <= $starts[$i - 1])) {
                return null;
            }
            $starts[$i] = $start + $this->timestampShift();
        }

        $windows = [];
        foreach ($starts as $i => $start) {
            $windows[$i] = [$i === 0 ? PHP_INT_MIN : $start, isset($starts[$i + 1]) ? $starts[$i + 1] - 1 : PHP_INT_MAX];
        }

        return $windows;
    }

    /**
     * CX-87: the laps of one session, each with its [from, to] clamped to the session, or null for a
     * lap with no start_time (its figures are cleared rather than left describing the discarded
     * stream). A session's laps are the ones its first_lap_index / num_laps name when it writes them,
     * which needs no timestamps; otherwise the laps whose start_time falls inside the session. A lap
     * runs until the next lap of the session starts.
     */
    private function lapWindows(int $session_index, int $from, int $to): array
    {
        $starts = $this->data_mesgs['lap']['start_time'] ?? [];
        $first = $session_index >= 0 ? ($this->data_mesgs['session']['first_lap_index'][$session_index] ?? null) : null;
        $count = $session_index >= 0 ? ($this->data_mesgs['session']['num_laps'][$session_index] ?? null) : null;

        if ($first !== null && $count !== null) {
            $laps = [];
            for ($i = $first; $i < $first + $count && array_key_exists($i, $starts); ++$i) {
                $laps[$i] = $starts[$i] === null ? null : $starts[$i] + $this->timestampShift();
            }
        } elseif (($this->message_counts['session'] ?? 0) <= 1) {
            $laps = array_map(fn($start) => $start === null ? null : $start + $this->timestampShift(), $starts);
        } else {
            // A lap with no start_time goes with the timed lap before it.
            $laps = [];
            $in_session = false;
            foreach ($starts as $i => $start) {
                if ($start !== null) {
                    $in_session = $start + $this->timestampShift() >= $from && $start + $this->timestampShift() <= $to;
                }
                if ($in_session) {
                    $laps[$i] = $start === null ? null : $start + $this->timestampShift();
                }
            }
        }

        $timed = array_filter($laps, fn($start) => $start !== null);
        $indexes = array_keys($timed);
        $windows = [];
        foreach ($laps as $i => $start) {
            if ($start === null) {
                $windows[$i] = null;
                continue;
            }
            $position = array_search($i, $indexes, true);
            $next = $indexes[$position + 1] ?? null;
            $windows[$i] = [
                $position === 0 ? $from : max($from, $start),
                min($to, $next === null ? PHP_INT_MAX : $timed[$next] - 1),
            ];
        }

        return $windows;
    }

    /** CX-87: start_time is still raw (FIT epoch) here -- fixData() shifts it later -- while record timestamps are Unix. */
    private function timestampShift(): int
    {
        return $this->garmin_timestamps ? 0 : self::FIT_UNIX_TS_DIFF;
    }

    /** CX-87: the values of a sorted list that fall in [from, to], found by binary search. */
    private function keysInWindow(array $sorted, int $from, int $to): array
    {
        $lo = 0;
        $hi = count($sorted);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($sorted[$mid] < $from) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        $keys = [];
        for ($i = $lo, $n = count($sorted); $i < $n && $sorted[$i] <= $to; ++$i) {
            $keys[] = $sorted[$i];
        }

        return $keys;
    }

    /**
     * CX-87: overwrite one session's or lap's avg_/max_ (and normalized_power for power) of a field
     * with figures from the record values at $timestamps, rounded to whole units as the device
     * writes them. Power-derived figures this library does not re-derive (training stress score,
     * intensity factor, total work) are set to null, since they describe the discarded stream; so is
     * everything when no value survives. Both messages are padded one entry per message (APP-1788),
     * so $index is the message's own entry; a field the file never wrote is not added.
     */
    private function summariseMessage(string $mesg_name, int $index, string $field, array $timestamps): void
    {
        $values = [];
        foreach ($timestamps as $ts) {
            if (isset($this->data_mesgs['record'][$field][$ts])) {
                $values[] = $this->data_mesgs['record'][$field][$ts];
            }
        }

        $summary = [
            'avg_' . $field => $values ? (int) round(array_sum($values) / count($values)) : null,
            'max_' . $field => $values ? (int) round(max($values)) : null,
        ];
        if ($field === 'power') {
            // Too short for one 30-sample rolling window: the mean is the closest figure.
            $np = $values ? $this->normalisedPower($values) : null;
            $summary['normalized_power'] = $np === null ? $summary['avg_power'] : (int) round($np);
            $summary['training_stress_score'] = null;
            $summary['intensity_factor'] = null;
            $summary['total_work'] = null;
        }

        foreach ($summary as $key => $value) {
            if (isset($this->data_mesgs[$mesg_name][$key]) && array_key_exists($index, $this->data_mesgs[$mesg_name][$key])) {
                $this->data_mesgs[$mesg_name][$key][$index] = $value;
            }
        }
    }

    /**
     * Normalised Power over a power stream: 30-sample rolling mean, fourth power, mean, fourth root.
     * Samples, not seconds: powerMetrics() runs on the per-second stream that fixData() builds.
     * Null when the stream is too short for a single rolling window.
     */
    private function normalisedPower(array $power): ?float
    {
        $np_values = ($this->php_trader_ext_loaded) ? (trader_sma($power, 30) ?: []) : $this->sma($power, 30);

        $sum = 0.0;
        $count = 0;
        foreach ($np_values as $value) {
            $sum += pow($value, 4);
            ++$count;
        }

        return $count === 0 ? null : pow($sum / $count, 1 / 4);
    }

    /**
     * APP-1788: keep every field of a PADDED_MESSAGES message one entry per message, so index $i
     * is message $i in all of them.
     *
     * readDataMessage() appends a field only when that message carries a valid value for it, so a
     * field that is invalid (an HR strap dropping out) or absent (power only on a triathlon's bike
     * leg) would otherwise leave its list shorter than the message count, with every later value
     * shifted onto the wrong lap or session. Called after each such message: a field this message
     * did not write gets null, and a field first seen on this message gets null for every earlier one.
     *
     * @param string $mesg_name     e.g. 'lap'
     * @param int    $mesg_count    messages of that name read so far, including this one
     * @param array  $counts_before each field's length before this message was read
     */
    private function padMessageFields(string $mesg_name, int $mesg_count, array $counts_before): void
    {
        foreach ($this->data_mesgs[$mesg_name] ?? [] as $field => $values) {
            // Written in place, so a field this message already filled is not copied (see APP-1554).
            $before = $counts_before[$field] ?? 0;
            if ($before < $mesg_count - 1) {
                $this->data_mesgs[$mesg_name][$field] = array_merge(array_fill(0, $mesg_count - 1 - $before, null), $values);
            }
            if (count($this->data_mesgs[$mesg_name][$field]) < $mesg_count) {
                $this->data_mesgs[$mesg_name][$field][] = null;
            }
        }
    }

    /**
     * If the user has requested for the data to be fixed, identify the missing keys for that data.
     */
    private function fixData(array $options): void
    {
        // By default self::FIT_UNIX_TS_DIFF will be added to timestamps, which have field type of date_time (or local_date_time).
        // Timestamp fields (field number == 253) converted after being unpacked in readDataMessage(); the others,
        // every field the profile types date_time or local_date_time, here.
        if (!$this->garmin_timestamps) {
            foreach ($this->data_mesg_info as $mesg_info) {
                $mesg_name = $mesg_info['mesg_name'];
                if (!isset($this->data_mesgs[$mesg_name])) {
                    continue;
                }
                foreach ($mesg_info['field_defns'] as $number => $field_info) {
                    if ($number === 253 || !in_array($field_info['type'], ['date_time', 'local_date_time'], true)
                        || !isset($this->data_mesgs[$mesg_name][$field_info['field_name']])) {
                        continue;
                    }
                    $values = &$this->data_mesgs[$mesg_name][$field_info['field_name']];
                    if (is_array($values)) {
                        foreach ($values as &$element) {
                            // APP-1788: padded fields hold null for a message without a valid value.
                            if ($element !== null) {
                                $element += self::FIT_UNIX_TS_DIFF;
                            }
                        }
                        unset($element);
                    } else {
                        $values += self::FIT_UNIX_TS_DIFF;
                    }
                    unset($values);
                }
            }
        }


        // Remove duplicate timestamps and store original before interpolating
        if (isset($this->data_mesgs['record']['timestamp']) && is_array($this->data_mesgs['record']['timestamp'])) {
            $this->data_mesgs['record']['timestamp'] = array_unique($this->data_mesgs['record']['timestamp']);
            $this->data_mesgs['record']['timestamp_original'] = $this->data_mesgs['record']['timestamp'];
        }

        // 'data_every_second' => 'false' is off, as false is.
        $every_second = !empty($options['data_every_second']) && !(is_string($options['data_every_second']) && strtolower($options['data_every_second']) === 'false');

        // Return if no option set
        if (empty($options['fix_data']) && !$every_second) {
            return;
        }

        // If user has not specified the data to be fixed, assume all
        if ($every_second && empty($options['fix_data'])) {
            $options['fix_data'] = ['all'];
        }

        // Check if valid option(s) provided
        array_walk($options['fix_data'], function (&$value) {
            $value = strtolower($value);
        });  // Make all lower-case.
        if (count(array_intersect(['all', 'cadence', 'distance', 'heart_rate', 'lat_lon', 'speed', 'power', 'altitude', 'enhanced_speed', 'enhanced_altitude'], $options['fix_data'])) === 0) {
            throw new \Exception('phpFITFileAnalysis->fixData(): option not valid!');
        }

        // No records, or one (its fields already unwrapped to values by oneElementArrays()): nothing
        // to fill in. Checked after the options, which are invalid whatever the file.
        if (!is_array($this->data_mesgs['record']['timestamp'] ?? null)) {
            return;
        }

        // If $options['data_every_second'], then create timestamp array for every second from min to max
        if ($every_second) {
            $min_ts = min($this->data_mesgs['record']['timestamp']);
            $max_ts = max($this->data_mesgs['record']['timestamp']);
            unset($this->data_mesgs['record']['timestamp']);
            for ($i=$min_ts; $i<=$max_ts; ++$i) {
                $this->data_mesgs['record']['timestamp'][] = $i;
            }
        }

        $bCadence = $bDistance = $bHeartRate = $bLatitudeLongitude = $bSpeed = $bPower = $bAltitude = $bEnhancedSpeed = $bEnhancedAltitude = false;
        if (in_array('all', $options['fix_data'])) {
            $bCadence = isset($this->data_mesgs['record']['cadence']);
            $bDistance = isset($this->data_mesgs['record']['distance']);
            $bHeartRate = isset($this->data_mesgs['record']['heart_rate']);
            $bLatitudeLongitude = isset($this->data_mesgs['record']['position_lat']) && isset($this->data_mesgs['record']['position_long']);
            $bSpeed = isset($this->data_mesgs['record']['speed']);
            $bPower = isset($this->data_mesgs['record']['power']);
            $bAltitude = isset($this->data_mesgs['record']['altitude']);
            $bEnhancedSpeed = isset($this->data_mesgs['record']['enhanced_speed']);
            $bEnhancedAltitude = isset($this->data_mesgs['record']['enhanced_altitude']);
        } else {
            if (isset($this->data_mesgs['record']['timestamp'])) {
                $count_timestamp = count($this->data_mesgs['record']['timestamp']);  // No point try to insert missing values if we know there aren't any.
                if (isset($this->data_mesgs['record']['cadence']) && is_array($this->data_mesgs['record']['cadence'])) {
                    $bCadence = (count($this->data_mesgs['record']['cadence']) === $count_timestamp) ? false : in_array('cadence', $options['fix_data']);
                }
                if (isset($this->data_mesgs['record']['distance']) && is_array($this->data_mesgs['record']['distance'])) {
                    $bDistance = (count($this->data_mesgs['record']['distance']) === $count_timestamp) ? false : in_array('distance', $options['fix_data']);
                }
                if (isset($this->data_mesgs['record']['heart_rate']) && is_array($this->data_mesgs['record']['heart_rate'])) {
                    $bHeartRate = (count($this->data_mesgs['record']['heart_rate']) === $count_timestamp) ? false : in_array('heart_rate', $options['fix_data']);
                }
                if (isset($this->data_mesgs['record']['position_lat']) && isset($this->data_mesgs['record']['position_long']) && is_array($this->data_mesgs['record']['position_lat'])) {
                    $bLatitudeLongitude = (count($this->data_mesgs['record']['position_lat']) === $count_timestamp
                        && count($this->data_mesgs['record']['position_long']) === $count_timestamp) ? false : in_array('lat_lon', $options['fix_data']);
                }
                if (isset($this->data_mesgs['record']['speed']) && is_array($this->data_mesgs['record']['speed'])) {
                    $bSpeed = (count($this->data_mesgs['record']['speed']) === $count_timestamp) ? false : in_array('speed', $options['fix_data']);
                }
                if (isset($this->data_mesgs['record']['power']) && is_array($this->data_mesgs['record']['power'])) {
                    $bPower = (count($this->data_mesgs['record']['power']) === $count_timestamp) ? false : in_array('power', $options['fix_data']);
                }
                if (isset($this->data_mesgs['record']['altitude']) && is_array($this->data_mesgs['record']['altitude'])) {
                    $bAltitude = (count($this->data_mesgs['record']['altitude']) === $count_timestamp) ? false : in_array('altitude', $options['fix_data']);
                }
                if (isset($this->data_mesgs['record']['enhanced_speed']) && is_array($this->data_mesgs['record']['enhanced_speed'])) {
                    $bEnhancedSpeed = (count($this->data_mesgs['record']['enhanced_speed']) === $count_timestamp) ? false : in_array('enhanced_speed', $options['fix_data']);
                }
                if (isset($this->data_mesgs['record']['enhanced_altitude']) && is_array($this->data_mesgs['record']['enhanced_altitude'])) {
                    $bEnhancedAltitude = (count($this->data_mesgs['record']['enhanced_altitude']) === $count_timestamp) ? false : in_array('enhanced_altitude', $options['fix_data']);
                }
            }
        }

        $missing_distance_keys = [];
        $missing_hr_keys = [];
        $missing_lat_keys = [];
        $missing_lon_keys = [];
        $missing_speed_keys = [];
        $missing_power_keys = [];
        $missing_altitude_keys = [];
        $missing_enhanced_speed_keys = [];
        $missing_enhanced_altitude_keys = [];

        foreach ($this->data_mesgs['record']['timestamp'] as $timestamp) {
            if ($bCadence) {  // Assumes all missing cadence values are zeros
                if (!isset($this->data_mesgs['record']['cadence'][$timestamp])) {
                    $this->data_mesgs['record']['cadence'][$timestamp] = 0;
                }
            }
            if ($bDistance) {
                if (!isset($this->data_mesgs['record']['distance'][$timestamp])) {
                    $missing_distance_keys[] = $timestamp;
                }
            }
            if ($bHeartRate) {
                if (!isset($this->data_mesgs['record']['heart_rate'][$timestamp])) {
                    $missing_hr_keys[] = $timestamp;
                }
            }
            if ($bLatitudeLongitude) {
                if (!isset($this->data_mesgs['record']['position_lat'][$timestamp])) {
                    $missing_lat_keys[] = $timestamp;
                }
                if (!isset($this->data_mesgs['record']['position_long'][$timestamp])) {
                    $missing_lon_keys[] = $timestamp;
                }
            }
            if ($bSpeed) {
                if (!isset($this->data_mesgs['record']['speed'][$timestamp])) {
                    $missing_speed_keys[] = $timestamp;
                }
            }
            if ($bPower) {
                if (!isset($this->data_mesgs['record']['power'][$timestamp])) {
                    $missing_power_keys[] = $timestamp;
                }
            }
            if ($bAltitude) {
                if (!isset($this->data_mesgs['record']['altitude'][$timestamp])) {
                    $missing_altitude_keys[] = $timestamp;
                }
            }
            if ($bEnhancedSpeed) {
                if (!isset($this->data_mesgs['record']['enhanced_speed'][$timestamp])) {
                    $missing_enhanced_speed_keys[] = $timestamp;
                }
            }
            if ($bEnhancedAltitude) {
                if (!isset($this->data_mesgs['record']['enhanced_altitude'][$timestamp])) {
                    $missing_enhanced_altitude_keys[] = $timestamp;
                }
            }
        }

        $paused_timestamps = $this->isPaused();
        $this->filterPauseGapThreshold($paused_timestamps);

        if ($bCadence) {
            ksort($this->data_mesgs['record']['cadence']);  // no interpolation; zeros added earlier
        }
        if ($bDistance) {
            $this->interpolateMissingData($missing_distance_keys, $this->data_mesgs['record']['distance'], false, $paused_timestamps);
        }
        if ($bHeartRate) {
            $this->interpolateMissingData($missing_hr_keys, $this->data_mesgs['record']['heart_rate'], true, $paused_timestamps);
        }
        if ($bLatitudeLongitude) {
            $this->interpolateMissingData($missing_lat_keys, $this->data_mesgs['record']['position_lat'], false, $paused_timestamps);
            $this->interpolateMissingData($missing_lon_keys, $this->data_mesgs['record']['position_long'], false, $paused_timestamps);
        }
        if ($bSpeed) {
            $this->interpolateMissingData($missing_speed_keys, $this->data_mesgs['record']['speed'], false, $paused_timestamps);
        }
        if ($bPower) {
            $this->interpolateMissingData($missing_power_keys, $this->data_mesgs['record']['power'], true, $paused_timestamps);
        }
        if ($bAltitude) {
            $this->interpolateMissingData($missing_altitude_keys, $this->data_mesgs['record']['altitude'], false, $paused_timestamps);
        }
        if ($bEnhancedSpeed) {
            $this->interpolateMissingData($missing_enhanced_speed_keys, $this->data_mesgs['record']['enhanced_speed'], false, $paused_timestamps);
        }
        if ($bEnhancedAltitude) {
            $this->interpolateMissingData($missing_enhanced_altitude_keys, $this->data_mesgs['record']['enhanced_altitude'], false, $paused_timestamps);
        }
    }

    private function filterPauseGapThreshold(array &$paused_timestamps): void
    {
        $gap_threshold_seconds = 60;
        $i = 0;

        // APP-1554: a set keyed by timestamp, not a list. This was in_array() over a list that grew
        // to one entry per second of the activity, scanned once per record -- O(n^2).
        $checked_timestamps = [];

        // APP-1554: isPaused() emits one entry per second from the first record timestamp to the
        // last, so the keys are contiguous ascending integers and ordinal position maps directly to
        // key. That lets the per-record `array_search(false, array_slice($paused_timestamps, $i))`
        // -- which copied the remainder of the array every time -- collapse into one backward pass
        // recording, for each position, the first entry at or after it that is not paused.
        //
        // Safe against the mutation below: that only clears entries in [timestamp, unpaused_at),
        // every one of which is marked checked and therefore skipped, so no position this table is
        // later consulted for can have had its answer changed.
        $keys = array_keys($paused_timestamps);
        $next_unpaused_at = [];
        $next = false;  // array_search() returned false when nothing later is unpaused
        for ($p = count($keys) - 1; $p >= 0; --$p) {
            if (!$paused_timestamps[$keys[$p]]) {
                $next = $keys[$p];
            }
            $next_unpaused_at[$p] = $next;
        }

        foreach ($paused_timestamps as $timestamp => $is_paused) {
            if (isset($checked_timestamps[$timestamp])) {
                ++$i;
                continue;
            }

            if (!$is_paused) {
                $checked_timestamps[$timestamp] = true;
                ++$i;

                continue;
            }

            // look ahead to when was unpaused at
            $unpaused_at = $next_unpaused_at[$i];

            if ($unpaused_at - $timestamp <= $gap_threshold_seconds) {
                for ($x = $timestamp; $x < $unpaused_at; ++$x) {
                    $paused_timestamps[$x] = false;
                    $checked_timestamps[$x] = true;
                }
            } else {
                // range() rather than a loop, to keep its exact semantics for the degenerate
                // bounds the original could produce.
                foreach (range($timestamp, $unpaused_at) as $x) {
                    $checked_timestamps[$x] = true;
                }
            }

            ++$i;
        }
    }

    /**
     * For the missing keys in the data, interpolate using values either side and insert as necessary.
     *
     * A run of missing keys between two recorded ones is filled evenly between their values (null
     * throughout when the run starts paused); before the first or after the last recorded key, the
     * edge value is repeated. $missing_keys must be ascending, as fixData() builds them.
     *
     * One forward pass. It used to rewind the array and walk it from the start for every run of
     * missing keys -- O(records x gaps), seconds for a long ride with data_every_second.
     */
    private function interpolateMissingData(array &$missing_keys, mixed &$array, bool $is_int, array $paused_timestamps): void
    {
        if (!is_array($array) || $array === []) {
            return;  // Can't interpolate if not an array
        }

        ksort($array);
        $keys = array_keys($array);
        $min_key = $keys[0];
        $max_key = $keys[count($keys) - 1];
        $count = count($missing_keys);
        $next = 0;  // index in $keys of the first recorded key after the current missing one

        for ($i = 0; $i < $count;) {
            $missing_timestamp = $missing_keys[$i];
            if ($missing_timestamp === 0) {  // as before: 0 was the "done" marker, so never filled
                ++$i;
                continue;
            }
            $is_paused_timestamp = isset($paused_timestamps[$missing_timestamp]) && $paused_timestamps[$missing_timestamp] === true;

            // Interpolating outside recorded range is impossible - use edge values instead
            if ($missing_timestamp > $max_key || $missing_timestamp < $min_key) {
                $array[$missing_timestamp] = $is_paused_timestamp ? null : $array[$missing_timestamp > $max_key ? $max_key : $min_key];
                ++$i;
                continue;
            }

            while ($keys[$next] < $missing_timestamp) {
                ++$next;
            }
            $next_key = $keys[$next];
            $prev_value = $array[$keys[$next - 1]];
            $next_value = $array[$next_key];

            $run_end = $i + 1;
            while ($run_end < $count && $missing_keys[$run_end] < $next_key) {
                ++$run_end;
            }
            $gap = ($next_value - $prev_value) / ($run_end - $i + 1);

            for ($k = 0; $k < $run_end - $i; ++$k) {
                $gap_value = null;
                if (!$is_paused_timestamp) {
                    $gap_value = $prev_value + ($gap * ($k + 1));
                    if ($is_int) {
                        $gap_value = (int) round($gap_value);
                    }
                }
                $array[$missing_keys[$i + $k]] = $gap_value;
            }
            $i = $run_end;
        }

        ksort($array);  // sort using keys
    }

    /**
     * Change arrays that contain only one element into non-arrays so you can use $variable rather than $variable[0] to access.
     */
    private function oneElementArrays(): void
    {
        foreach ($this->data_mesgs as $mesg_key => $mesg) {
            if ($mesg_key === 'developer_data') {
                continue;
            }
            foreach ($mesg as $field_key => $field) {
                if (count($field) === 1) {
                    $first_key = key($field);
                    $this->data_mesgs[$mesg_key][$field_key] = $field[$first_key];
                }
            }
        }
    }

    /**
     * The FIT protocol makes use of enumerated data types. This returns the name of $value (or of
     * each value in an array) in the enumerated type $type, or 'unknown'.
     *
     * $this->enum_data holds the names this library has always returned (some capitalised, see
     * the comments there); any other value is named from the FIT SDK profile (Profile::TYPES) --
     * every type there can be asked for, e.g. 'lap_trigger'. 'product' is the SDK's garmin_product.
     */
    public function enumData($type, $value)
    {
        if (is_array($value)) {
            $tmp = [];
            foreach ($value as $element) {
                $tmp[] = $this->enumData($type, $element);
            }
            return $tmp;
        }
        if (!is_int($value) && !is_string($value)) {
            return 'unknown';
        }
        if (isset($this->enum_data[$type][$value])) {
            return $this->enum_data[$type][$value];
        }
        if ($type === 'product' && in_array($value, self::UNNAMED_PRODUCT_IDS, true)) {
            return 'unknown';
        }
        $name = Profile::TYPES[self::ENUM_TYPE_ALIASES[$type] ?? $type][$value] ?? null;
        if ($name === null) {
            return 'unknown';
        }

        return in_array($type, self::CAPITALISED_ENUM_TYPES, true) ? ucfirst(str_replace('_', ' ', $name)) : $name;
    }

    /**
     * Short-hand access to commonly used enumerated data, for the device that created the file.
     */
    public function manufacturer()
    {
        return $this->enumData('manufacturer', $this->creatorDeviceInfo('manufacturer'));
    }

    /**
     * The 'product' table is Garmin's product list (the SDK's garmin_product), and a product number
     * means a Garmin product only when the manufacturer is Garmin, Dynastream (OEM) or Tacx; any
     * other manufacturer's number is 'unknown' rather than some Garmin device's name.
     */
    public function product()
    {
        $manufacturer = $this->creatorDeviceInfo('manufacturer');
        if ($manufacturer !== null && !in_array($manufacturer, self::GARMIN_PRODUCT_MANUFACTURERS, true)) {
            return 'unknown';
        }

        return $this->enumData('product', $this->creatorDeviceInfo('product'));
    }

    /**
     * A device_info field of the device that created the file: the message for device_index 0
     * (creator), else the first that names a manufacturer. device_info fields are one entry per
     * message, so an index means the same device in every field.
     */
    private function creatorDeviceInfo(string $field): mixed
    {
        $device_info = $this->data_mesgs['device_info'] ?? [];
        $as_list = fn($v) => is_array($v) ? array_values($v) : [$v];
        $manufacturers = $as_list($device_info['manufacturer'] ?? null);
        $device_indexes = $as_list($device_info['device_index'] ?? null);
        $values = $as_list($device_info[$field] ?? null);

        $index = array_search(0, $device_indexes, true);
        if ($index === false || !isset($manufacturers[$index])) {
            $index = array_key_first(array_filter($manufacturers, fn($m) => $m !== null));
        }

        return $index === null ? null : ($values[$index] ?? null);
    }
    /**
     * CX-87: the native record fields (e.g. 'power') that a developer field replaced in at least one
     * session, so a caller can tell a file carrying Stryd's power from one carrying only the watch's.
     * Empty when no override was applied (none declared, none with a reading, or the option off).
     *
     * @return list<string>
     */
    public function appliedDeveloperOverrides(): array
    {
        return array_keys($this->applied_overrides);
    }

    public function sport(?int $index = null)
    {
        $tmp = $this->enumData('sport', $this->data_mesgs['session']['sport'] ?? ($this->data_mesgs['sport']['sport'] ?? 0));

        if (is_array($tmp)) {
            if ($index !== null) {
                if (isset($tmp[$index])) {
                    return $tmp[$index];
                }
            }

            return $tmp[0];
        }

        return $tmp;
    }

    /**
     * Transform the values read from the FIT file into the units requested by the user.
     */
    private function setUnits(array $options): void
    {
        if (!empty($options['units'])) {
            // Handle $options['units'] not being passed as array and/or not in lowercase.
            $units = strtolower((is_array($options['units'])) ? $options['units'][0] : $options['units']);
        } else {
            $units = 'metric';
        }

        //  Handle $options['pace'] being pass as array and/or boolean vs string and/or lowercase.
        $bPace = false;
        if (isset($options['pace'])) {
            $pace = is_array($options['pace']) ? $options['pace'][0] : $options['pace'];
            if (is_bool($pace)) {
                $bPace = $pace;
            } elseif (is_string($pace)) {
                $pace = strtolower($pace);
                if ($pace === 'true' || $pace === 'false') {
                    $bPace = $pace;
                } else {
                    throw new \Exception('phpFITFileAnalysis->setUnits(): pace option not valid!');
                }
            } else {
                throw new \Exception('phpFITFileAnalysis->setUnits(): pace option not valid!');
            }
        }

        // Set units for all messages
        $messages = ['session', 'lap', 'record', 'segment_lap'];
        $c_fields = [
                'avg_temperature',
                'max_temperature',
                'temperature'
            ];
        $m_fields = [
                'distance',
                'total_distance'
            ];
        $m_ft_fields = [
                'altitude',
                'avg_altitude',
                'enhanced_altitude',  // was missing: statute left the record's enhanced altitude in metres
                'enhanced_avg_altitude',
                'enhanced_max_altitude',
                'enhanced_min_altitude',
                'max_altitude',
                'min_altitude',
                'total_ascent',
                'total_descent'
            ];
        $ms_fields = [
                'avg_neg_vertical_speed',
                'avg_pos_vertical_speed',
                'avg_speed',
                'enhanced_avg_speed',
                'enhanced_max_speed',
                'enhanced_speed',
                'max_neg_vertical_speed',
                'max_pos_vertical_speed',
                'max_speed',
                'speed'
            ];
        $semi_fields = [
                'end_position_lat',
                'end_position_long',
                'nec_lat',
                'nec_long',
                'position_lat',
                'position_long',
                'start_position_lat',
                'start_position_long',
                'swc_lat',
                'swc_long'
            ];

        foreach ($messages as $message) {
            switch ($units) {
                case 'statute':
                    // convert from celsius to fahrenheit
                    foreach ($c_fields as $field) {
                        if (isset($this->data_mesgs[$message][$field])) {
                            if (is_array($this->data_mesgs[$message][$field])) {
                                foreach ($this->data_mesgs[$message][$field] as &$value) {
                                    if ($value === null) {  // APP-1788: a lap without a valid value
                                        continue;
                                    }
                                    $value = round((($value * 9) / 5) + 32, 2);
                                }
                            } else {
                                $this->data_mesgs[$message][$field] = round((($this->data_mesgs[$message][$field] * 9) / 5) + 32, 2);
                            }
                        }
                    }

                    // convert from meters to miles
                    foreach ($m_fields as $field) {
                        if (isset($this->data_mesgs[$message][$field])) {
                            if (is_array($this->data_mesgs[$message][$field])) {
                                foreach ($this->data_mesgs[$message][$field] as &$value) {
                                    if ($value === null) {  // APP-1788: a lap without a valid value
                                        continue;
                                    }
                                    $value = round($value * 0.000621371192, 2);
                                }
                            } else {
                                $this->data_mesgs[$message][$field] = round($this->data_mesgs[$message][$field] * 0.000621371192, 2);
                            }
                        }
                    }

                    // convert from meters to feet
                    foreach ($m_ft_fields as $field) {
                        if (isset($this->data_mesgs[$message][$field])) {
                            if (is_array($this->data_mesgs[$message][$field])) {
                                foreach ($this->data_mesgs[$message][$field] as &$value) {
                                    if ($value === null) {  // APP-1788: a lap without a valid value
                                        continue;
                                    }
                                    $value = round($value * 3.2808399, 1);
                                }
                            } else {
                                $this->data_mesgs[$message][$field] = round($this->data_mesgs[$message][$field] * 3.2808399, 1);
                            }
                        }
                    }

                    // convert  meters per second to miles per hour
                    foreach ($ms_fields as $field) {
                        if (isset($this->data_mesgs[$message][$field])) {
                            if (is_array($this->data_mesgs[$message][$field])) {
                                foreach ($this->data_mesgs[$message][$field] as &$value) {
                                    if ($value === null) {  // APP-1788: a lap without a valid value
                                        continue;
                                    }
                                    if ($bPace) {
                                        // Standing still is no pace, as for metric (a division by zero).
                                        $value = ($value != 0) ? round(60 / 2.23693629 / $value, 3) : 0;
                                    } else {
                                        $value = round($value * 2.23693629, 3);
                                    }
                                }
                            } else {
                                if ($bPace) {
                                    $speed = $this->data_mesgs[$message][$field];
                                    $this->data_mesgs[$message][$field] = ($speed != 0) ? round(60 / 2.23693629 / $speed, 3) : 0;
                                } else {
                                    $this->data_mesgs[$message][$field] = round($this->data_mesgs[$message][$field] * 2.23693629, 3);
                                }
                            }
                        }
                    }

                    // convert from semicircles to degress
                    foreach ($semi_fields as $field) {
                        if (isset($this->data_mesgs[$message][$field])) {
                            if (is_array($this->data_mesgs[$message][$field])) {
                                foreach ($this->data_mesgs[$message][$field] as &$value) {
                                    if ($value === null) {  // APP-1788: a lap without a valid value
                                        continue;
                                    }
                                    $value = round($value * (180.0 / pow(2, 31)), 5);
                                }
                            } else {
                                $this->data_mesgs[$message][$field] = round($this->data_mesgs[$message][$field] * (180.0 / pow(2, 31)), 5);
                            }
                        }
                    }

                    break;

                case 'raw':
                    // Do nothing - leave values as read from file.
                    break;
                case 'metric':
                    // convert from meters to kilometers
                    foreach ($m_fields as $field) {
                        if (isset($this->data_mesgs[$message][$field])) {
                            if (is_array($this->data_mesgs[$message][$field])) {
                                foreach ($this->data_mesgs[$message][$field] as &$value) {
                                    if ($value === null) {  // APP-1788: a lap without a valid value
                                        continue;
                                    }
                                    $value = round($value * 0.001, 2);
                                }
                            } else {
                                $this->data_mesgs[$message][$field] = round($this->data_mesgs[$message][$field] * 0.001, 2);
                            }
                        }
                    }

                    // convert  meters per second to kilometers per hour
                    foreach ($ms_fields as $field) {
                        if (isset($this->data_mesgs[$message][$field])) {
                            if (is_array($this->data_mesgs[$message][$field])) {
                                foreach ($this->data_mesgs[$message][$field] as &$value) {
                                    if ($value === null) {  // APP-1788: a lap without a valid value
                                        continue;
                                    }
                                    if ($bPace) {
                                        $value = ($value != 0) ? round(60 / 3.6 / $value, 3) : 0;
                                    } else {
                                        $value = round($value * 3.6, 3);
                                    }
                                }
                            } else {
                                if ($this->data_mesgs[$message][$field] === 0) {  // Prevent divide by zero error
                                    continue;
                                }
                                if ($bPace) {
                                    $this->data_mesgs[$message][$field] = round(60 / 3.6 / $this->data_mesgs[$message][$field], 3);
                                } else {
                                    $this->data_mesgs[$message][$field] = round($this->data_mesgs[$message][$field] * 3.6, 3);
                                }
                            }
                        }
                    }

                    // convert from semicircles to degress
                    foreach ($semi_fields as $field) {
                        if (isset($this->data_mesgs[$message][$field])) {
                            if (is_array($this->data_mesgs[$message][$field])) {
                                foreach ($this->data_mesgs[$message][$field] as &$value) {
                                    if ($value === null) {  // APP-1788: a lap without a valid value
                                        continue;
                                    }
                                    $value = round($value * (180.0 / pow(2, 31)), 5);
                                }
                            } else {
                                $this->data_mesgs[$message][$field] = round($this->data_mesgs[$message][$field] * (180.0 / pow(2, 31)), 5);
                            }
                        }
                    }

                    break;
                default:
                    throw new \Exception('phpFITFileAnalysis->setUnits(): units option not valid!');
            }
        }
    }

    /**
     * Calculate HR zones using HRmax formula: zone = HRmax * percentage.
     */
    public function hrZonesMax($hr_maximum, $percentages_array = [0.60, 0.75, 0.85, 0.95])
    {
        return array_map(fn($percentage) => round($percentage * $hr_maximum), $percentages_array);
    }

    /**
     * Calculate HR zones using HRreserve formula: zone = HRresting + ((HRmax - HRresting) * percentage).
     */
    public function hrZonesReserve($hr_resting, $hr_maximum, $percentages_array = [0.60, 0.65, 0.75, 0.82, 0.89, 0.94 ])
    {
        return array_map(fn($percentage) => round($hr_resting + ($percentage * ($hr_maximum - $hr_resting))), $percentages_array);
    }

    /**
     * Calculate power zones using Functional Threshold Power value: zone = FTP * percentage.
     */
    public function powerZones($functional_threshold_power, $percentages_array = [0.55, 0.75, 0.90, 1.05, 1.20, 1.50])
    {
        return array_map(fn($percentage) => round($percentage * $functional_threshold_power) + 1, $percentages_array);
    }

    /**
     * Partition the data (e.g. cadence, heart_rate, power, speed) using thresholds provided as an array.
     * A null (a paused second, with data_every_second) is no sample; it used to count in the lowest zone.
     */
    public function partitionData($record_field = '', $thresholds = null, $percentages = true, $labels_for_keys = true)
    {
        if (!isset($this->data_mesgs['record'][$record_field])) {
            throw new \Exception('phpFITFileAnalysis->partitionData(): '.$record_field.' data not present in FIT file!');
        }
        if (!is_array($thresholds)) {
            throw new \Exception('phpFITFileAnalysis->partitionData(): thresholds must be an array e.g. [10,20,30,40,50]!');
        }

        foreach ($thresholds as $threshold) {
            if (!is_numeric($threshold) || $threshold < 0) {
                throw new \Exception('phpFITFileAnalysis->partitionData(): '.$threshold.' not valid in thresholds!');
            }
            if (isset($last_threshold) && $last_threshold >= $threshold) {
                throw new \Exception('phpFITFileAnalysis->partitionData(): error near ..., '.$last_threshold.', '.$threshold.', ... - each element in thresholds array must be greater than previous element!');
            }
            $last_threshold = $threshold;
        }

        $result = array_fill(0, count($thresholds)+1, 0);

        foreach ((array) $this->data_mesgs['record'][$record_field] as $value) {
            if ($value === null) {
                continue;
            }
            $key = 0;
            $count = count($thresholds);
            for (; $key<$count; ++$key) {
                if ($value < $thresholds[$key]) {
                    break;
                }
            }
            $result[$key]++;
        }

        array_unshift($thresholds, 0);
        $keys = [];

        if ($labels_for_keys === true) {
            $count = count($thresholds);
            for ($i=0; $i<$count; ++$i) {
                $keys[] = $thresholds[$i] . (isset($thresholds[$i+1]) ? '-'.($thresholds[$i+1] - 1) : '+');
            }
            $result = array_combine($keys, $result);
        }

        if ($percentages === true) {
            $total = array_sum($result);
            foreach ($result as &$value) {
                $value = $total ? round($value / $total * 100, 1) : 0.0;
            }
            unset($value);
        }

        return $result;
    }

    /**
     * Split data into buckets/bins using a Counting Sort algorithm (http://en.wikipedia.org/wiki/Counting_sort) to generate data for a histogram plot.
     * Buckets are keyed by their value: an int when whole, else a string (a float key would be
     * truncated to an int -- deprecated since PHP 8.1, and wrong for a width such as 0.5). Nulls
     * (paused seconds) are no samples.
     */
    public function histogram($bucket_width = 25, $record_field = '')
    {
        if (!isset($this->data_mesgs['record'][$record_field])) {
            throw new \Exception('phpFITFileAnalysis->histogram(): '.$record_field.' data not present in FIT file!');
        }
        if (!is_numeric($bucket_width) || $bucket_width <= 0) {
            throw new \Exception('phpFITFileAnalysis->histogram(): bucket width is not valid!');
        }

        $bucket = fn($value) => ($value == (int) $value) ? (int) $value : (string) $value;
        $result = [];
        $max = 0;
        foreach ((array) $this->data_mesgs['record'][$record_field] as $value) {
            if ($value === null) {
                continue;
            }
            $bucket_value = round($value / $bucket_width) * $bucket_width;
            $max = max($max, $bucket_value);
            $key = $bucket($bucket_value);
            $result[$key] = ($result[$key] ?? 0) + 1;
        }

        for ($i=0; $i<$max / $bucket_width; ++$i) {
            $result[$bucket($i * $bucket_width)] ??= 0;
        }

        ksort($result);
        return $result;
    }

    /**
     * Helper functions / shortcuts.
     */
    public function hrPartionedHRmaximum($hr_maximum)
    {
        return $this->partitionData('heart_rate', $this->hrZonesMax($hr_maximum));
    }
    public function hrPartionedHRreserve($hr_resting, $hr_maximum)
    {
        return $this->partitionData('heart_rate', $this->hrZonesReserve($hr_resting, $hr_maximum));
    }
    public function powerPartioned($functional_threshold_power)
    {
        return $this->partitionData('power', $this->powerZones($functional_threshold_power));
    }
    public function powerHistogram($bucket_width = 25)
    {
        return $this->histogram($bucket_width, 'power');
    }

    /**
     * Simple moving average algorithm
     */
    private function sma(array $array, int|float|string $time_period): \Generator
    {
        $data = array_values($array);
        $count = count($data);
        $time_period = (int) $time_period;
        if ($time_period < 1) {
            return;
        }

        // A running sum and a count of the nulls inside the window: O(n), where slicing each window
        // was O(n x period) -- a 3,600 s best effort over a four-hour ride is 50 million additions.
        // Every window is yielded, the last included (it used to be dropped, so a period equal to
        // the number of samples had no window at all).
        $sum = 0;
        $nulls = 0;
        for ($i = 0; $i < $count; ++$i) {
            if ($data[$i] === null) {
                ++$nulls;
            } else {
                $sum += $data[$i];
            }
            if ($i >= $time_period) {
                $leaving = $data[$i - $time_period];
                if ($leaving === null) {
                    --$nulls;
                } else {
                    $sum -= $leaving;
                }
            }
            // A window containing a null is skipped: null values indicate pauses at that time.
            if ($i >= $time_period - 1 && $nulls === 0) {
                yield $sum / $time_period;
            }
        }
    }

    /**
     * Calculate TRIMP (TRaining IMPulse) and an Intensity Factor using HR data. Useful if power data not available.
     * hr_FT is heart rate at Functional Threshold, or Lactate Threshold Heart Rate (LTHR)
     */
    public function hrMetrics($hr_resting, $hr_maximum, $hr_FT, $gender)
    {
        $hr_metrics = [  // array to hold HR analysis data
            'TRIMPexp' => 0.0,
            'hrIF' => 0.0,
        ];
        if (in_array($gender, ['F', 'f', 'Female', 'female'])) {
            $gender_coeff = 1.67;
        } else {
            $gender_coeff = 1.92;
        }
        // A null is a paused second (fixData() with data_every_second): it is no sample. It used to
        // count as 0 bpm -- a negative heart-rate reserve that took TRIMP down, and a zero in the mean.
        // Each sample counts as one second, so with smart recording pass 'data_every_second'.
        $heart_rates = array_filter((array) ($this->data_mesgs['record']['heart_rate'] ?? []), fn($hr) => $hr !== null);
        foreach ($heart_rates as $hr) {
            // TRIMPexp formula from http://fellrnr.com/wiki/TRIMP
            // TRIMPexp = sum(D x HRr x 0.64ey)
            $temp_heart_rate = ($hr - $hr_resting) / ($hr_maximum - $hr_resting);
            $hr_metrics['TRIMPexp'] += ((1/60) * $temp_heart_rate * 0.64 * (exp($gender_coeff * $temp_heart_rate)));
        }
        $hr_metrics['TRIMPexp'] = round($hr_metrics['TRIMPexp']);
        $hr_metrics['hrIF'] = $heart_rates ? round((array_sum($heart_rates) / count($heart_rates)) / $hr_FT, 2) : 0.0;

        return $hr_metrics;
    }

    /**
     * Returns 'Average Power', 'Kilojoules', 'Normalised Power', 'Variability Index', 'Intensity Factor', and 'Training Stress Score' in an array.
     *
     * Normalised Power (and metrics dependent on it) require the PHP trader extension to be loaded
     * http://php.net/manual/en/book.trader.php
     */
    public function powerMetrics($functional_threshold_power)
    {
        if (!isset($this->data_mesgs['record']['power'])) {
            throw new \Exception('phpFITFileAnalysis->powerMetrics(): power data not present in FIT file!');
        }

        // Nulls are paused seconds (fixData() with data_every_second): no work, no time. kJ and TSS
        // used to count them as seconds at average power. Each sample counts as one second, so with
        // smart recording pass 'data_every_second'.
        $power = (array) $this->data_mesgs['record']['power'];
        $non_null_power_records = array_filter($power, function ($powerRecord) {
            return $powerRecord !== null;
        });
        $samples = count($non_null_power_records);

        $power_metrics['Average Power'] = $samples ? array_sum($non_null_power_records) / $samples : 0.0;
        $power_metrics['Kilojoules'] = array_sum($non_null_power_records) / 1000;

        $normalised_power = $this->normalisedPower($power);
        if ($normalised_power === null) {
            // Too short for one 30-sample window: this divided by zero; say why instead, as the no-power path does.
            throw new \Exception('phpFITFileAnalysis->powerMetrics(): too few power samples for normalised power!');
        }
        $power_metrics['Normalised Power'] = $normalised_power;

        $power_metrics['Variability Index'] = $power_metrics['Average Power'] ? $power_metrics['Normalised Power'] / $power_metrics['Average Power'] : 0.0;
        $power_metrics['Intensity Factor'] = $power_metrics['Normalised Power'] / $functional_threshold_power;
        $power_metrics['Training Stress Score'] = ($samples * $power_metrics['Normalised Power'] * $power_metrics['Intensity Factor']) / ($functional_threshold_power * 36);

        // Round the values to make them something sensible.
        $power_metrics['Average Power'] = (int)round($power_metrics['Average Power']);
        $power_metrics['Kilojoules'] = (int)round($power_metrics['Kilojoules']);
        $power_metrics['Normalised Power'] = (int)round($power_metrics['Normalised Power']);
        $power_metrics['Variability Index'] = round($power_metrics['Variability Index'], 2);
        $power_metrics['Intensity Factor'] = round($power_metrics['Intensity Factor'], 2);
        $power_metrics['Training Stress Score'] = (int)round($power_metrics['Training Stress Score']);

        return $power_metrics;
    }

    /**
     * Returns Critical Power (Best Efforts) values for supplied time period(s).
     */
    public function criticalPower($time_periods)
    {
        if (!isset($this->data_mesgs['record']['power'])) {
            throw new \Exception('phpFITFileAnalysis->criticalPower(): power data not present in FIT file!');
        }
        $power = (array) $this->data_mesgs['record']['power'];

        // The best average over $period samples; 0 when no window fits (every one spans a pause).
        $best = function ($period) use ($power) {
            $averages = ($this->php_trader_ext_loaded) ? trader_sma($power, $period) : $this->sma($power, $period);
            if ($averages === false) {
                return null;
            }
            // sma() is a generator; trader_sma() returns an array. PHP 8 rejects a Generator in
            // max(), so normalise. Pre-existing, surfaced by PowerTest once the suite could run
            // again (APP-1554).
            $averages = is_array($averages) ? $averages : iterator_to_array($averages, false);

            return $averages === [] ? 0 : max($averages);
        };

        $criticalPower_values = [];
        if (is_array($time_periods)) {
            $count = count($power);
            foreach ($time_periods as $time_period) {
                if (!is_numeric($time_period)) {
                    throw new \Exception('phpFITFileAnalysis->criticalPower(): time periods must only contain numeric data!');
                }
                if ($time_period < 0) {
                    throw new \Exception('phpFITFileAnalysis->criticalPower(): time periods cannot be negative!');
                }
                if ($time_period > $count) {
                    break;
                }

                $value = $best($time_period);
                if ($value !== null) {
                    $criticalPower_values[$time_period] = $value;
                }
            }

            return $criticalPower_values;
        } elseif (is_numeric($time_periods) && $time_periods > 0) {
            if ($time_periods > count($power)) {
                $criticalPower_values[$time_periods] = 0;
            } else {
                $value = $best($time_periods);
                if ($value !== null) {
                    $criticalPower_values[$time_periods] = $value;
                }
            }

            return $criticalPower_values;
        } else {
            throw new \Exception('phpFITFileAnalysis->criticalPower(): time periods not valid!');
        }
    }

    /**
     * Returns array of booleans using timestamp as key.
     * true == timer paused (e.g. autopause)
     */
    public function isPaused()
    {
        if (!isset($this->data_mesgs['event']['event']) || !is_array($this->data_mesgs['event']['event'])) {
            return [];
        }

        /**
         * Event enumerated values of interest
         * 0 = timer
         * event_type 0 = start; 1 = stop, 4 = stop_all, 8 = stop_disable, 9 = stop_disable_all.
         * Only stop_all counted before; devices that write a plain stop never paused.
         */
        $tek = array_keys($this->data_mesgs['event']['event'], 0, true);  // timer event keys

        // Timer starts and stops as [timestamp, paused after it], in time order and, within a
        // second, in file order. Walking the two lists separately on exact-second matches got stuck
        // on a start and stop in the same second, or one before the first record: every later
        // second then read as paused.
        $transitions = [];
        foreach ($tek as $v) {
            $event_type = $this->data_mesgs['event']['event_type'][$v] ?? null;
            $timestamp = $this->data_mesgs['event']['timestamp'][$v] ?? null;
            if ($timestamp === null) {
                continue;
            }
            if ($event_type === 0) {
                $transitions[] = [$timestamp, $v, false];
            } elseif (in_array($event_type, [1, 4, 8, 9], true)) {
                $transitions[] = [$timestamp, $v, true];
            }
        }
        sort($transitions);

        $record_timestamps = (array) ($this->data_mesgs['record']['timestamp'] ?? []);  // one record: a value
        if ($record_timestamps === []) {
            return [];
        }
        $first_ts = min($record_timestamps);  // first timestamp
        $last_ts = max($record_timestamps);  // last timestamp

        $is_paused = [];
        $bPaused = false;
        $next = 0;
        $count = count($transitions);

        for ($i = $first_ts; $i < $last_ts; ++$i) {
            // Every start or stop up to this second; the last of them decides.
            while ($next < $count && $transitions[$next][0] <= $i) {
                $bPaused = $transitions[$next][2];
                ++$next;
            }
            $is_paused[$i] = $bPaused;
        }
        $is_paused[$last_ts] = isset($this->data_mesgs['record']['speed']) && is_array($this->data_mesgs['record']['speed']) && end($this->data_mesgs['record']['speed']) === 0;

        return $is_paused;
    }

    /**
     * Returns an array that can be used to plot Circumferential Pedal Velocity (x-axis) vs Average Effective Pedal Force (y-axis).
     * NB Crank length is in metres.
     */
    public function quadrantAnalysis($crank_length, $ftp, $selected_cadence = 90, $use_timestamps = false)
    {
        if ($crank_length === null || $ftp === null) {
            return [];
        }
        if (empty($this->data_mesgs['record']['power']) || empty($this->data_mesgs['record']['cadence'])) {
            return [];
        }

        $quadrant_plot = [];
        $quadrant_plot['selected_cadence'] = $selected_cadence;
        $quadrant_plot['aepf_threshold'] = round(($ftp * 60) / ($selected_cadence * 2 * pi() * $crank_length), 3);
        $quadrant_plot['cpv_threshold'] = round(($selected_cadence * $crank_length * 2 * pi()) / 60, 3);

        // Used to calculate percentage of points in each quadrant
        $quad_percent = ['hf_hv' => 0, 'hf_lv' => 0, 'lf_lv' => 0, 'lf_hv' => 0];

        // Filter zeroes from cadence array (otherwise !div/0 error for AEPF)
        $cadence = array_filter($this->data_mesgs['record']['cadence']);
        $cpv = $aepf = 0.0;

        foreach ($cadence as $k => $c) {
            $p = isset($this->data_mesgs['record']['power'][$k]) ? $this->data_mesgs['record']['power'][$k] : 0;

            // Circumferential Pedal Velocity (CPV, m/s) = (Cadence × Crank Length × 2 × Pi) / 60
            $cpv = round(($c * $crank_length * 2 * pi()) / 60, 3);

            // Average Effective Pedal Force (AEPF, N) = (Power × 60) / (Cadence × 2 × Pi × Crank Length)
            $aepf = round(($p * 60) / ($c * 2 * pi() * $crank_length), 3);

            if ($use_timestamps === true) {
                $quadrant_plot['plot'][$k] = [$cpv, $aepf];
            } else {
                $quadrant_plot['plot'][] = [$cpv, $aepf];
            }

            if ($aepf > $quadrant_plot['aepf_threshold']) {  // high force
                if ($cpv > $quadrant_plot['cpv_threshold']) {  // high velocity
                    $quad_percent['hf_hv']++;
                } else {
                    $quad_percent['hf_lv']++;
                }
            } else {  // low force
                if ($cpv > $quadrant_plot['cpv_threshold']) {  // high velocity
                    $quad_percent['lf_hv']++;
                } else {
                    $quad_percent['lf_lv']++;
                }
            }
        }

        // Convert to percentages and add to array that will be returned by the function
        $sum = array_sum($quad_percent);
        foreach ($quad_percent as $k => $v) {
            $quad_percent[$k] = round($v / $sum * 100, 2);
        }
        $quadrant_plot['quad_percent'] = $quad_percent;

        // Calculate CPV and AEPF for cadences between 20 and 150rpm at and near to FTP
        for ($c = 20; $c <= 150; $c += 5) {
            $cpv = round((($c * $crank_length * 2 * pi()) / 60), 3);
            $quadrant_plot['ftp-25w'][] = [$cpv, round((($ftp - 25) * 60) / ($c * 2 * pi() * $crank_length), 3)];
            $quadrant_plot['ftp'][] = [$cpv, round(($ftp * 60) / ($c * 2 * pi() * $crank_length), 3)];
            $quadrant_plot['ftp+25w'][] = [$cpv, round((($ftp + 25) * 60) / ($c * 2 * pi() * $crank_length), 3)];
        }

        return $quadrant_plot;
    }

    /**
     * Returns array of gear change information.
     */
    public function gearChanges($bIgnoreTimerPaused = true)
    {
        /**
         * Event enumerated values of interest
         * 42 = front_gear_change
         * 43 = rear_gear_change
         */
        $events = (array) ($this->data_mesgs['event']['event'] ?? []);
        $fgcek = array_keys($events, 42, true);  // front gear change event keys
        $rgcek = array_keys($events, 43, true);  // rear gear change event keys
        $empty = ['front_gear_summary' => [], 'rear_gear_summary' => [], 'combined_summary' => [], 'gears_array' => []];
        if (($fgcek === [] && $rgcek === []) || !isset($this->data_mesgs['record']['timestamp'])) {
            return $empty;  // No gear changes (or no records): it used to warn its way to the same.
        }

        /**
         * gear_change_data (uint32)
         * components:
         *     rear_gear_num  00000000 00000000 00000000 11111111
         *     rear_gear      00000000 00000000 11111111 00000000
         *     front_gear_num 00000000 11111111 00000000 00000000
         *     front_gear     11111111 00000000 00000000 00000000
         * scale: 1, 1, 1, 1
         * bits: 8, 8, 8, 8
         */

        $fgc = [];  // front gear components
        $front_gears = [];
        foreach ($fgcek as $k) {
            $fgc_tmp = [
                'timestamp'   => $this->data_mesgs['event']['timestamp'][$k],
                // 'data'        => $this->data_mesgs['event']['data'][$k],
                // 'event_type'  => $this->data_mesgs['event']['event_type'][$k],
                // 'event_group' => $this->data_mesgs['event']['event_group'][$k],
                'rear_gear_num' => $this->data_mesgs['event']['data'][$k] & 255,
                'rear_gear' => ($this->data_mesgs['event']['data'][$k] >> 8) & 255,
                'front_gear_num' => ($this->data_mesgs['event']['data'][$k] >> 16) & 255,
                'front_gear' => ($this->data_mesgs['event']['data'][$k] >> 24) & 255
            ];

            $fgc[] = $fgc_tmp;

            if (!array_key_exists($fgc_tmp['front_gear_num'], $front_gears)) {
                $front_gears[$fgc_tmp['front_gear_num']] = $fgc_tmp['front_gear'];
            }
        }
        ksort($front_gears);

        $rgc = [];  // rear gear components
        $rear_gears = [];
        foreach ($rgcek as $k) {
            $rgc_tmp = [
                'timestamp'   => $this->data_mesgs['event']['timestamp'][$k],
                // 'data'        => $this->data_mesgs['event']['data'][$k],
                // 'event_type'  => $this->data_mesgs['event']['event_type'][$k],
                // 'event_group' => $this->data_mesgs['event']['event_group'][$k],
                'rear_gear_num' => $this->data_mesgs['event']['data'][$k] & 255,
                'rear_gear' => ($this->data_mesgs['event']['data'][$k] >> 8) & 255,
                'front_gear_num' => ($this->data_mesgs['event']['data'][$k] >> 16) & 255,
                'front_gear' => ($this->data_mesgs['event']['data'][$k] >> 24) & 255
            ];

            $rgc[] = $rgc_tmp;

            if (!array_key_exists($rgc_tmp['rear_gear_num'], $rear_gears)) {
                $rear_gears[$rgc_tmp['rear_gear_num']] = $rgc_tmp['rear_gear'];
            }
        }
        ksort($rear_gears);

        $timestamps = (array) $this->data_mesgs['record']['timestamp'];  // one record: a value
        $first_ts = min($timestamps);  // first timestamp
        $last_ts = max($timestamps);   // last timestamp

        $fg = 0;  // front gear at start of ride
        $rg = 0;  // rear gear at start of ride

        if (isset($fgc[0]['timestamp'])) {
            if ($first_ts == $fgc[0]['timestamp']) {
                $fg = $fgc[0]['front_gear'];
            } else {
                $fg = ($fgc[0]['front_gear_num'] == 1 ? $front_gears[2] ?? null : $front_gears[1] ?? null) ?? $fgc[0]['front_gear'];
            }
        }

        if (isset($rgc[0]['timestamp'])) {
            if ($first_ts == $rgc[0]['timestamp']) {
                $rg = $rgc[0]['rear_gear'];
            } else {
                $rg = ($rgc[0]['rear_gear_num'] == min(array_keys($rear_gears)) ? $rear_gears[$rgc[0]['rear_gear_num'] + 1] ?? null : $rear_gears[$rgc[0]['rear_gear_num'] - 1] ?? null) ?? $rgc[0]['rear_gear'];
            }
        }

        $fg_summary = [];
        $rg_summary = [];
        $combined = [];
        $gears_array = [];

        if ($bIgnoreTimerPaused === true) {
            $is_paused = $this->isPaused();
        }

        $f = 0;  // next front change
        $r = 0;  // next rear change
        for ($i = $first_ts; $i < $last_ts; ++$i) {
            // From the second after a change, ride in its gear. Every change before this second
            // applies -- several in one second (a synchro shift) or during a pause, not one per
            // second afterwards -- so this runs before a paused second is skipped.
            while (isset($fgc[$f]) && $i > $fgc[$f]['timestamp']) {
                $fg = $fgc[$f++]['front_gear'];
            }
            while (isset($rgc[$r]) && $i > $rgc[$r]['timestamp']) {
                $rg = $rgc[$r++]['rear_gear'];
            }

            if ($bIgnoreTimerPaused === true && ($is_paused[$i] ?? false) === true) {
                continue;
            }

            $fg_summary[$fg] = isset($fg_summary[$fg]) ? $fg_summary[$fg] + 1 : 1;
            $rg_summary[$rg] = isset($rg_summary[$rg]) ? $rg_summary[$rg] + 1 : 1;

            $combined[$fg][$rg] = isset($combined[$fg][$rg]) ? $combined[$fg][$rg] + 1 : 1;

            $gears_array[$i] = ['front_gear' => $fg, 'rear_gear' => $rg];
        }

        krsort($fg_summary);
        krsort($rg_summary);
        krsort($combined);

        $output = ['front_gear_summary' => $fg_summary, 'rear_gear_summary' => $rg_summary, 'combined_summary' => $combined, 'gears_array' => $gears_array];

        return $output;
    }

    /**
     * Create a JSON object that contains available record message information and CPV/AEPF if requested/available.
     */
    public function getJSON($crank_length = null, $ftp = null, $data_required = ['all'], $selected_cadence = 90)
    {
        if (!is_array($data_required)) {
            $data_required = [$data_required];
        }
        foreach ($data_required as &$datum) {
            $datum = strtolower($datum);
        }

        $all = in_array('all', $data_required);
        $timestamp         = ($all || in_array('timestamp', $data_required));
        $paused            = ($all || in_array('paused', $data_required));
        $lap               = ($all || in_array('lap', $data_required));
        $quadrant_analysis = ($all || in_array('quadrant-analysis', $data_required));
        // The record fields getJSON() can include. (A variable named after each, looked up as $$key,
        // used to decide; a record field with no such variable raised "Undefined variable".)
        $record_fields = ['temperature', 'position_lat', 'position_long', 'distance', 'altitude', 'speed', 'heart_rate', 'cadence', 'power'];
        $wanted = array_flip($all ? $record_fields : array_intersect($record_fields, $data_required));

        $for_json = [];
        $for_json['fix_data'] = isset($this->options['fix_data']) ? $this->options['fix_data'] : null;
        $for_json['units'] = isset($this->options['units']) ? $this->options['units'] : null;
        $for_json['pace'] = isset($this->options['pace']) ? $this->options['pace'] : null;

        $lap_count = 1;
        $data = [];
        if ($quadrant_analysis) {
            $quadrant_plot = $this->quadrantAnalysis($crank_length, $ftp, $selected_cadence, true);
            if (!empty($quadrant_plot)) {
                $for_json['aepf_threshold'] = $quadrant_plot['aepf_threshold'];
                $for_json['cpv_threshold'] = $quadrant_plot['cpv_threshold'];
            }
        }
        if ($paused) {
            $is_paused = $this->isPaused();
        }

        $lap_timestamps = $this->data_mesgs['lap']['timestamp'] ?? null;
        foreach ((array) ($this->data_mesgs['record']['timestamp'] ?? []) as $ts) {
            if ($lap && is_array($lap_timestamps) && isset($lap_timestamps[$lap_count - 1]) && $ts >= $lap_timestamps[$lap_count - 1]) {
                $lap_count++;
            }
            $tmp = [];
            if ($timestamp) {
                $tmp['timestamp'] = $ts;
            }
            if ($lap) {
                $tmp['lap'] = $lap_count;
            }

            foreach ($this->data_mesgs['record'] as $key => $value) {
                if (isset($wanted[$key])) {
                    $tmp[$key] = $value[$ts] ?? null;
                }
            }

            if ($quadrant_analysis) {
                if (!empty($quadrant_plot)) {
                    $tmp['cpv'] = isset($quadrant_plot['plot'][$ts]) ? $quadrant_plot['plot'][$ts][0] : null;
                    $tmp['aepf'] = isset($quadrant_plot['plot'][$ts]) ? $quadrant_plot['plot'][$ts][1] : null;
                }
            }

            if ($paused) {
                $tmp['paused'] = $is_paused[$ts] ?? false;
            }

            $data[] = $tmp;
            unset($tmp);
        }

        $for_json['data'] = $data;

        return json_encode($for_json, JSON_THROW_ON_ERROR);
    }

    /**
     * Create a JSON object that contains available lap message information.
     */
    public function getJSONLap()
    {
        $for_json = [];
        $for_json['fix_data'] = isset($this->options['fix_data']) ? $this->options['fix_data'] : null;
        $for_json['units'] = isset($this->options['units']) ? $this->options['units'] : null;
        $for_json['pace'] = isset($this->options['pace']) ? $this->options['pace'] : null;
        // A one-lap file's fields are scalars (oneElementArrays()): count() of an int was a TypeError.
        $for_json['num_laps'] = count((array) ($this->data_mesgs['lap']['timestamp'] ?? []));
        $data = [];

        for ($i=0; $i<$for_json['num_laps']; $i++) {
            $data[$i]['lap'] = $i;
            foreach ($this->data_mesgs['lap'] as $key => $value) {
                $data[$i][$key] = is_array($value) ? ($value[$i] ?? null) : $value;
            }
        }

        $for_json['data'] = $data;

        return json_encode($for_json, JSON_THROW_ON_ERROR);
    }

    /**
     * What was decoded and how, as data:
     * - 'profile_version': the FIT SDK profile the library decodes with;
     * - 'file_header': protocol and profile version, data size, of the (last) file read;
     * - 'definitions': every definition message in the file, with message and field names
     *   ('unknown' where the profile has none);
     * - 'messages': per message decoded, each field's count of values (or its value when single).
     */
    public function debugInfo(): array
    {
        $definitions = [];
        foreach ($this->defn_mesgs_all as $definition) {
            $mesg_info = $this->data_mesg_info[$definition['global_mesg_num']] ?? null;
            $fields = [];
            foreach ($definition['field_defns'] as $defn) {
                $fields[] = [
                    'field_definition_number' => $defn['field_definition_number'],
                    'size' => $defn['size'],
                    'base_type' => $defn['base_type'],
                    'field_name' => $mesg_info['field_defns'][$defn['field_definition_number']]['field_name'] ?? 'unknown',
                ];
            }
            $definitions[] = [
                'global_mesg_num' => $definition['global_mesg_num'],
                'mesg_name' => $mesg_info['mesg_name'] ?? 'unknown',
                'architecture' => $definition['architecture'] === 1 ? 'big-endian' : 'little-endian',
                'fields' => $fields,
                'num_dev_fields' => $definition['num_dev_fields'],
                'total_size' => $definition['total_size'],
            ];
        }

        $messages = [];
        foreach ($this->data_mesgs as $mesg_key => $mesg) {
            foreach ($mesg as $field_key => $field) {
                $messages[$mesg_key][$field_key] = is_array($field) ? count($field) : $field;
            }
        }

        return [
            'profile_version' => Profile::VERSION,
            'file_header' => $this->file_header,
            'definitions' => $definitions,
            'messages' => $messages,
        ];
    }

    /**
     * Outputs tables of debugInfo(): the definitions within the processed FIT file and the messages
     * found. Values from the file are HTML-escaped (they used to be echoed as they came).
     */
    public function showDebugInfo()
    {
        $e = fn($value) => htmlspecialchars(is_scalar($value) || $value === null ? (string) $value : json_encode($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $info = $this->debugInfo();

        echo '<h3>FIT SDK profile ' . $e($info['profile_version']) . '</h3>';

        echo '<h3>FIT Definition Messages contained within the file</h3>';
        echo '<table class=\'table table-condensed table-striped\'>';  // Bootstrap classes
        echo '<thead><th>global_mesg_num</th><th>architecture</th><th>field defns</th><th>total_size</th></thead><tbody>';
        foreach ($info['definitions'] as $definition) {
            echo '<tr><td>' . $e($definition['global_mesg_num']) . ' (' . $e($definition['mesg_name']) . ')</td><td>' . $e($definition['architecture']) . '</td><td>';
            foreach ($definition['fields'] as $field) {
                echo 'defn: ' . $e($field['field_definition_number']) . '; size: ' . $e($field['size']) . '; type: ' . $e($field['base_type']) . ' (' . $e($field['field_name']) . ')<br>';
            }
            echo '</td><td>' . $e($definition['total_size']) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<br><hr><br>';

        echo '<h3>Messages found in file</h3>';
        foreach ($info['messages'] as $mesg_key => $fields) {
            echo '<table class=\'table table-condensed table-striped\'>';
            echo '<thead><th>' . $e($mesg_key) . '</th><th>count() or value</th></thead><tbody>';
            foreach ($fields as $field_key => $field) {
                echo '<tr><td>' . $e($field_key) . '</td><td>' . $e($field) . '</td></tr>';
            }
            echo '</tbody></table><br><br>';
        }
    }

    /*
     * Process HR messages
     *
     * Based on the logic in GoldenCheetah's decodeHr() (commit
     * https://github.com/GoldenCheetah/GoldenCheetah/commit/957ae470999b9a57b5b8ec57e75512d4baede1ec),
     * which the FIT SDK decoders' merge_heart_rates also follows. A strap buffers beats and sends
     * them in hr messages: each carries its beats (filtered_bpm) and their times in 1/1024 s,
     * either in full (event_timestamp) or as 12-bit values counting on from the previous time
     * (event_timestamp_12). The first message whose timestamp and first time are both valid anchors
     * the times to the clock (its timestamp is that first beat's). A message's beats pair with its
     * own times, so a message with no valid beat, or times that cannot be read, affects only its
     * own beats.
     */
    private function processHrMessages(): void
    {
        if ($this->hr_messages === [] || empty($this->data_mesgs['record']['timestamp'])) {
            return;
        }

        $beats = [];     // [time in 1/1024 s, bpm]
        $last = null;    // the latest time, which 12-bit times count on from
        $anchor = null;  // clock time minus event time, in seconds
        foreach ($this->hr_messages as $message) {
            $bpm = $message['filtered_bpm'];
            // Full times when any is valid, else the 12-bit ones (decoded even with no beats to
            // pair, so the clock they count on moves on).
            $full = $message['event_timestamp'];
            $times = ($full !== null && array_filter($full, fn($t) => $t !== null) !== [])
                ? $full
                : self::hrTimes12($message['event_timestamp_12'], $last);
            if ($anchor === null && $message['timestamp'] !== null && ($times[0] ?? null) !== null) {
                $anchor = $message['timestamp'] - $times[0] / 1024.0;
            }
            foreach ($times as $i => $time) {
                if ($time === null) {
                    continue;
                }
                $last = $time;
                // filtered_bpm 255 is "no reading" (null here) and was averaged in as 255 bpm.
                if (($bpm[$i] ?? null) !== null) {
                    $beats[] = [$time, $bpm[$i]];
                }
            }
        }
        $this->hr_messages = [];  // decoded: free the copy
        if ($anchor === null) {
            return;
        }

        // Average the beats in each second of the records' range.
        $record_timestamps = (array) $this->data_mesgs['record']['timestamp'];
        $min_record_ts = min($record_timestamps);
        $max_record_ts = max($record_timestamps);
        $filtered_bpm_arr = [];
        foreach ($beats as [$time, $value]) {
            $ts_secs = (int) round($time / 1024.0 + $anchor);
            if ($ts_secs >= $min_record_ts && $ts_secs <= $max_record_ts) {
                $filtered_bpm_arr[$ts_secs][0] = ($filtered_bpm_arr[$ts_secs][0] ?? 0) + $value;
                $filtered_bpm_arr[$ts_secs][1] = ($filtered_bpm_arr[$ts_secs][1] ?? 0) + 1;
            }
        }

        if (count($filtered_bpm_arr) > 0) {
            // Heart rate can be delivered solely via `hr` messages (the separate
            // uncompressed-HR stream used by many chest-strap / ANT+ sensors). In that
            // case the `record` messages carry no heart_rate field yet, so initialise it
            // before mapping the decoded values onto the record timestamps below. (This runs
            // before oneElementArrays(), so an existing heart_rate is always keyed by timestamp.)
            $this->data_mesgs['record']['heart_rate'] ??= [];
        }

        // Populate the heart_rate fields for record messages
        foreach ($filtered_bpm_arr as $idx => $arr) {
            $this->data_mesgs['record']['heart_rate'][$idx] = (int)round($arr[0] / $arr[1]);
        }
    }

    /**
     * The times (1/1024 s) in event_timestamp_12: every 12-bit value the bytes hold, packed
     * little-endian, each counting on from the time before it (from $last, which this advances)
     * and rolling over every 4096. None when there is nothing to count on from, or the bytes are
     * invalid: a guess would misplace this message's beats and, through $last, every later one's.
     */
    private static function hrTimes12(?array $bytes, ?int &$last): array
    {
        if ($bytes === null || $last === null || in_array(null, $bytes, true)) {
            return [];
        }

        $times = [];
        $previous = $last;
        $count = intdiv(count($bytes) * 8, 12);
        for ($k = 0; $k < $count; $k++) {
            $bit = $k * 12;
            $byte = $bit >> 3;
            $value = (($bytes[$byte] | (($bytes[$byte + 1] ?? 0) << 8)) >> ($bit & 7)) & 0xFFF;
            $time = ($previous & ~0xFFF) + $value;
            if ($value < ($previous & 0xFFF)) {
                $time += 0x1000;
            }
            $times[] = $previous = $time;
        }
        $last = $previous;

        return $times;
    }
}
