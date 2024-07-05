<?php

declare(strict_types=1);


class RadolanComposite {

    public float $offx= 0 ; // horizontal projection offset
    public float $offy= 0 ; // vertical projection offset

    public float $Rx = 0;  // horizontal resolution in km/px
    public float $Ry = 0;  // vertical resolution in km/px

    public int $Dx = 0 ; // data width
    public int $Dy = 0 ; // data height

    public float $earthRadius = 6370.040; // km

    public float $junctionNorth = 0; // N
    public float $junctionEast  = 0; // E

    public float $lamda0 = 0;
    public float $phi0 = 0;

    // corners // N, E
    public float $originTop = 0;
    public float $originLeft = 0; // N, E
    public float $edgeBottom = 0;
    public float $edgeRight = 0; // N, E

    function __construct(){
        // calibrate projection
        $this->Rx = 1;
        $this->Ry = 1;
        $this->Dx = 1100;
        $this->Dy = 1200;

        $this->earthRadius = 6370.040; // km - R
        $this->junctionNorth = 60.0; // N  - phi0
        $this->junctionEast  = 10.0; // E  - lamda0

        $this->lamda0 = $this->rad($this->junctionEast);
        $this->phi0 = $this->rad($this->junctionNorth);

        // corners // N, E
        $this->originTop = 55.8621;
        $this->originLeft = 1.14445; // N, E
        $this->edgeBottom = 45.6882;
        $this->edgeRight = 16.5967; // N, E

        [$this->offx, $this->offy] = $this->translate($this->originTop, $this->originLeft);
        [$resx, $resy] = $this->translate($this->edgeBottom, $this->edgeRight);
        $this->Rx = $resx / $this->Dx;
        $this->Ry = $resy / $this->Dy;
    }

    function rad($deg): float {
        return $deg*(pi()/180.0);
    }

    function square($val): float {
        return $val * $val;
    }

    function translateLatLonToGrid($x, $y): array
    {
        return $this->translate($x, $y);
    }

    function translateGridToLatLon($x, $y): array
    {
        return $this->translateXYtoLatLon($x, $y);
    }

    function translateXYtoLatLon($x, $y) : array  {
        $x *= $this->Rx;
        $y *= $this->Ry;

        $x += $this->offx;
        $y += $this->offy;

        $y = -$y;
        $lamda = atan(-$x / $y) + $this->lamda0;
        $term = $this->square($this->earthRadius) * $this->square(1 + sin($this->phi0));
        $phi = asin(($term -($this->square($x) + $this->square($y))) / ($term + ($this->square($x) + $this->square($y))));
        return [(int)rad2deg($phi), (int)rad2deg($lamda)];
    }

    // x, y := c.Translate(52.51861, 13.40833)	// Berlin (lat, lon)
    function translate($north, $east): array
    {
        // latitude north / longitude east
        // Translate main

        $phi = $this->rad($north);

        $lamda = $this->rad($east);

        $m = (1.0 + sin($this->phi0)) / (1.0 + sin($phi));
        $x = ($this->earthRadius * $m * cos($phi) * sin($lamda - $this->lamda0));
        $y = ($this->earthRadius * $m * cos($phi) * cos($lamda - $this->lamda0));

        // offset correction
        $x -= $this->offx;
        $y -= $this->offy;

        // scaling
        $x /= $this->Rx;
        $y /= $this->Ry;
        return [(int)round($x), (int)round($y)];
    }

}



class Radolan extends IPSModule
{
    const STATUS_ACTIVE = 102;
    const STATUS_INACTIVE = 104;
    const STATUS_ERROR = 200;

    const DB_IDENTS = ['000', '005', '010', '015', '020', '025', '030', '035',
        '040', '045', '050', '055', '060', '065', '070', '075',
        '080', '085', '090', '095', '100', '105', '110', '115', '120'
    ];

    const DBZ_MIN_VALUE = 1;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyFloat("Latitude", 48.762778);
        $this->RegisterPropertyFloat("Longitude", 11.424722);
        $this->RegisterPropertyInteger("Radius", 8);
        $this->RegisterPropertyString("Place", "Ingolstadt");
        $this->RegisterPropertyBoolean("TimerActive", true);
        $this->RegisterPropertyInteger('Period', 900);
        $this->RegisterPropertyInteger('dBZRainMin', 2);

        $this->RegisterAttributeString("RadolanDirectory", "");
        $this->RegisterAttributeString("WNDataDirectory", "");
        $this->RegisterAttributeString("ImageOutDirectory", "");

        $this->RegisterVariableString("BaseTimeString", "BaseTimeString", "");
        $this->RegisterVariableInteger("BaseTime", "BaseTime", "~UnixTimestamp");

        $this->RegisterAttributeString("currentAvgdBZ", "");


        foreach (self::DB_IDENTS as $currIdent) {
            $this->RegisterVariableFloat($currIdent, "Regenintensität " . $currIdent);
        }
        //Timer
        $this->RegisterTimer('UpdateData', 0, 'RAD_UpdateData($_IPS[\'TARGET\']);');

        $this->SetTimer($this->ReadPropertyBoolean("TimerActive"));
    }

    public function Destroy()
    {
        $radolanDirectory = $this->ReadAttributeString("RadolanDirectory");
        if ($radolanDirectory !== "") {
            $this->delTree($radolanDirectory);
        }
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->SetTimer($this->ReadPropertyBoolean("TimerActive"));

    }

    function createEmptyImage()
    {
        return @imagecreatetruecolor(1100, 1200);
    }

    function addColorsToImage($im, $colors): array
    {
        $colMapping = array();
        foreach ($colors as $val => $col) {
            $colVal = imagecolorallocate($im, $col["r"], $col["g"], $col["b"]);
            $colMapping[$val] = $colVal;
        }
        return $colMapping;
    }

    function delFolderContents($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
        }
    }

    function delTree($dir): bool
    {
        $this->delFolderContents($dir);
        return rmdir($dir);
    }

    function SetTimer($timerActive){
        if ($timerActive === true) {
            $this->SetTimerInterval('UpdateData', $this->ReadPropertyInteger('Period') * 1000);
            $this->SendDebug('Timer auf aktiv gesetzt', "",0);

        } else {
            $this->SetTimerInterval('UpdateData', 0);
            $this->SendDebug('Timer auf inaktiv gesetzt', "",0);
        }
        $this->SendDebug('Set Timer', ($timerActive ? 'true' : 'false'),0);

    }

    function setBaseTimeFromFileName($filename)
    {
        $res = DateTime::createFromFormat("ymdHi", substr($filename, 2, 10), new DateTimeZone('UTC'));
        $timezone = new DateTimeZone('Europe/Berlin');
        $res->setTimezone($timezone);
        SetValue($this->GetIDForIdent("BaseTimeString"), $res->format('d.m.Y H:i:s'));
        SetValue($this->GetIDForIdent("BaseTime"), $res->getTimestamp());
    }

    function getOffsetFromFileName($filename)
    {
        return substr($filename, 13, 3);
    }

    function getTimestampFromFileName($filename)
    {
        $res = DateTime::createFromFormat("ymdHi", substr($filename, 2, 10), new DateTimeZone('UTC'));
        $timezone = new DateTimeZone('Europe/Berlin');
        $res->setTimezone($timezone);
        $offset = $this->getOffsetFromFileName($filename);
        $res->add(new DateInterval('PT' . $offset . 'M'));
        return $res->getTimestamp();
    }

    function storeCurrentAvgdBZ($valueArray)
    {
        $this->WriteAttributeString("currentAvgdBZ", serialize($valueArray));
    }

    function getCurrentAvgdBZ()
    {
        $currValues = $this->ReadAttributeString("currentAvgdBZ");
        return unserialize($currValues, ['allowed_classes' => false]);
    }

    public function maxdBZForTimestamp($fromTimestamp, $duration)
    {
        $toTimestamp = $fromTimestamp;
        $toTimestamp += $duration*60;
        $currValues = $this->getCurrentAvgdBZ();
        $maxdBZ=-1;
        $found = false;
        foreach ($currValues as $key => $value) {
            if ($key >= $fromTimestamp and $key <= $toTimestamp) {
                $found = true;
                if($value > $maxdBZ){
                    $maxdBZ = $value;
                }
            }
        }
        return $maxdBZ;
    }

    public function isRainExpected($fromTimestamp, $duration)
    {
        $rainExpected=false;
        $maxdBZ = $this->maxdBZForTimestamp($fromTimestamp, $duration);
        $dBZRainMin=$this->ReadPropertyInteger("dBZRainMin");
        if (($maxdBZ >= $dBZRainMin) or($maxdBZ === -1)) {
            $rainExpected = true;
        }
        return $rainExpected;
    }
    protected function SetImage($timeOffset, $filename)
    {
        $MediaId = @$this->GetIDForIdent('IMAGE_' . $timeOffset);
        if ($MediaId == false) {
            $MediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($MediaId, $this->InstanceID);
            IPS_SetName($MediaId, "Bild " . $timeOffset);
            IPS_SetIdent($MediaId, 'IMAGE_' . $timeOffset);
        }
        IPS_SetMediaFile($MediaId, $filename, true);
        return $MediaId;
    }

    public function UpdateData(){
        $this->SendDebug('UpdateData started',"",0);
        $this->GetRadolanData();
        $this->ProcessRadolanData();
        $this->SendDebug('UpdateData ended',"",0);
    }

    public function GetRadolanData()
    {
        $this->SendDebug('GetRadolanData started',"",0);

        $url = 'https://opendata.dwd.de/weather/radar/composite/wn/WN_LATEST.tar.bz2';
        $file_name = 'WN_LATEST.tar';

        //  /mnt/data/symcon/media/RAD_radolan

        $localTempRadolanFolder = IPS_GetKernelDir() . DIRECTORY_SEPARATOR . "media" . DIRECTORY_SEPARATOR . "RAD_radolan" . $this->InstanceID;
        $this->WriteAttributeString("RadolanDirectory", $localTempRadolanFolder);

        if (!is_dir($localTempRadolanFolder)) {
            mkdir($localTempRadolanFolder);
        }

        $WNdataDir = $localTempRadolanFolder . DIRECTORY_SEPARATOR . 'full' . DIRECTORY_SEPARATOR;
        $this->WriteAttributeString("WNDataDirectory", $WNdataDir);

        if (!is_dir($WNdataDir)) {
            mkdir($WNdataDir);
        } else {
            $this->delFolderContents($WNdataDir);
        }

        $outDir = $localTempRadolanFolder . DIRECTORY_SEPARATOR . "out" . DIRECTORY_SEPARATOR;
        $this->WriteAttributeString("ImageOutDirectory", $outDir);

        if (!is_dir($outDir)) {
            mkdir($outDir);
        } else {
            $this->delFolderContents($outDir);
        }

        $full_file_name = $localTempRadolanFolder . DIRECTORY_SEPARATOR . $file_name;
        $full_file_name_bz2 = $localTempRadolanFolder . DIRECTORY_SEPARATOR . $file_name . ".bz2";
        $localImage = IPS_GetKernelDir() . "\\media\\bild.jpg";

        file_put_contents($full_file_name_bz2, file_get_contents($url));
        exec("bzip2 -d $full_file_name_bz2");

        try {
            $phar = new PharData($full_file_name);
            $phar->extractTo($WNdataDir, null, true); // extract all files
        } catch (Exception $e) {
            $this->SendDebug('Error extracting tar', $e->getTraceAsString(), 0);
        }
        // tar-file löschen
        unlink($full_file_name);
        $this->SendDebug('GetRadolanData ended',"",0);
    }

    public function ProcessRadolanData()
    {
        $this->SendDebug('ProcessRadolanData started',"",0);

        include 'Borders.php';
        include 'Cities.php';

        $colors = array(
            "85" => array("r" => 255, "g" => 50, "b" => 255),
            "75" => array("r" => 153, "g" => 0, "b" => 153),
            "65" => array("r" => 1, "g" => 0, "b" => 202),
            "60" => array("r" => 72, "g" => 72, "b" => 255),
            "55" => array("r" => 180, "g" => 1, "b" => 1),
            "50.5" => array("r" => 255, "g" => 2, "b" => 0),
            "46" => array("r" => 254, "g" => 137, "b" => 2),
            "41.5" => array("r" => 255, "g" => 196, "b" => 2),
            "37" => array("r" => 255, "g" => 255, "b" => 0),
            "32.5" => array("r" => 204, "g" => 230, "b" => 2),
            "28" => array("r" => 154, "g" => 204, "b" => 2),
            "23.5" => array("r" => 77, "g" => 191, "b" => 25),
            "19" => array("r" => 0, "g" => 153, "b" => 52),
            "14.5" => array("r" => 0, "g" => 202, "b" => 202),
            "10" => array("r" => 52, "g" => 255, "b" => 255),
            "5.5" => array("r" => 153, "g" => 255, "b" => 255),
            "1" => array("r" => 227, "g" => 255, "b" => 255),
            "0" => array("r" => 255, "g" => 255, "b" => 255));

        $measureLat = $this->ReadPropertyFloat("Latitude");
        $measureLon = $this->ReadPropertyFloat("Longitude");
        $measureRadius = $this->ReadPropertyInteger("Radius");
        $measureName = $this->ReadPropertyString("Place");
        $predictionLength = 300;

        date_default_timezone_set('Europe/Berlin');

        $c = new RadolanComposite();

        $imBackground = $this->createEmptyImage();
        $colMappingBackground = $this->addColorsToImage($imBackground, $colors);
        $black = imagecolorallocate($imBackground, 0, 0, 0);
        $transparent = imagecolorallocate($imBackground, 1, 1, 1);
        imagecolortransparent($imBackground, $transparent);

        imagefill($imBackground, 0, 0, $transparent);
        $myBorder = new Borders();
        foreach ($myBorder->border as $borderDot) {
            [$x, $y] = $c->translate($borderDot[0], $borderDot[1]);
            imagesetpixel($imBackground, $x, $y, $black);
        }

        // draw mesh
        for ($e = 1.0; $e < 16.0; $e += 0.1) {
            for ($n = 46.0; $n < 55.0; $n += 0.1) {
                if ($e - intval($e) < 0.1 || $n - intval($n) < 0.1) {
                    [$x, $y] = $c->translate($n, $e);
                    imagesetpixel($imBackground, $x, $y, $black);
                }
            }
        }

        [$xx, $yy] = $c->translate($measureLat, $measureLon);

        $measureX = intval($xx);
        $measureY = intval($yy);
        $radiusSquared = $measureRadius * $measureRadius;
        $measureLeftSquareX = $measureX - $measureRadius;
        $measureRightSquareX = $measureX + $measureRadius;
        $measureTopSquareY = $measureY - $measureRadius;
        $measureBottomSquareY = $measureY + $measureRadius;
        $predictionLeftSquareX = $measureX - $predictionLength;
        $predictionRightSquareX = $measureX + $predictionLength;
        $predictionTopSquareY = $measureY - $predictionLength;
        $predictionBottomSquareY = $measureY + $predictionLength;

        $relPixel = array();
        for ($x = $measureX - $measureRadius; $x <= $measureX + $measureRadius; $x++) {
            for ($y = $measureY - $measureRadius; $y <= $measureY + $measureRadius; $y++) {
                $dx = $x - $measureX;
                $dy = $y - $measureY;
                $distanceSquared = $dx * $dx + $dy * $dy;
                if ($distanceSquared < $radiusSquared) {
                    array_push($relPixel, array("x" => $x, "y" => $y, "d" => sqrt($distanceSquared)));
                    //imagesetpixel($imBackground, $x, $y, $black);
                }

            }
        }
        imageellipse($imBackground, $measureX, $measureY, $measureRadius * 2, $measureRadius * 2, $black);

        // Begrenzung Vorhersagebereich
        imagerectangle($imBackground, $predictionLeftSquareX - 1, $predictionTopSquareY - 1, $predictionRightSquareX + 1, $predictionBottomSquareY + 1, $black);

        // Markierung Vorhersageort
        // relevante Stelle (Fadenkreuz)
        [$xx, $yy] = $c->translate($measureLat, $measureLon);
        $xi = intval($xx);
        $yi = intval($yy);
        for ($xp = $xi - 8; $xp < $xi - 3; $xp++) {
            if ($xp >= 0 && $xp <= 1099) {
                imagesetpixel($imBackground, $xp, $yi, $black);
            }
        }
        for ($xp = $xi + 8; $xp > $xi + 3; $xp--) {
            if ($xp >= 0 && $xp <= 1099) {
                imagesetpixel($imBackground, $xp, $yi, $black);
            }
        }
        for ($yp = $yi - 8; $yp < $yi - 3; $yp++) {
            if ($yp >= 0 && $yp <= 1199) {
                imagesetpixel($imBackground, $xi, $yp, $black);
            }
        }
        for ($yp = $yi + 8; $yp > $yi + 3; $yp--) {
            if ($yp >= 0 && $yp <= 1199) {
                imagesetpixel($imBackground, $xi, $yp, $black);
            }
        }

        // Städte eintragen
        $myCities = new Cities();
        foreach ($myCities->cities as $city) {
            [$x, $y] = $c->translate($city[1], $city[2]);
            imagerectangle($imBackground, $x - 2, $y - 2, $x + 2, $y + 2, $black);
            imagestring($imBackground, 5, $x - 10, $y + 4, $city[0], $black);
        }

        // Legende
        $i = 0;
        $fromString = "  >";
        $toString = "";
        $lx = 80;
        $ly = 100;
        $lw = 80;
        $lh = 50;

        foreach ($colMappingBackground as $limit => $col) {
            imagefilledrectangle($imBackground, $lx, $ly + $i * $lh, $lx + $lw, $ly + $i * $lh + $lh, $col);
            imagerectangle($imBackground, $lx, $ly + $i * $lh, $lx + $lw, $ly + $i * $lh + $lh, $black);
            $limitString = number_format((float)$limit, 1, ".", null);
            if ($limit < 10) {
                $limitString = " " . $limitString;
            }
            imagestring($imBackground, 3, $lx + 10, $ly + $i * $lh + 15, $fromString . $limitString . $toString, $black);
            $toString = "-$limitString";
            $fromString = "";
            $i++;
        }

        imagestring($imBackground, 5, 480, 1150, "Quelle: Deutscher Wetterdienst / OpenStreetMap", $black);


        $imMerge = $this->createEmptyImage();
        $colMapping = $this->addColorsToImage($imMerge, $colors);

        $avgdBZArray = array();

        $first = true;

        $WNdataDir = $this->ReadAttributeString("WNDataDirectory");

        foreach (scandir($WNdataDir, SCANDIR_SORT_ASCENDING) as $filename) {
            if ($filename != "." && $filename != "..") {
                $im = $this->createEmptyImage();
                $colMapping = $this->addColorsToImage($im, $colors);
                $black = imagecolorallocate($im, 0, 0, 0);
                $white = imagecolorallocate($im, 255, 255, 255);
                $pink = imagecolorallocate($im, 255, 50, 255);
                $transparent = imagecolorallocate($im, 1, 1, 1);
                imagecolortransparent($im, $transparent);
                if ($first) {
                    imagefill($im, 0, 0, $white);
                } else {
                    imagecopymerge($im, $imMerge, 0, 0, 0, 0, 1100, 1200, 50);
                    imagefilledrectangle($im, $predictionLeftSquareX, $predictionTopSquareY, $predictionRightSquareX, $predictionBottomSquareY, $white);
                }

                if ($first) {
                    $this->setBaseTimeFromFileName($filename);
                }

                $currentTime = $this->getTimestampFromFileName($filename);

                $this->SendDebug('Processing Image', $filename, 0);

                $timeOffset = $this->getOffsetFromFileName($filename);

                $outDir = $this->ReadAttributeString("ImageOutDirectory");
                $imageOutFile = $outDir . $filename . ".png";

                $handle = fopen($WNdataDir . $filename, "rb");

                $x = 0;
                $y = 1199;
                $highVal = 0;
                $lowVal = 0;
                $lastNoData = false;
                $currentNoData = false;
                $metaData = array();

                $metaData["produktkennung"] = fread($handle, 2);
                $metaData["messungUTC"] = fread($handle, 6);
                $metaData["WMONummer"] = intval(fread($handle, 5));
                $metaData["messungZeitpunkt"] = fread($handle, 4);
                $metaData["kennungBY"] = fread($handle, 2);
                $metaData["produktLaenge"] = intval(fread($handle, 10));
                $metaData["kennungVS"] = fread($handle, 2);
                $metaData["formatVersion"] = intval(fread($handle, 2));
                $metaData["kennungSW"] = fread($handle, 2);
                $metaData["SoftwareVersion"] = fread($handle, 9);
                $metaData["kennungPR"] = fread($handle, 2);
                $metaData["Genauigkeit"] = fread($handle, 5);
                $metaData["kennungINT"] = fread($handle, 3);
                $metaData["intervalldauer"] = fread($handle, 4);
                $metaData["kennungGP"] = fread($handle, 2);
                $metaData["anzahlPixel"] = fread($handle, 9);
                $metaData["kennungVV"] = fread($handle, 2);
                $metaData["Vorhersagezeitpunkt"] = intval(fread($handle, 4));
                $metaData["kennungMF"] = fread($handle, 2);
                $metaData["Modulflags"] = fread($handle, 9);
                $metaData["kennungMS"] = fread($handle, 2);
                $metaData["Textlaenge"] = intval(fread($handle, 3));
                $metaData["Text"] = fread($handle, $metaData["Textlaenge"]);
                $metaData["ende"] = ord(fread($handle, 1));

                $components = preg_split("/x/", $metaData["anzahlPixel"]);
                $anzPixelX = intval($components[0]);
                $anzPixelY = intval($components[1]);
                $zeitpunkt = "20" . substr($metaData["messungZeitpunkt"], 2, 2) . "-" . substr($metaData["messungZeitpunkt"], 0, 2) . "-" .
                    substr($metaData["messungUTC"], 0, 2) . " " . substr($metaData["messungUTC"], 2, 2) . ":" . substr($metaData["messungUTC"], 4, 2) . ":00";
                $messungZeitpunkt = date_create($zeitpunkt, new DateTimeZone('+0000'));
                $timezone = date_default_timezone_get();
                $userTimezone = new DateTimeZone($timezone);
                $messungZeitpunkt->setTimezone($userTimezone);
                $vorhersageZeitpunkt = "";
                try {
                    $vorhersageZeitpunkt = date_add($messungZeitpunkt, new DateInterval("PT" . $metaData["Vorhersagezeitpunkt"] . "M"));
                } catch (Exception $e) {
                    $this->SendDebug('Error computing Vorhersagezeitpunkt', $e->getTraceAsString(), 0);
                }

                $dBZSum = 0;
                $sumCount = 0;
                if (!$first) {
                    if ($predictionBottomSquareY < 1199) {
                        // Zeilen unterhalb des Vorsagebereichs ignorieren
                        $ignoreFirst = (1199 - $predictionBottomSquareY) * 1100 * 2;
                        fread($handle, $ignoreFirst);
                        $y = $predictionBottomSquareY;
                    }
                }
                while (!feof($handle) && ($first || ($y > $predictionTopSquareY))) {
                    // erstes Bild oder unterhalb des Vorhersagebereichs
                    if (!$first) {
                        if ($x == 0 && $predictionLeftSquareX > 0) {
                            // Spalten links von Vorhersagebereich ignorieren
                            fread($handle, ($predictionLeftSquareX) * 2);
                            $x = $predictionLeftSquareX; // + 1;
                        } elseif ($x == $predictionRightSquareX && $predictionRightSquareX < 1099) {
                            // Spalten rechts von Vorhersagebereich ignorieren
                            fread($handle, (1099 - $predictionRightSquareX + 1) * 2);
                            $x = 1100;
                        }
                    }
                    if ($x < 1100) {
                        $lowVal = ord(fread($handle, 1));
                        $highVal = ord(fread($handle, 1));
                        $dBZ = ($lowVal + $highVal * 256) / 20 - 32.5;
                        $color = 0;
                        // Verarbeitung
                        if ($highVal == 41) {
                            $currentNoData = true;
                        } else {
                            $currentNoData = false;
                            $color = $white;
                            foreach ($colMapping as $limit => $col) {
                                if ($dBZ >= $limit) {
                                    $color = $col;
                                    break;
                                }
                            }
                            if ($x >= $measureLeftSquareX && $x <= $measureRightSquareX && $y >= $measureTopSquareY && $y <= $measureBottomSquareY) {
                                foreach ($relPixel as $i => $pixel) {
                                    if ($x == $pixel["x"] && $y == $pixel["y"]) {
                                        if ($dBZ > 0) {
                                            $dBZSum += $dBZ * ($measureRadius - $pixel["d"]) / $measureRadius;
                                        }
                                        $sumCount = $sumCount + ($measureRadius - $pixel["d"]) / $measureRadius;
                                    }
                                }
                            }
                        }
                        if ($currentNoData) {
                            if ($lastNoData) {
                                $color = $white;
                            } else {
                                $color = $pink;
                            }
                        } else {
                            if ($lastNoData && $x > 0) {
                                imagesetpixel($im, $x - 1, $y, $pink);
                            }
                        }
                        $lastNoData = $currentNoData;
                        imagesetpixel($im, $x, $y, $color);
                        $x++;
                    }
                    if ($x == 1100) {
                        $x = 0;
                        $y--;
                    }
                }
                fclose($handle);
                if ($first) {
                    // $im sichern (vor Beschriftung)
                    imagecopy($imMerge, $im, 0, 0, 0, 0, 1100, 1200);
                }

                $avgdBZ = 0;
                if ($sumCount > 0) {
                    $avgdBZ = $dBZSum / $sumCount;
                }
                $avgdBZArray[$currentTime] = $avgdBZ;

                $zeitpunktString = date_format($vorhersageZeitpunkt, "d.m.Y - H:i - ") . number_format($avgdBZ, 2, ".", null) . " dBZ";

                SetValue($this->GetIDForIdent($timeOffset), $avgdBZ);
                imagestring($im, 5, 80, 80, $zeitpunktString, $black);

                $color = $white;
                foreach ($colMapping as $limit => $col) {
                    if ($avgdBZ >= $limit) {
                        $color = $col;
                        break;
                    }
                }
                // aktueller Wert
                $i = sizeof($colMappingBackground);
                imagefilledrectangle($im, $lx, $ly + $i * $lh, $lx + $lw, $ly + $i * $lh + $lh, $color);
                imagerectangle($im, $lx, $ly + $i * $lh, $lx + $lw, $ly + $i * $lh + $lh, $black);
                $avgdBZString = number_format($avgdBZ, 1, ".", null);
                if ($avgdBZ < 10) {
                    $avgdBZString = " " . $avgdBZString;
                }
                imagestring($im, 3, $lx + 10, $ly + $i * $lh + 10, "dBZ: $avgdBZString", $black);
                imagecopymerge($im, $imBackground, 0, 0, 0, 0, 1100, 1200, 100);
                $imout = imagecrop($im, ['x' => 80, 'y' => 80, 'width' => 950, 'height' => 1100]);

                imagepng($imout, $imageOutFile);
                $this->SetImage($timeOffset, $imageOutFile);

                imagedestroy($im);
                imagedestroy($imout);
                $first = false;
            }
        }
        imagedestroy($imBackground);
        imagedestroy($imMerge);
        $this->storeCurrentAvgdBZ($avgdBZArray);
        //$this->SendDebug('Werte', var_dump($avgdBZArray), 0);
        $this->SendDebug('ProcessRadolanData ended',"",0);

    }

    public function CheckRain(string $zeitpunkt, string $dauer)
    {
        $timestamp = (DateTime::createFromFormat('d.m.Y H:i:s', $zeitpunkt))->getTimestamp();
        $max=$this->maxdBZForTimestamp($timestamp,(int)$dauer);
        $regen=$this->isRainExpected($timestamp, (int)$dauer);
        $dt = new DateTime('@' . $timestamp);
        $timezone = new DateTimeZone('Europe/Berlin');
        $dt->setTimezone($timezone);
        echo("Regen erwartet ab".($dt->format('d.m.Y H:i:s'))." für ".$dauer." Minuten: dBZ: ".$max." - Regen: ".($regen ? 'true' : 'false'));
    }
}