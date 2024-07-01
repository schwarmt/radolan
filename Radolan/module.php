<?php

declare(strict_types=1);
	class Radolan extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			$this->RequireParent('{4CB91589-CE01-4700-906F-26320EFCF6C4}');
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();
		}

		public function Send(string $RequestMethod, string $RequestURL, string $RequestData, int $Timeout)
		{
			$this->SendDataToParent(json_encode(['DataID' => '{D4C1D08F-CD3B-494B-BE18-B36EF73B8F43}', "RequestMethod" => $RequestMethod, "RequestURL" => $RequestURL, "RequestData" => $RequestData, "Timeout" => $Timeout]));
		}

        function delFolderContents($dir){
            $files = array_diff(scandir($dir), array('.', '..'));
            foreach ($files as $file) {
                (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
            }
        }
        function delTree($dir): bool {
            delFolderContents($dir);
            return rmdir($dir);
        }

		public function ReceiveData($JSONString)
		{
			$data = json_decode($JSONString);
			IPS_LogMessage('Device RECV', utf8_decode($data->Buffer . ' - ' . $data->RequestMethod . ' - ' . $data->RequestURL . ' - ' . $data->RequestData . ' - ' . $data->Timeout));
		}

        public function UpdateImage()
        {
            $URL = $this->ImageURL;
            if ($URL == false) {
                return false;
            }
            if ($this->ParentID == 0) {
                return false;
            }
            if (!$this->HasActiveParent()) {
                return false;
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $URL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC | CURLAUTH_DIGEST);
            $this->SendDebug('Request Image', $URL, 0);
            $Result = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $Error = curl_error($ch);
            curl_close($ch);
            if (($Result === false) || ($http_code >= 400)) {
                $this->SendDebug('Request Image ' . $http_code, $Error, 0);
                set_error_handler([$this, 'ModulErrorHandler']);
                trigger_error($Error, E_USER_NOTICE);
                restore_error_handler();
                return false;
            }
            $MediaId = $this->GetMediaId();
            IPS_SetMediaContent($MediaId, base64_encode($Result));
            return true;
        }
        protected function GetMediaId()
        {
            $MediaId = @$this->GetIDForIdent('IMAGE');
            if ($MediaId == false) {
                $MediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
                IPS_SetParent($MediaId, $this->InstanceID);
                IPS_SetName($MediaId, $this->Translate('Image'));
                IPS_SetIdent($MediaId, 'IMAGE');
                $filename = 'media' . DIRECTORY_SEPARATOR . 'ONVIF_' . $this->InstanceID . '.jpg';
                IPS_SetMediaFile($MediaId, $filename, false);
            }
            return $MediaId;
        }

        public function GetRadolanData(){

            $url = 'https://opendata.dwd.de/weather/radar/composite/wn/WN_LATEST.tar.bz2';
            $file_name = 'WN_LATEST.tar';
            $localTempRadolanFolder = IPS_GetKernelDir().DIRECTORY_SEPARATOR."media".DIRECTORY_SEPARATOR."RAD_radolan".DIRECTORY_SEPARATOR;
            if(!is_dir($localTempRadolanFolder)){
                mkdir($localTempRadolanFolder);
            }

            $WNdataDir=$localTempRadolanFolder.DIRECTORY_SEPARATOR.'full'.DIRECTORY_SEPARATOR;
            if(!is_dir($WNdataDir)){
                mkdir($WNdataDir);
            }
            else{
                delFolderContents($WNdataDir);
            }

            $outDir=$localTempRadolanFolder.DIRECTORY_SEPARATOR."out".DIRECTORY_SEPARATOR;
            if(!is_dir($outDir)){
                mkdir($outDir);
            }
            else{
                delFolderContents($outDir);
            }

            $full_file_name = $localTempRadolanFolder.DIRECTORY_SEPARATOR.$file_name;

            $localImage = IPS_GetKernelDir()."\\media\\bild.jpg";

            // Use file_get_contents() function to get the file
            // from url and use file_put_contents() function to
            // save the file by using base name
            if(file_put_contents( $full_file_name,bzdecompress ( file_get_contents($url), true ))) {
                echo "File downloaded successfully";
            }
            else {
                echo "File downloading failed.";
            }
            try {
                $phar = new PharData($full_file_name);
                $phar->extractTo($WNdataDir, null, true); // extract all files
            } catch (Exception $e) {
                // handle errors
            }

        }
	}