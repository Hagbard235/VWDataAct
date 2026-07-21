<?php

declare(strict_types=1);

class VWEUDataActTelemetry extends IPSModule
{
    public function Create()
    {
        // Always call parent first
        parent::Create();

        // Register Module Properties
        $this->RegisterPropertyString('SourceMode', 'file');
        $this->RegisterPropertyString('FilePath', '');
        $this->RegisterPropertyString('PortalUsername', '');
        $this->RegisterPropertyString('PortalPassword', '');
        $this->RegisterPropertyString('PortalVIN', '');
        $this->RegisterPropertyInteger('PollInterval', 15);

        // Feature Toggles
        $this->RegisterPropertyBoolean('EnableHVBattery', true);
        $this->RegisterPropertyBoolean('Enable12VBordnetz', true);
        $this->RegisterPropertyBoolean('EnableSecurity', true);
        $this->RegisterPropertyBoolean('EnableTyrePressure', true);
        $this->RegisterPropertyBoolean('EnableClimatisation', true);
        $this->RegisterPropertyBoolean('EnableService', true);

        // Register Update Timer
        $this->RegisterTimer('UpdateTimer', 0, 'VWDA_UpdateData($_IPS[\'TARGET\']);');

        // Register Custom Variable Profiles
        $this->RegisterProfiles();

        // Register Status Variables
        $this->RegisterVariables();
    }

    public function ApplyChanges()
    {
        // Always call parent first
        parent::ApplyChanges();

        // Set Timer Interval (in milliseconds)
        $pollInterval = $this->ReadPropertyInteger('PollInterval');
        if ($pollInterval > 0) {
            $this->SetTimerInterval('UpdateTimer', $pollInterval * 60 * 1000);
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }

        $sourceMode = $this->ReadPropertyString('SourceMode');

        if ($sourceMode === 'file') {
            $filePath = $this->ResolvePath(trim($this->ReadPropertyString('FilePath')));
            if (empty($filePath) || !file_exists($filePath)) {
                $this->SetStatus(201); // File or folder not found
                return;
            }
        } else {
            $username = trim($this->ReadPropertyString('PortalUsername'));
            $password = trim($this->ReadPropertyString('PortalPassword'));
            if (empty($username) || empty($password)) {
                $this->SetStatus(201); // Missing credentials
                return;
            }
        }

        $this->SetStatus(102); // OK / Active

        // Execute initial update
        $this->UpdateData();
    }

    /**
     * Public method to trigger automated download & import from VW Data Act Portal
     */
    public function DownloadAndImport()
    {
        $username = trim($this->ReadPropertyString('PortalUsername'));
        $password = trim($this->ReadPropertyString('PortalPassword'));
        $vin = trim($this->ReadPropertyString('PortalVIN'));

        if (empty($username) || empty($password)) {
            $this->SetStatus(201);
            $this->SendDebug('DownloadAndImport', 'Portal Zugangsdaten (E-Mail / Passwort) fehlen.', 0);
            return false;
        }

        $targetDir = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . 'vwdataact_exports';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vwda_cookie_' . $this->InstanceID . '.txt';
        if (file_exists($cookieFile)) {
            @unlink($cookieFile);
        }

        // Step 1: Initiate Portal Auth Session
        $this->SendDebug('DownloadAndImport', 'Schritt 1: Portal-Sitzung initiieren (https://eu-data-act.drivesomethinggreater.com)...', 0);
        $portalUrl = 'https://eu-data-act.drivesomethinggreater.com/';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $portalUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $html = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        // Step 2: Post Login Credentials if login form redirected
        $this->SendDebug('DownloadAndImport', 'Schritt 2: Authentifizierung an IPD/OIDC Portal: ' . $effectiveUrl, 0);
        if (strpos($effectiveUrl, 'auth') !== false || strpos($effectiveUrl, 'identity') !== false || strpos((string)$html, 'login') !== false || strpos((string)$html, 'username') !== false) {
            preg_match('/action="([^"]+)"/', (string)$html, $matches);
            $loginUrl = isset($matches[1]) ? htmlspecialchars_decode($matches[1]) : $effectiveUrl;

            curl_setopt($ch, CURLOPT_URL, $loginUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'username' => $username,
                'password' => $password
            ]));
            $loginResponse = curl_exec($ch);
            $loginEffUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $this->SendDebug('DownloadAndImport', 'Login-Antwort URL: ' . $loginEffUrl, 0);

            if (strpos((string)$loginResponse, 'error') !== false && (strpos((string)$loginEffUrl, 'login') !== false || strpos((string)$loginEffUrl, 'auth') !== false)) {
                $this->SetStatus(203);
                $this->SendDebug('DownloadAndImport', 'Portal-Anmeldung fehlgeschlagen (Falsche E-Mail/Passwort oder MFA erforderlich).', 0);
                curl_close($ch);
                @unlink($cookieFile);
                return false;
            }
        }

        // Step 3: Fetch Active Data Requests / Exports List
        $this->SendDebug('DownloadAndImport', 'Schritt 3: Abrufen der Datenexport-Liste für VIN: ' . ($vin ?: 'Alle'), 0);
        
        $candidateEndpoints = [
            'https://eu-data-act.drivesomethinggreater.com/api/data-requests',
            'https://eu-data-act.drivesomethinggreater.com/api/v1/data-requests',
            'https://eu-data-act.drivesomethinggreater.com/api/exports',
            'https://eu-data-act.drivesomethinggreater.com/api/v1/exports'
        ];

        $downloadUrl = '';
        $lastHttpCode = 0;

        foreach ($candidateEndpoints as $endpoint) {
            $url = $endpoint;
            if (!empty($vin)) {
                $url .= '?vin=' . urlencode($vin);
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json, text/plain, */*',
                'X-Requested-With: XMLHttpRequest'
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastHttpCode = $httpCode;

            $this->SendDebug('DownloadAndImport', "Endpoint {$endpoint} => HTTP Code {$httpCode}", 0);

            if ($httpCode === 200 && !empty($response)) {
                $data = json_decode((string)$response, true);
                if (is_array($data)) {
                    $items = $data['data'] ?? $data['exports'] ?? $data['requests'] ?? $data;
                    if (is_array($items)) {
                        foreach ($items as $item) {
                            if (is_array($item)) {
                                if (isset($item['downloadUrl'])) {
                                    $downloadUrl = $item['downloadUrl'];
                                    break 2;
                                } elseif (isset($item['url'])) {
                                    $downloadUrl = $item['url'];
                                    break 2;
                                } elseif (isset($item['id'])) {
                                    $downloadUrl = "https://eu-data-act.drivesomethinggreater.com/api/data-requests/" . $item['id'] . "/download";
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (empty($downloadUrl)) {
            $this->SetStatus(202);
            $this->SendDebug('DownloadAndImport', "FEHLER (HTTP {$lastHttpCode}): Es wurde kein aktiver Datenexport für den Account / VIN gefunden.", 0);
            $this->SendDebug('DownloadAndImport', "HINWEIS: Du musst dich einmalig auf https://eu-data-act.drivesomethinggreater.com im Browser anmelden und unter 'Daten-Cluster' einen dauerhaften 15-Minuten Export für dein Fahrzeug aktivieren!", 0);
            curl_close($ch);
            @unlink($cookieFile);
            return false;
        }

        // Step 4: Download ZIP Archive
        $this->SendDebug('DownloadAndImport', 'Schritt 4: Lade Telemetrie-ZIP herunter von: ' . $downloadUrl, 0);
        $zipFilename = 'telemetry_' . date('YmdHis') . ($vin ? '_' . $vin : '') . '.zip';
        $zipPath = $targetDir . DIRECTORY_SEPARATOR . $zipFilename;

        $fp = fopen($zipPath, 'wb');
        curl_setopt($ch, CURLOPT_URL, $downloadUrl);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);
        curl_close($ch);
        @unlink($cookieFile);

        if (!$success || $httpCode >= 400 || !file_exists($zipPath) || filesize($zipPath) < 100) {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
            $this->SetStatus(202);
            $this->SendDebug('DownloadAndImport', "Download-Fehler (HTTP {$httpCode}). Die Datei konnte nicht heruntergeladen werden.", 0);
            return false;
        }

        $this->SetStatus(102);
        $this->SendDebug('DownloadAndImport', 'Neuer Telemetrie-Export erfolgreich heruntergeladen: ' . $zipFilename . ' (' . filesize($zipPath) . ' Bytes)', 0);

        // Process downloaded ZIP file
        $this->ProcessZipFile($zipPath);
        return true;
    }

    /**
     * Main Ingestion & Data Update function
     */
    public function UpdateData()
    {
        $sourceMode = $this->ReadPropertyString('SourceMode');
        if ($sourceMode === 'api') {
            $this->DownloadAndImport();
            return;
        }

        $rawPath = trim($this->ReadPropertyString('FilePath'));
        $filePath = $this->ResolvePath($rawPath);

        if (empty($filePath) || !file_exists($filePath)) {
            $this->SetStatus(201);
            $this->SendDebug('UpdateData', 'Dateipfad ungültig oder nicht vorhanden: ' . $rawPath, 0);
            return;
        }

        // Determine actual ZIP file path
        $actualZipFile = '';
        if (is_dir($filePath)) {
            $zipFiles = glob(rtrim($filePath, '/\\') . '/*.zip');
            if (!empty($zipFiles)) {
                // Pick latest modified ZIP file
                usort($zipFiles, function ($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                $actualZipFile = $zipFiles[0];
            }
        } elseif (is_file($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'zip') {
            $actualZipFile = $filePath;
        }

        if (empty($actualZipFile) || !file_exists($actualZipFile)) {
            $this->SetStatus(202);
            $this->SendDebug('UpdateData', 'Keine gültige ZIP-Datei gefunden in: ' . $filePath, 0);
            return;
        }

        $this->ProcessZipFile($actualZipFile);
    }

    /**
     * Process a ZIP file (Memory Extraction & Variable Update)
     */
    private function ProcessZipFile(string $actualZipFile)
    {
        // Open ZIP archive in memory
        $zip = new ZipArchive();
        if ($zip->open($actualZipFile) !== true) {
            $this->SetStatus(202);
            $this->SendDebug('ProcessZipFile', 'ZIP-Archiv konnte nicht geöffnet werden: ' . $actualZipFile, 0);
            return;
        }

        // Find telemetry JSON file inside ZIP archive
        $jsonString = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'json') {
                $jsonString = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();

        if (empty($jsonString)) {
            $this->SetStatus(202);
            $this->SendDebug('ProcessZipFile', 'Keine JSON-Datei im ZIP-Archiv enthalten.', 0);
            return;
        }

        $payload = json_decode($jsonString, true);
        if (!$payload || !is_array($payload)) {
            $this->SetStatus(202);
            $this->SendDebug('ProcessZipFile', 'JSON-Payload konnte nicht dekodiert werden.', 0);
            return;
        }

        $this->SetStatus(102);

        // Process telemetry payload
        $this->ProcessPayload($payload);

        // Render HTML Tile Dashboard
        $this->RenderTileVisualization();
    }

    /**
     * Resolve path relative to Symcon Kernel Dir if needed
     */
    private function ResolvePath(string $path): string
    {
        if (empty($path)) {
            return '';
        }
        if (file_exists($path)) {
            return $path;
        }
        $kernelPath = IPS_GetKernelDir() . ltrim($path, '/\\');
        if (file_exists($kernelPath)) {
            return $kernelPath;
        }
        return $path;
    }

    /**
     * Register custom IP-Symcon variable profiles
     */
    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('VWDA.Power.kW')) {
            IPS_CreateVariableProfile('VWDA.Power.kW', 2); // Float
            IPS_SetVariableProfileText('VWDA.Power.kW', '', ' kW');
            IPS_SetVariableProfileIcon('VWDA.Power.kW', 'Energy');
            IPS_SetVariableProfileDigits('VWDA.Power.kW', 1);
        }

        if (!IPS_VariableProfileExists('VWDA.Power.W')) {
            IPS_CreateVariableProfile('VWDA.Power.W', 2); // Float
            IPS_SetVariableProfileText('VWDA.Power.W', '', ' W');
            IPS_SetVariableProfileIcon('VWDA.Power.W', 'Energy');
            IPS_SetVariableProfileDigits('VWDA.Power.W', 0);
        }

        if (!IPS_VariableProfileExists('VWDA.Energy.kWh')) {
            IPS_CreateVariableProfile('VWDA.Energy.kWh', 2); // Float
            IPS_SetVariableProfileText('VWDA.Energy.kWh', '', ' kWh');
            IPS_SetVariableProfileIcon('VWDA.Energy.kWh', 'Energy');
            IPS_SetVariableProfileDigits('VWDA.Energy.kWh', 1);
        }

        if (!IPS_VariableProfileExists('VWDA.Distance.km')) {
            IPS_CreateVariableProfile('VWDA.Distance.km', 2); // Float
            IPS_SetVariableProfileText('VWDA.Distance.km', '', ' km');
            IPS_SetVariableProfileIcon('VWDA.Distance.km', 'Distance');
            IPS_SetVariableProfileDigits('VWDA.Distance.km', 0);
        }

        if (!IPS_VariableProfileExists('VWDA.Pressure.bar')) {
            IPS_CreateVariableProfile('VWDA.Pressure.bar', 2); // Float
            IPS_SetVariableProfileText('VWDA.Pressure.bar', '', ' bar');
            IPS_SetVariableProfileIcon('VWDA.Pressure.bar', 'Gauge');
            IPS_SetVariableProfileDigits('VWDA.Pressure.bar', 2);
        }

        if (!IPS_VariableProfileExists('VWDA.Voltage.V')) {
            IPS_CreateVariableProfile('VWDA.Voltage.V', 2); // Float
            IPS_SetVariableProfileText('VWDA.Voltage.V', '', ' V');
            IPS_SetVariableProfileIcon('VWDA.Voltage.V', 'Voltage');
            IPS_SetVariableProfileDigits('VWDA.Voltage.V', 1);
        }

        if (!IPS_VariableProfileExists('VWDA.Current.A')) {
            IPS_CreateVariableProfile('VWDA.Current.A', 2); // Float
            IPS_SetVariableProfileText('VWDA.Current.A', '', ' A');
            IPS_SetVariableProfileIcon('VWDA.Current.A', 'Electricity');
            IPS_SetVariableProfileDigits('VWDA.Current.A', 1);
        }

        if (!IPS_VariableProfileExists('VWDA.CellVoltage.V')) {
            IPS_CreateVariableProfile('VWDA.CellVoltage.V', 2); // Float
            IPS_SetVariableProfileText('VWDA.CellVoltage.V', '', ' V');
            IPS_SetVariableProfileIcon('VWDA.CellVoltage.V', 'Battery');
            IPS_SetVariableProfileDigits('VWDA.CellVoltage.V', 3);
        }

        if (!IPS_VariableProfileExists('VWDA.CellDrift.mV')) {
            IPS_CreateVariableProfile('VWDA.CellDrift.mV', 2); // Float
            IPS_SetVariableProfileText('VWDA.CellDrift.mV', '', ' mV');
            IPS_SetVariableProfileIcon('VWDA.CellDrift.mV', 'Battery');
            IPS_SetVariableProfileDigits('VWDA.CellDrift.mV', 1);
        }
    }

    /**
     * Register status variables for all categories
     */
    private function RegisterVariables()
    {
        // 0. Metadata & System
        $this->RegisterVariableString('VIN', 'Fahrzeug-ID (VIN)', '', 1);
        $this->RegisterVariableBoolean('IsConnected', 'Fahrzeug Online', '~Switch', 2);
        $this->RegisterVariableInteger('LastUpdate', 'Letztes Update', '~UnixTimestamp', 3);

        // 1. Hochvolt-Akku & PV-Laden
        $this->RegisterVariableFloat('HVSOC', 'Batterieladezustand (SoC)', '~Battery.100', 10);
        $this->RegisterVariableFloat('TargetSOC', 'Ziel-Ladezustand', '~Battery.100', 11);
        $this->RegisterVariableFloat('ChargePowerKW', 'Aktuelle Ladeleistung', 'VWDA.Power.kW', 12);
        $this->RegisterVariableFloat('ChargeRateKmph', 'Laderate (km/h)', 'VWDA.Distance.km', 13);
        $this->RegisterVariableFloat('RemainingChargeTimeMin', 'Restladezeit', '', 14);
        $this->RegisterVariableFloat('TotalChargedEnergyKWh', 'Geladene Energie', 'VWDA.Energy.kWh', 15);
        $this->RegisterVariableString('ChargeMode', 'Lademodus', '', 16);
        $this->RegisterVariableString('ChargeState', 'Ladestatus', '', 17);
        $this->RegisterVariableFloat('MaxCurrentL1', 'Max Netzstrom L1', 'VWDA.Current.A', 18);
        $this->RegisterVariableFloat('MaxCurrentL2', 'Max Netzstrom L2', 'VWDA.Current.A', 19);
        $this->RegisterVariableFloat('MaxCurrentL3', 'Max Netzstrom L3', 'VWDA.Current.A', 20);
        $this->RegisterVariableString('PlugConnectionState', 'Stecker-Verbindung', '', 21);
        $this->RegisterVariableString('PlugLockState', 'Stecker-Verriegelung', '', 22);

        // 2. 12V-Bordnetz & Akku-Diagnose
        $this->RegisterVariableFloat('BoardnetVoltage', '12V Boardnetzspannung', 'VWDA.Voltage.V', 30);
        $this->RegisterVariableFloat('BoardnetCurrent', '12V Strom', 'VWDA.Current.A', 31);
        $this->RegisterVariableFloat('BEMLevel', '12V BEM Level', '~Battery.100', 32);
        $this->RegisterVariableFloat('HVCellVoltageMax', 'HV Zellspannung Max', 'VWDA.CellVoltage.V', 33);
        $this->RegisterVariableFloat('HVCellVoltageMin', 'HV Zellspannung Min', 'VWDA.CellVoltage.V', 34);
        $this->RegisterVariableFloat('HVCellDrift', 'HV Zelldrift', 'VWDA.CellDrift.mV', 35);
        $this->RegisterVariableFloat('HVBatteryTempMax', 'HV Akkutemperatur Max', '~Temperature', 36);
        $this->RegisterVariableFloat('HVBatteryTempMin', 'HV Akkutemperatur Min', '~Temperature', 37);

        // 3. Sicherheit & Fahrzeugstatus
        $this->RegisterVariableBoolean('DoorLockFL', 'Tür Schloss Vorne Links', '~Lock', 40);
        $this->RegisterVariableBoolean('DoorStatusFL', 'Tür Offen Vorne Links', '~Switch', 41);
        $this->RegisterVariableBoolean('DoorLockFR', 'Tür Schloss Vorne Rechts', '~Lock', 42);
        $this->RegisterVariableBoolean('DoorStatusFR', 'Tür Offen Vorne Rechts', '~Switch', 43);
        $this->RegisterVariableBoolean('DoorLockRL', 'Tür Schloss Hinten Links', '~Lock', 44);
        $this->RegisterVariableBoolean('DoorStatusRL', 'Tür Offen Hinten Links', '~Switch', 45);
        $this->RegisterVariableBoolean('DoorLockRR', 'Tür Schloss Hinten Rechts', '~Lock', 46);
        $this->RegisterVariableBoolean('DoorStatusRR', 'Tür Offen Hinten Rechts', '~Switch', 47);
        $this->RegisterVariableBoolean('TrunkLock', 'Kofferraum Schloss', '~Lock', 48);
        $this->RegisterVariableBoolean('TrunkStatus', 'Kofferraum Offen', '~Switch', 49);
        $this->RegisterVariableBoolean('HoodLock', 'Motorhaube Schloss', '~Lock', 50);
        $this->RegisterVariableBoolean('HoodStatus', 'Motorhaube Offen', '~Switch', 51);
        $this->RegisterVariableFloat('WindowOpenFL', 'Fenster Vorne Links %', '~Battery.100', 52);
        $this->RegisterVariableFloat('WindowOpenFR', 'Fenster Vorne Rechts %', '~Battery.100', 53);
        $this->RegisterVariableFloat('WindowOpenRL', 'Fenster Hinten Links %', '~Battery.100', 54);
        $this->RegisterVariableFloat('WindowOpenRR', 'Fenster Hinten Rechts %', '~Battery.100', 55);
        $this->RegisterVariableBoolean('ParkingLights', 'Standlicht Aktiv', '~Switch', 56);

        // 4. Reifendruck & Sensorik
        $this->RegisterVariableFloat('TyrePressureFL', 'Reifendruck Ist Vorne Links', 'VWDA.Pressure.bar', 60);
        $this->RegisterVariableFloat('TyrePressureFR', 'Reifendruck Ist Vorne Rechts', 'VWDA.Pressure.bar', 61);
        $this->RegisterVariableFloat('TyrePressureRL', 'Reifendruck Ist Hinten Links', 'VWDA.Pressure.bar', 62);
        $this->RegisterVariableFloat('TyrePressureRR', 'Reifendruck Ist Hinten Rechts', 'VWDA.Pressure.bar', 63);
        $this->RegisterVariableFloat('TyrePressureReqFL', 'Reifendruck Soll Vorne Links', 'VWDA.Pressure.bar', 64);
        $this->RegisterVariableFloat('TyrePressureReqFR', 'Reifendruck Soll Vorne Rechts', 'VWDA.Pressure.bar', 65);
        $this->RegisterVariableFloat('TyrePressureReqRL', 'Reifendruck Soll Hinten Links', 'VWDA.Pressure.bar', 66);
        $this->RegisterVariableFloat('TyrePressureReqRR', 'Reifendruck Soll Hinten Rechts', 'VWDA.Pressure.bar', 67);

        // 5. Klima & Komfort
        $this->RegisterVariableFloat('OutdoorTemp', 'Außentemperatur', '~Temperature', 70);
        $this->RegisterVariableFloat('TargetCabinTemp', 'Ziel-Innenraumtemperatur', '~Temperature', 71);
        $this->RegisterVariableFloat('CabinTemp', 'Innenraumtemperatur', '~Temperature', 72);
        $this->RegisterVariableBoolean('WindowHeating', 'Scheibenheizung Aktiv', '~Switch', 73);
        $this->RegisterVariableFloat('RemainingClimatisationMin', 'Standklima Restzeit', '', 74);
        $this->RegisterVariableFloat('InteriorClimatisationPowerW', 'Verbrauch Standklima (W)', 'VWDA.Power.W', 75);
        $this->RegisterVariableFloat('ResidualPowerW', 'Verbrauch Nebenverbraucher (W)', 'VWDA.Power.W', 76);

        // 6. Laufleistung, Wartung & Systemzustand
        $this->RegisterVariableFloat('MileageKm', 'Kilometerstand', 'VWDA.Distance.km', 80);
        $this->RegisterVariableFloat('CruisingRangeKm', 'Gesamtreichweite', 'VWDA.Distance.km', 81);
        $this->RegisterVariableFloat('ServiceInspectionDays', 'Tage bis Inspektion', '', 82);
        $this->RegisterVariableString('VehicleErrorDescription', 'Aktive Warnung / Fehler', '', 83);
        $this->RegisterVariableInteger('VehicleErrorNumber', 'Fehlercode', '', 84);

        // HTML Visualisierung (HTMLBox)
        $this->RegisterVariableString('Visualization', 'Fahrzeug Kachel-Dashboard', '~HTMLBox', 100);
    }

    /**
     * Map JSON data into IP-Symcon Status Variables
     */
    private function ProcessPayload(array $payload)
    {
        $vin = $payload['vin'] ?? '';
        $this->SetValue('VIN', (string)$vin);

        $dataItems = $payload['Data'] ?? [];
        if (!is_array($dataItems)) {
            return;
        }

        // Build normalized lookup maps: fieldName -> value, key -> value
        $fieldMap = [];
        foreach ($dataItems as $item) {
            if (!isset($item['dataFieldName'])) {
                continue;
            }
            $fn = $item['dataFieldName'];
            $val = $item['value'] ?? null;
            $key = $item['key'] ?? null;

            $fieldMap[$fn] = $val;

            // Normalized index patterns like powerCurve.[0].soc -> powerCurve.[*].soc
            $norm = preg_replace('/\[\d+\]/', '[*]', $fn);
            $fieldMap[$norm] = $val;

            if ($key) {
                $fieldMap[$key] = $val;
            }
        }

        $this->SetValue('LastUpdate', time());

        // System / Metadata
        $isConnected = $fieldMap['isConnected'] ?? null;
        if ($isConnected !== null) {
            $this->SetValue('IsConnected', filter_var($isConnected, FILTER_VALIDATE_BOOLEAN));
        }

        // Cluster 1: Hochvolt-Akku & PV-Laden
        if ($this->ReadPropertyBoolean('EnableHVBattery')) {
            $soc = $this->ExtractFloat($fieldMap, ['hvsoc_info.value', 'batteryStatus.currentSOC_pct', 'battery_state_report.soc', 'hv_soc', 'state_of_charge']);
            if ($soc !== null) {
                $this->SetValue('HVSOC', $soc);
            }

            $targetSoc = $this->ExtractFloat($fieldMap, ['targetSoc_pct', 'settings.target_soc', 'state.threshold']);
            if ($targetSoc !== null) {
                $this->SetValue('TargetSOC', $targetSoc);
            }

            $chargePower = $this->ExtractFloat($fieldMap, ['chargingStatus.chargePower_kW', 'battery_state_report.charge_power', 'ChargingEvent.[*].ChargingStatus.[*].chargePowerKW']);
            if ($chargePower !== null) {
                $this->SetValue('ChargePowerKW', $chargePower);
            }

            $chargeRate = $this->ExtractFloat($fieldMap, ['chargeRateKmph', 'ChargingEvent.[*].ChargingStatus.[*].chargeRateKmph']);
            if ($chargeRate !== null) {
                $this->SetValue('ChargeRateKmph', $chargeRate);
            }

            $remChargeTime = $this->ExtractFloat($fieldMap, ['remaining_charging_time_complete', 'ChargingEvent.[*].ChargingStatus.[*].remainingChargingTimeToCompleteMin']);
            if ($remChargeTime !== null) {
                $this->SetValue('RemainingChargeTimeMin', $remChargeTime);
            }

            $totalEnergy = $this->ExtractFloat($fieldMap, ['chargingSession.[*].totalEnergyCharged', 'aggregation.day.[*].chargedEnergy', 'charged_energy']);
            if ($totalEnergy !== null) {
                $this->SetValue('TotalChargedEnergyKWh', $totalEnergy);
            }

            $chargeMode = $fieldMap['chargingStatus.chargeMode'] ?? $fieldMap['chargeModeSelection'] ?? '';
            if (!empty($chargeMode)) {
                $this->SetValue('ChargeMode', (string)$chargeMode);
            }

            $chargeState = $fieldMap['chargingStatus.currentChargeState'] ?? '';
            if (!empty($chargeState)) {
                $this->SetValue('ChargeState', (string)$chargeState);
            }

            $maxL1 = $this->ExtractFloat($fieldMap, ['HVLM_Max_Strom_Netz_L1', '01990dc3-ad7e-7c08-a0d6-3110676ff542']);
            if ($maxL1 !== null) {
                $this->SetValue('MaxCurrentL1', $maxL1);
            }

            $maxL2 = $this->ExtractFloat($fieldMap, ['HVLM_Max_Strom_Netz_L2']);
            if ($maxL2 !== null) {
                $this->SetValue('MaxCurrentL2', $maxL2);
            }

            $maxL3 = $this->ExtractFloat($fieldMap, ['HVLM_Max_Strom_Netz_L3']);
            if ($maxL3 !== null) {
                $this->SetValue('MaxCurrentL3', $maxL3);
            }

            $plugState = $fieldMap['plugStatusItem.plugConnectionState'] ?? '';
            if (!empty($plugState)) {
                $this->SetValue('PlugConnectionState', (string)$plugState);
            }

            $plugLock = $fieldMap['plugStatusItem.plugLockState'] ?? '';
            if (!empty($plugLock)) {
                $this->SetValue('PlugLockState', (string)$plugLock);
            }
        }

        // Cluster 2: 12V-Bordnetz & Akku-Diagnose
        if ($this->ReadPropertyBoolean('Enable12VBordnetz')) {
            $v12 = $this->ExtractFloat($fieldMap, ['boardnetBatteryVoltageIndication', 'BDM_Spannung_dyn', 'BDM_Spannung', '0198f4be-cd01-7118-b448-6448743738b5']);
            if ($v12 !== null) {
                $this->SetValue('BoardnetVoltage', $v12);
            }

            $i12 = $this->ExtractFloat($fieldMap, ['BDM_Strom_dyn', 'DC_IstStrom_NV', '0198f4be-b6e0-7630-b3f3-5e856eab3270']);
            if ($i12 !== null) {
                $this->SetValue('BoardnetCurrent', $i12);
            }

            $bem = $this->ExtractFloat($fieldMap, ['bem_level', '053496dc-0a8e-3e0c-93fd-055e622d3f99']);
            if ($bem !== null) {
                $this->SetValue('BEMLevel', $bem);
            }

            $vCellMax = $this->ExtractFloat($fieldMap, ['BMC_IstZellspannungMax', '0198f4be-cd01-7118-b448-6448743738bb']);
            if ($vCellMax !== null) {
                $this->SetValue('HVCellVoltageMax', $vCellMax);
            }

            $vCellMin = $this->ExtractFloat($fieldMap, ['BMC_IstZellspannungMin', '0198f4be-cd01-7118-b448-6448743738b3']);
            if ($vCellMin !== null) {
                $this->SetValue('HVCellVoltageMin', $vCellMin);
            }

            if ($vCellMax !== null && $vCellMin !== null) {
                $driftMv = abs($vCellMax - $vCellMin) * 1000.0;
                $this->SetValue('HVCellDrift', $driftMv);
            }

            $tHvMax = $this->ExtractFloat($fieldMap, ['hvbatterytemperature_info.max_temperature.value', 'hvbatterytemperature.max_temperature']);
            if ($tHvMax !== null) {
                $this->SetValue('HVBatteryTempMax', $tHvMax);
            }

            $tHvMin = $this->ExtractFloat($fieldMap, ['hvbatterytemperature_info.min_temperature.value', 'hvbatterytemperature.min_temperature']);
            if ($tHvMin !== null) {
                $this->SetValue('HVBatteryTempMin', $tHvMin);
            }
        }

        // Cluster 3: Sicherheit & Fahrzeugstatus
        if ($this->ReadPropertyBoolean('EnableSecurity')) {
            $this->SetValue('DoorLockFL', ($fieldMap['door_info.front_left.door_lock_status.value'] ?? '') === 'LOCKED');
            $this->SetValue('DoorStatusFL', ($fieldMap['door_info.front_left.door_status.value'] ?? '') === 'OPEN');
            $this->SetValue('DoorLockFR', ($fieldMap['door_info.front_right.door_lock_status.value'] ?? '') === 'LOCKED');
            $this->SetValue('DoorStatusFR', ($fieldMap['door_info.front_right.door_status.value'] ?? '') === 'OPEN');
            $this->SetValue('DoorLockRL', ($fieldMap['door_info.rear_left.door_lock_status.value'] ?? '') === 'LOCKED');
            $this->SetValue('DoorStatusRL', ($fieldMap['door_info.rear_left.door_status.value'] ?? '') === 'OPEN');
            $this->SetValue('DoorLockRR', ($fieldMap['door_info.rear_right.door_lock_status.value'] ?? '') === 'LOCKED');
            $this->SetValue('DoorStatusRR', ($fieldMap['door_info.rear_right.door_status.value'] ?? '') === 'OPEN');

            $this->SetValue('TrunkLock', ($fieldMap['trunk_lid_info.trunk_lid_lock_status.value'] ?? '') === 'LOCKED');
            $this->SetValue('TrunkStatus', ($fieldMap['trunk_lid_info.trunk_lid_status.value'] ?? '') === 'OPEN');
            $this->SetValue('HoodLock', ($fieldMap['hood_info.hood_lock_status.value'] ?? '') === 'LOCKED');
            $this->SetValue('HoodStatus', ($fieldMap['hood_info.hood_status.value'] ?? '') === 'OPEN');

            $this->SetValue('WindowOpenFL', (float)($fieldMap['window_info.front_left.window_percentage_open.value'] ?? 0));
            $this->SetValue('WindowOpenFR', (float)($fieldMap['window_info.front_right.window_percentage_open.value'] ?? 0));
            $this->SetValue('WindowOpenRL', (float)($fieldMap['window_info.rear_left.window_percentage_open.value'] ?? 0));
            $this->SetValue('WindowOpenRR', (float)($fieldMap['window_info.rear_right.window_percentage_open.value'] ?? 0));

            $parkingLeft = $fieldMap['parking_lights_info.left_status.value'] ?? 'OFF';
            $parkingRight = $fieldMap['parking_lights_info.right_status.value'] ?? 'OFF';
            $this->SetValue('ParkingLights', $parkingLeft !== 'OFF' || $parkingRight !== 'OFF');
        }

        // Cluster 4: Reifendruck & Sensorik
        if ($this->ReadPropertyBoolean('EnableTyrePressure')) {
            $pFL = $this->ExtractFloat($fieldMap, ['tyre_pressure_actual_front_left']);
            if ($pFL !== null) {
                $this->SetValue('TyrePressureFL', $pFL);
            }
            $pFR = $this->ExtractFloat($fieldMap, ['tyre_pressure_actual_front_right']);
            if ($pFR !== null) {
                $this->SetValue('TyrePressureFR', $pFR);
            }
            $pRL = $this->ExtractFloat($fieldMap, ['tyre_pressure_actual_rear_left']);
            if ($pRL !== null) {
                $this->SetValue('TyrePressureRL', $pRL);
            }
            $pRR = $this->ExtractFloat($fieldMap, ['tyre_pressure_actual_rear_right']);
            if ($pRR !== null) {
                $this->SetValue('TyrePressureRR', $pRR);
            }

            $pReqFL = $this->ExtractFloat($fieldMap, ['tyre_pressure_required_front_left']);
            if ($pReqFL !== null) {
                $this->SetValue('TyrePressureReqFL', $pReqFL);
            }
            $pReqFR = $this->ExtractFloat($fieldMap, ['tyre_pressure_required_front_right']);
            if ($pReqFR !== null) {
                $this->SetValue('TyrePressureReqFR', $pReqFR);
            }
            $pReqRL = $this->ExtractFloat($fieldMap, ['tyre_pressure_required_rear_left']);
            if ($pReqRL !== null) {
                $this->SetValue('TyrePressureReqRL', $pReqRL);
            }
            $pReqRR = $this->ExtractFloat($fieldMap, ['tyre_pressure_required_rear_right']);
            if ($pReqRR !== null) {
                $this->SetValue('TyrePressureReqRR', $pReqRR);
            }
        }

        // Cluster 5: Klima & Komfort
        if ($this->ReadPropertyBoolean('EnableClimatisation')) {
            $tOut = $this->ExtractFloat($fieldMap, ['outdoortemperature_info.value', 'outdoor_temperature']);
            if ($tOut !== null) {
                $this->SetValue('OutdoorTemp', $tOut);
            }

            $tTarget = $this->ExtractFloat($fieldMap, ['envelope.[*].report.targetTemperature.temperature', 'target_temperature']);
            if ($tTarget !== null) {
                $this->SetValue('TargetCabinTemp', $tTarget);
            }

            $tCabin = $this->ExtractFloat($fieldMap, ['in_cabin_temperature.temperature']);
            if ($tCabin !== null) {
                $this->SetValue('CabinTemp', $tCabin);
            }

            $winHeat = $fieldMap['envelope.[*].report.windowHeatingState'] ?? 'OFF';
            $this->SetValue('WindowHeating', $winHeat !== 'OFF');

            $remClimaSec = $this->ExtractFloat($fieldMap, ['envelope.[*].report.remainingClimatizationTime_min.seconds', 'remaining_climatisation_time']);
            if ($remClimaSec !== null) {
                $this->SetValue('RemainingClimatisationMin', round($remClimaSec / 60.0, 1));
            }

            $pClima = $this->ExtractFloat($fieldMap, ['interiorClimatizationConsumption']);
            if ($pClima !== null) {
                $this->SetValue('InteriorClimatisationPowerW', $pClima);
            }

            $pRes = $this->ExtractFloat($fieldMap, ['residualConsumption']);
            if ($pRes !== null) {
                $this->SetValue('ResidualPowerW', $pRes);
            }
        }

        // Cluster 6: Laufleistung, Wartung & Systemzustand
        if ($this->ReadPropertyBoolean('EnableService')) {
            $mileage = $this->ExtractFloat($fieldMap, ['mileage_info.value', 'inventoryData.[*].odometer', 'mileage']);
            if ($mileage !== null) {
                $this->SetValue('MileageKm', $mileage);
            }

            $range = $this->ExtractFloat($fieldMap, ['cruise_range_primary_info.value', 'cruising_range_combined', 'batteryStatus.cruisingRange.range']);
            if ($range !== null) {
                $this->SetValue('CruisingRangeKm', $range);
            }

            $serviceDays = $this->ExtractFloat($fieldMap, ['service_maintenance_info.due_in_time.value', 'time to next inspection']);
            if ($serviceDays !== null) {
                $this->SetValue('ServiceInspectionDays', $serviceDays);
            }

            $errDesc = $fieldMap['vehicleError.errorDescription'] ?? '';
            $this->SetValue('VehicleErrorDescription', (string)$errDesc);

            $errNum = (int)($fieldMap['vehicleError.errorNumber'] ?? 0);
            $this->SetValue('VehicleErrorNumber', $errNum);
        }
    }

    /**
     * Helper to extract numeric value from candidate field names
     */
    private function ExtractFloat(array $fieldMap, array $candidates): ?float
    {
        foreach ($candidates as $cand) {
            if (isset($fieldMap[$cand]) && is_numeric($fieldMap[$cand])) {
                return (float)$fieldMap[$cand];
            }
        }
        return null;
    }

    /**
     * Render HTML/CSS/JS Tile Dashboard for WebFront / Tile Visu
     */
    public function RenderTileVisualization()
    {
        $vin = $this->GetValue('VIN');
        $soc = round($this->GetValue('HVSOC'), 0);
        $targetSoc = round($this->GetValue('TargetSOC'), 0);
        $chargePower = round($this->GetValue('ChargePowerKW'), 1);
        $mileage = number_format($this->GetValue('MileageKm'), 0, ',', '.');
        $range = round($this->GetValue('CruisingRangeKm'), 0);
        $outdoorTemp = round($this->GetValue('OutdoorTemp'), 1);
        $targetTemp = round($this->GetValue('TargetCabinTemp'), 1);
        $inspectionDays = round($this->GetValue('ServiceInspectionDays'), 0);
        $v12 = round($this->GetValue('BoardnetVoltage'), 1);
        $cellDrift = round($this->GetValue('HVCellDrift'), 1);

        $doorFL = $this->GetValue('DoorStatusFL');
        $doorFR = $this->GetValue('DoorStatusFR');
        $doorRL = $this->GetValue('DoorStatusRL');
        $doorRR = $this->GetValue('DoorStatusRR');
        $trunk = $this->GetValue('TrunkStatus');
        $hood = $this->GetValue('HoodStatus');

        $lockFL = $this->GetValue('DoorLockFL');
        $lockFR = $this->GetValue('DoorLockFR');
        $lockRL = $this->GetValue('DoorLockRL');
        $lockRR = $this->GetValue('DoorLockRR');

        $pFL = round($this->GetValue('TyrePressureFL'), 2);
        $pFR = round($this->GetValue('TyrePressureFR'), 2);
        $pRL = round($this->GetValue('TyrePressureRL'), 2);
        $pRR = round($this->GetValue('TyrePressureRR'), 2);

        $errDesc = htmlspecialchars($this->GetValue('VehicleErrorDescription'));

        // Stroke dash offset for 283 circumference circle
        $dashOffset = round(283 - (283 * $soc / 100), 1);

        $html = <<<HTML
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); max-width: 900px; margin: auto;">

    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #1e293b; padding-bottom: 15px; margin-bottom: 20px;">
        <div>
            <span style="font-size: 1.25rem; font-weight: 700; background: linear-gradient(90deg, #38bdf8, #818cf8); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">VW EU Data Act Telemetry</span>
            <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 2px;">VIN: {$vin}</div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 1.1rem; font-weight: 600; color: #e2e8f0;">{$mileage} km</div>
            <div style="font-size: 0.8rem; color: #38bdf8;">Reichweite: {$range} km</div>
        </div>
    </div>

    <!-- Main Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">

        <!-- Widget 1: SoC & Charge -->
        <div style="background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid #334155; border-radius: 12px; padding: 16px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 0.85rem; color: #94a3b8; font-weight: 600; text-transform: uppercase;">Hochvolt-Akku</div>
                <div style="font-size: 2.2rem; font-weight: 800; color: #38bdf8; margin: 4px 0;">{$soc}<span style="font-size: 1.2rem;">%</span></div>
                <div style="font-size: 0.8rem; color: #cbd5e1;">Ziel-SoC: {$targetSoc}%</div>
                <div style="font-size: 0.8rem; color: #4ade80; margin-top: 4px;">Ladeleistung: {$chargePower} kW</div>
            </div>
            <div style="position: relative; width: 90px; height: 90px;">
                <svg width="90" height="90" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="45" fill="none" stroke="#334155" stroke-width="8"/>
                    <circle cx="50" cy="50" r="45" fill="none" stroke="#38bdf8" stroke-width="8" stroke-dasharray="283" stroke-dashoffset="{$dashOffset}" stroke-linecap="round" transform="rotate(-90 50 50)"/>
                </svg>
            </div>
        </div>

        <!-- Widget 2: Security & Doors Silhouette -->
        <div style="background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid #334155; border-radius: 12px; padding: 16px;">
            <div style="font-size: 0.85rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Fahrzeug Status</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.8rem;">
                <div style="padding: 6px; border-radius: 6px; background: " . ($doorFL ? '#ef444422' : '#22c55e11') . "; border: 1px solid " . ($doorFL ? '#ef4444' : '#22c55e44') . ";">VL: " . ($doorFL ? 'OFFEN' : ($lockFL ? 'Zu' : 'Entriegelt')) . "</div>
                <div style="padding: 6px; border-radius: 6px; background: " . ($doorFR ? '#ef444422' : '#22c55e11') . "; border: 1px solid " . ($doorFR ? '#ef4444' : '#22c55e44') . ";">VR: " . ($doorFR ? 'OFFEN' : ($lockFR ? 'Zu' : 'Entriegelt')) . "</div>
                <div style="padding: 6px; border-radius: 6px; background: " . ($doorRL ? '#ef444422' : '#22c55e11') . "; border: 1px solid " . ($doorRL ? '#ef4444' : '#22c55e44') . ";">HL: " . ($doorRL ? 'OFFEN' : ($lockRL ? 'Zu' : 'Entriegelt')) . "</div>
                <div style="padding: 6px; border-radius: 6px; background: " . ($doorRR ? '#ef444422' : '#22c55e11') . "; border: 1px solid " . ($doorRR ? '#ef4444' : '#22c55e44') . ";">HR: " . ($doorRR ? 'OFFEN' : ($lockRR ? 'Zu' : 'Entriegelt')) . "</div>
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.75rem; color: #94a3b8;">
                <span>Kofferraum: <strong style="color: " . ($trunk ? '#ef4444' : '#22c55e') . "'>" . ($trunk ? 'OFFEN' : 'Zu') . "</strong></span>
                <span>Motorhaube: <strong style="color: " . ($hood ? '#ef4444' : '#22c55e') . "'>" . ($hood ? 'OFFEN' : 'Zu') . "</strong></span>
            </div>
        </div>

        <!-- Widget 3: Reifendruck -->
        <div style="background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid #334155; border-radius: 12px; padding: 16px;">
            <div style="font-size: 0.85rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Reifendruck (bar)</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; text-align: center;">
                <div style="background: #0f172a; padding: 8px; border-radius: 8px; font-weight: 700; color: #38bdf8;">VL: " . ($pFL > 0 ? $pFL . ' bar' : '--') . "</div>
                <div style="background: #0f172a; padding: 8px; border-radius: 8px; font-weight: 700; color: #38bdf8;">VR: " . ($pFR > 0 ? $pFR . ' bar' : '--') . "</div>
                <div style="background: #0f172a; padding: 8px; border-radius: 8px; font-weight: 700; color: #38bdf8;">HL: " . ($pRL > 0 ? $pRL . ' bar' : '--') . "</div>
                <div style="background: #0f172a; padding: 8px; border-radius: 8px; font-weight: 700; color: #38bdf8;">HR: " . ($pRR > 0 ? $pRR . ' bar' : '--') . "</div>
            </div>
        </div>

        <!-- Widget 4: Klima & Diagnose -->
        <div style="background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid #334155; border-radius: 12px; padding: 16px;">
            <div style="font-size: 0.85rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Klima & Diagnose</div>
            <div style="font-size: 0.85rem; display: flex; justify-content: space-between; margin-bottom: 4px;">
                <span style="color: #94a3b8;">Außentemperatur:</span>
                <strong style="color: #f8fafc;">{$outdoorTemp} °C</strong>
            </div>
            <div style="font-size: 0.85rem; display: flex; justify-content: space-between; margin-bottom: 4px;">
                <span style="color: #94a3b8;">Soll-Innenraum:</span>
                <strong style="color: #f8fafc;">{$targetTemp} °C</strong>
            </div>
            <div style="font-size: 0.85rem; display: flex; justify-content: space-between; margin-bottom: 4px;">
                <span style="color: #94a3b8;">12V-Bordnetz:</span>
                <strong style="color: #4ade80;">" . ($v12 > 0 ? $v12 . ' V' : '--') . "</strong>
            </div>
            <div style="font-size: 0.85rem; display: flex; justify-content: space-between;">
                <span style="color: #94a3b8;">HV-Zelldrift:</span>
                <strong style="color: #facc15;">" . ($cellDrift > 0 ? $cellDrift . ' mV' : '--') . "</strong>
            </div>
        </div>
    </div>

    <!-- Footer / Service -->
    <div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #1e293b; display: flex; justify-content: space-between; font-size: 0.8rem; color: #94a3b8;">
        <div>Inspektion fällig in: <strong style="color: #f8fafc;">{$inspectionDays} Tagen</strong></div>
        <div>" . (!empty($errDesc) ? "<span style='color: #ef4444;'>Warnung: {$errDesc}</span>" : "<span style='color: #4ade80;'>Keine Systemfehler</span>") . "</div>
    </div>
</div>
HTML;

        $this->SetValue('Visualization', $html);
    }
}
