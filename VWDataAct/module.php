<?php

declare(strict_types=1);

class VWEUDataActTelemetry extends IPSModule
{
    // EU Data Act portal and VW group identity service. The portal flow follows evcc
    // (vehicle/vw/eudataact) and ioBroker.vw-connect (lib/euDataAct.js).
    private const PORTAL_BASE = 'https://eu-data-act.drivesomethinggreater.com';
    private const PORTAL_HOST = 'eu-data-act.drivesomethinggreater.com';
    private const IDENTITY_BASE = 'https://identity.vwgroup.io';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // OIDC client id and state suffix per brand
    private const BRANDS = [
        'Volkswagen' => ['9b58543e-1c15-4193-91d5-8a14145bebb0@apps_vw-dilab_com', 'VOLKSWAGEN_PASSENGER_CARS'],
        'Audi'       => ['cc29b87a-5e9a-4362-aecf-5adea6b01bbb@apps_vw-dilab_com', 'AUDI'],
        'Skoda'      => ['3ea88bf9-1d4e-4a68-b3ad-4098c1f1d246@apps_vw-dilab_com', 'SKODA'],
        'Seat'       => ['f85e5b69-e3b2-43aa-9c0d-1b7d0e0b576f@apps_vw-dilab_com', 'SEAT'],
        'Cupra'      => ['f85e5b69-e3b2-43aa-9c0d-1b7d0e0b576f@apps_vw-dilab_com', 'CUPRA']
    ];

    // Number of newest datasets merged on the first portal import
    private const MAX_BACKFILL = 8;

    // Number of downloaded datasets kept in user/vwdataact_exports
    private const KEEP_EXPORTS = 50;

    // Seconds the timer pauses after a failed login, so VW does not lock the account
    private const LOGIN_RETRY_DELAY = 3600;

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
        $this->RegisterPropertyString('PortalBrand', 'Volkswagen');
        $this->RegisterPropertyInteger('PollInterval', 15);

        // Portal import state: delivery time and VIN of the newest imported dataset,
        // time of the last failed login
        $this->RegisterAttributeInteger('LastDatasetCreatedOn', 0);
        $this->RegisterAttributeString('LastDatasetVIN', '');
        $this->RegisterAttributeInteger('LoginFailedAt', 0);

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
     * Public method to trigger automated download & import from VW Data Act Portal.
     *
     * The portal is not a live API: it stores a dataset whenever the vehicle reports
     * something and only ever appends. Datasets are partial, so every run imports all
     * datasets delivered after the newest one already imported, oldest first.
     */
    public function DownloadAndImport()
    {
        $username = trim($this->ReadPropertyString('PortalUsername'));
        $password = trim($this->ReadPropertyString('PortalPassword'));
        $vin = strtoupper(trim($this->ReadPropertyString('PortalVIN')));
        $brandName = $this->ReadPropertyString('PortalBrand');

        if (empty($username) || empty($password)) {
            $this->SetStatus(201);
            $this->SendDebug('DownloadAndImport', 'Portal Zugangsdaten (E-Mail / Passwort) fehlen.', 0);
            return false;
        }
        if (!isset(self::BRANDS[$brandName])) {
            $this->SetStatus(201);
            $this->SendDebug('DownloadAndImport', 'Unbekannte Marke: ' . $brandName, 0);
            return false;
        }

        $targetDir = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . 'vwdataact_exports';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        // The cookie file keeps the portal session between runs; the module only logs in
        // again when the portal rejects the session.
        $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vwda_cookie_' . $this->InstanceID . '.txt';
        $ch = $this->PortalCurl($cookieFile);
        $session = [
            'brand'    => self::BRANDS[$brandName],
            'user'     => $username,
            'password' => $password,
            'loggedIn' => false
        ];

        try {
            if ($vin === '') {
                $vin = $this->PortalFindVin($ch, $session);
            }
            $vinPath = rawurlencode($vin);

            // Step 1: identifier of the data request
            $this->SendDebug('DownloadAndImport', 'Schritt 1: Datenanfrage für VIN ' . $vin . ' abrufen...', 0);
            $res = $this->PortalGet($ch, self::PORTAL_BASE . '/proxy_api/euda-apim/datarequest/vehicles/' . $vinPath . '/metadata/partial', ['Accept: application/json'], $session);
            if ($res['code'] === 404) {
                throw new Exception('Für VIN ' . $vin . ' ist im Portal keine Datenanfrage eingerichtet (HTTP 404).', 205);
            }
            if ($res['code'] >= 400) {
                throw new Exception('Datenanfrage konnte nicht gelesen werden (HTTP ' . $res['code'] . ').', 206);
            }
            $meta = json_decode($res['body'], true);
            $identifier = is_array($meta) ? (string)($meta['Identifier'] ?? '') : '';
            if ($identifier === '') {
                throw new Exception('Das Portal liefert keine Kennung der Datenanfrage - Datenanfrage im Portal prüfen.', 205);
            }
            $dataUrl = self::PORTAL_BASE . '/proxy_api/euda-apim/datadelivery/vehicles/' . $vinPath . '/' . rawurlencode($identifier);

            // Step 2: delivered datasets
            $this->SendDebug('DownloadAndImport', 'Schritt 2: Liste der Datensätze abrufen...', 0);
            $res = $this->PortalGet($ch, $dataUrl . '/list', ['Accept: application/json', 'type: partial'], $session);
            $list = [];
            if ($res['code'] === 404) {
                // "No files available for this request" until the vehicle delivered its first dataset
                $this->SendDebug('DownloadAndImport', 'Das Portal hat noch keine Datensätze für dieses Fahrzeug.', 0);
            } elseif ($res['code'] >= 400) {
                throw new Exception('Liste der Datensätze konnte nicht gelesen werden (HTTP ' . $res['code'] . ').', 206);
            } else {
                $decoded = json_decode($res['body'], true);
                if (is_array($decoded) && isset($decoded['files']) && is_array($decoded['files'])) {
                    $list = $decoded['files'];
                } elseif (is_array($decoded) && ($decoded === [] || isset($decoded[0]))) {
                    $list = $decoded;
                } else {
                    throw new Exception('Unerwartete Antwort auf die Liste der Datensätze: ' . substr($res['body'], 0, 200), 206);
                }
            }

            $datasets = $this->ContentDatasets($list);
            $summary = count($list) . ' Einträge im Portal, davon ' . count($datasets) . ' mit Inhalt';
            if (!empty($datasets)) {
                $summary .= ', neuester vom ' . date('d.m.Y H:i:s', $datasets[count($datasets) - 1]['ts']);
            }
            $this->SendDebug('DownloadAndImport', $summary, 0);

            // Step 3: datasets not imported yet
            $after = $this->ReadAttributeInteger('LastDatasetCreatedOn');
            if ($this->ReadAttributeString('LastDatasetVIN') !== $vin) {
                $after = 0;
            }
            $pending = $this->PendingDatasets($datasets, $after);

            if (empty($pending)) {
                $this->SetStatus(102);
                $this->SendDebug('DownloadAndImport', 'Keine neuen Datensätze' . ($after > 0 ? ' seit ' . date('d.m.Y H:i:s', $after) : '') . ' - das Portal liefert nur, wenn am Fahrzeug etwas passiert.', 0);
                return true;
            }

            // Step 4: download and import, oldest first
            $imported = 0;
            foreach ($pending as $dataset) {
                $this->SendDebug('DownloadAndImport', 'Schritt 4: Lade ' . $dataset['name'] . ' (' . date('d.m.Y H:i:s', $dataset['ts']) . ')...', 0);
                $res = $this->PortalGet($ch, $dataUrl . '/download', [
                    'filename: ' . str_replace(["\r", "\n"], '', $dataset['name']),
                    'type: partial'
                ], $session);
                if ($res['code'] >= 400 || strncmp($res['body'], 'PK', 2) !== 0) {
                    throw new Exception('Download von ' . $dataset['name'] . ' fehlgeschlagen (HTTP ' . $res['code'] . ', ' . strlen($res['body']) . ' Bytes, keine ZIP-Datei).', 202);
                }

                $zipPath = $targetDir . DIRECTORY_SEPARATOR . basename($dataset['name']);
                if (file_put_contents($zipPath, $res['body']) === false) {
                    throw new Exception('ZIP-Datei konnte nicht gespeichert werden: ' . $zipPath, 202);
                }

                // An unreadable dataset is skipped so it cannot block all newer ones.
                if ($this->ProcessZipFile($zipPath, $dataset['ts'])) {
                    $imported++;
                } else {
                    $this->LogMessage('VWDataAct: Datensatz ' . $dataset['name'] . ' konnte nicht importiert werden und wird übersprungen.', KL_WARNING);
                }

                $this->WriteAttributeInteger('LastDatasetCreatedOn', $dataset['ts']);
                $this->WriteAttributeString('LastDatasetVIN', $vin);
            }

            $this->PruneExports($targetDir);
            $this->SendDebug('DownloadAndImport', $imported . ' von ' . count($pending) . ' Datensätzen importiert.', 0);
            return $imported === count($pending);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 203) {
                // pause automatic runs so repeated failed logins cannot lock the VW account
                $this->WriteAttributeInteger('LoginFailedAt', time());
            }
            $this->SetStatus(in_array($code, [201, 202, 203, 205, 206], true) ? $code : 206);
            $this->SendDebug('DownloadAndImport', 'FEHLER: ' . $e->getMessage(), 0);
            $this->LogMessage('VWDataAct: ' . $e->getMessage(), KL_ERROR);
            return false;
        } finally {
            // Persist the session cookies for the next run
            curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
            unset($ch);
        }
    }

    /**
     * Forget which portal datasets were imported; the next run imports the newest ones again.
     */
    public function ResetPortalState()
    {
        $this->WriteAttributeInteger('LastDatasetCreatedOn', 0);
        $this->WriteAttributeString('LastDatasetVIN', '');
        $this->SendDebug('ResetPortalState', 'Portal-Stand zurückgesetzt - der nächste Abruf lädt die neuesten ' . self::MAX_BACKFILL . ' Datensätze.', 0);
    }

    /**
     * Returns the VIN of the only vehicle linked in the portal.
     */
    private function PortalFindVin($ch, array &$session): string
    {
        $res = $this->PortalGet($ch, self::PORTAL_BASE . '/proxy_api/consent/me/vehicles?viewPosition=FRONT_LEFT', ['Accept: application/json'], $session);
        if ($res['code'] >= 400) {
            throw new Exception('Fahrzeugliste konnte nicht gelesen werden (HTTP ' . $res['code'] . ').', 206);
        }

        // the response is either a bare array or wrapped in {"vehicles": [...]}
        $decoded = json_decode($res['body'], true);
        $vehicles = [];
        if (is_array($decoded)) {
            $vehicles = (isset($decoded['vehicles']) && is_array($decoded['vehicles'])) ? $decoded['vehicles'] : $decoded;
        }

        $vins = [];
        foreach ($vehicles as $vehicle) {
            if (!is_array($vehicle)) {
                continue;
            }
            $found = (string)($vehicle['vin'] ?? '');
            if ($found === '') {
                $found = (string)($vehicle['vehicleIdentificationNumber'] ?? '');
            }
            if ($found !== '') {
                $vins[] = strtoupper($found);
            }
        }

        if (count($vins) === 1) {
            $this->SendDebug('PortalFindVin', 'Fahrzeug im Portal gefunden: ' . $vins[0], 0);
            return $vins[0];
        }
        if (empty($vins)) {
            throw new Exception('Im Portal ist kein Fahrzeug verknüpft.', 205);
        }
        throw new Exception('Mehrere Fahrzeuge im Portal (' . implode(', ', $vins) . ') - bitte die VIN in der Instanz eintragen.', 201);
    }

    private function PortalCurl(string $cookieFile)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        return $ch;
    }

    /**
     * Executes a request on the shared handle (redirects and cookies included).
     */
    private function PortalRequest($ch, string $method, string $url, array $headers = [], array $form = []): array
    {
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($ch);

        return [
            'code'  => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'url'   => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'body'  => is_string($body) ? $body : '',
            'error' => $body === false ? curl_error($ch) : ''
        ];
    }

    /**
     * True if the portal answered with a login redirect or page instead of data. An expired
     * session also shows up as HTTP 5xx with an HTML error page (Adobe AEM).
     */
    private function PortalNeedsLogin(array $res): bool
    {
        if ($res['code'] === 401 || $res['code'] === 403) {
            return true;
        }
        $host = (string)parse_url($res['url'], PHP_URL_HOST);
        if ($host !== '' && $host !== self::PORTAL_HOST) {
            return true;
        }
        $html = strncmp(ltrim($res['body']), '<', 1) === 0;
        return $html && ($res['code'] === 200 || $res['code'] >= 500);
    }

    /**
     * GET on the portal API, logging in once if the session is missing or expired.
     */
    private function PortalGet($ch, string $url, array $headers, array &$session): array
    {
        $res = $this->PortalRequest($ch, 'GET', $url, $headers);
        if ($res['error'] === '' && !$session['loggedIn'] && $this->PortalNeedsLogin($res)) {
            $this->SendDebug('PortalGet', 'Keine gültige Portal-Sitzung (HTTP ' . $res['code'] . ') - Anmeldung...', 0);
            $this->PortalLogin($ch, $session);
            $session['loggedIn'] = true;
            $res = $this->PortalRequest($ch, 'GET', $url, $headers);
        }

        if ($res['error'] !== '') {
            throw new Exception('Portal nicht erreichbar: ' . $res['error'], 202);
        }
        if ($this->PortalNeedsLogin($res)) {
            throw new Exception('Das Portal verweigert den Zugriff trotz Anmeldung (HTTP ' . $res['code'] . ', ' . $this->UrlWithoutQuery($res['url']) . ').', 203);
        }
        return $res;
    }

    /**
     * OIDC authorization-code login at the VW group identity service. The portal sets its
     * session cookie while the redirect chain leads back to the portal.
     */
    private function PortalLogin($ch, array $session): void
    {
        [$clientId, $brandState] = $session['brand'];

        // start with a fresh session
        curl_setopt($ch, CURLOPT_COOKIELIST, 'ALL');

        // prime the portal session (best effort), it sets cookies the login callback needs
        $this->PortalRequest($ch, 'GET', self::PORTAL_BASE . '/');

        // the portal's own redirect servlet fails for non-browser clients, so the
        // authorize url is built directly
        $authorizeQuery = http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'scope'         => 'openid cars profile',
            'state'         => 'de__en__' . $brandState,
            'redirect_uri'  => self::PORTAL_BASE . '/login',
            'prompt'        => 'login',
            'nonce'         => $this->RandomString(43)
        ]);
        $signin = $this->PortalRequest($ch, 'GET', self::IDENTITY_BASE . '/oidc/v1/authorize?' . $authorizeQuery);
        $this->SendDebug('PortalLogin', 'Login-Seite (Marke ' . $brandState . '): HTTP ' . $signin['code'] . ', ' . $this->UrlWithoutQuery($signin['url']), 0);
        if ($signin['error'] !== '' || $signin['code'] !== 200) {
            throw new Exception('Login-Seite nicht erreichbar (HTTP ' . $signin['code'] . ') ' . $signin['error'], 203);
        }

        $login = $this->LoginFields($signin['body']);
        if (!empty($login['fields']['hmac']) && !empty($login['fields']['_csrf'])) {
            $landing = $this->PortalLoginLegacy($ch, $signin, $login, $session['user'], $session['password'], $brandState);
        } else {
            $this->SendDebug('PortalLogin', 'Kein E-Mail/Passwort-Formular (hmac/_csrf fehlen) - versuche neuen Login-Ablauf.', 0);
            $landing = $this->PortalLoginNew($ch, $signin['body'], $session['user'], $session['password']);
        }

        $landing = $this->SkipMarketingConsent($ch, $landing);

        $final = $landing['url'];
        $path = (string)parse_url($final, PHP_URL_PATH);
        $this->SendDebug('PortalLogin', 'Zielseite: HTTP ' . $landing['code'] . ', ' . $this->UrlWithoutQuery($final), 0);

        // terms of use that were updated or never accepted for this brand
        if (stripos($path, '/terms-and-conditions') !== false) {
            throw new Exception('Anmeldung angehalten: VW verlangt die Bestätigung der Nutzungsbedingungen (Marke ' . $brandState . '). Bitte einmal im Browser auf ' . self::PORTAL_BASE . ' mit dieser Marke anmelden und bestätigen.', 203);
        }

        // consent screen: the password was correct, but the portal was never authorised in a browser
        if (stripos($path, '/signin-service/v1/consent/') !== false || stripos($landing['body'], 'consent-screen') !== false) {
            throw new Exception('Das EU-Data-Act-Portal ist für dieses VW-Konto noch nicht freigegeben: einmal im Browser auf ' . self::PORTAL_BASE . ' anmelden und auf der VW-Einwilligungsseite zustimmen.', 203);
        }
        if (strpos($final, 'signin-service') !== false || strpos($path, '/error') !== false) {
            parse_str((string)parse_url($final, PHP_URL_QUERY), $query);
            $code = is_string($query['error'] ?? null) ? $query['error'] : $this->LoginErrorText($this->ExtractTemplateModel($landing['body']) ?? []);
            if ($code !== '') {
                throw new Exception('Anmeldung fehlgeschlagen: ' . $this->DescribeLoginError($code) . ' (' . $code . ').', 203);
            }
            throw new Exception('Anmeldung fehlgeschlagen ohne Fehlercode (HTTP ' . $landing['code'] . ', Zielseite ' . $this->UrlWithoutQuery($final) . ').', 203);
        }
        if ((string)parse_url($final, PHP_URL_HOST) !== self::PORTAL_HOST) {
            throw new Exception('Anmeldung nicht abgeschlossen, unerwartete Zielseite: ' . $this->UrlWithoutQuery($final), 203);
        }

        $this->WriteAttributeInteger('LoginFailedAt', 0);
        $this->SendDebug('PortalLogin', 'Anmeldung erfolgreich.', 0);
    }

    /**
     * Identity login with separate email and password pages (as ioBroker.vw-connect).
     */
    private function PortalLoginLegacy($ch, array $signin, array $login, string $user, string $password, string $brandState): array
    {
        // email / identifier step: form inputs plus hmac/relayState/_csrf from window._IDK
        $fields = $login['fields'];
        $fields['email'] = $user;
        $identifierUrl = $this->ResolveUrl($signin['url'], (string)$login['action']);
        $auth = $this->PortalRequest($ch, 'POST', $identifierUrl, ['Referer: ' . $signin['url']], $fields);
        $this->SendDebug('PortalLogin', 'E-Mail-Schritt: HTTP ' . $auth['code'] . ', ' . $this->UrlWithoutQuery($auth['url']), 0);
        if ($auth['error'] !== '' || $auth['code'] >= 400) {
            throw new Exception('Anmeldung: E-Mail-Schritt fehlgeschlagen (HTTP ' . $auth['code'] . ') ' . $auth['error'], 203);
        }

        // password / authenticate step: posted to the form action of the password page
        $step = $this->LoginFields($auth['body']);
        if (empty($step['fields']['hmac']) || empty($step['fields']['_csrf'])) {
            $error = $this->LoginErrorText($step['model']);
            throw new Exception('Anmeldung: keine Passwort-Seite erhalten - ' . ($error !== '' ? $this->DescribeLoginError($error) . ' (' . $error . ')' : 'E-Mail-Adresse prüfen') . '.', 203);
        }
        $authenticateUrl = (string)$step['action'] !== '' ? $this->ResolveUrl($auth['url'], (string)$step['action']) : $this->UrlWithoutQuery($auth['url']);
        $template = is_string($step['model']['template'] ?? null) ? $step['model']['template'] : '';

        // Only ever send the password to the login. For an e-mail address VW does not know
        // for this brand it answers with its registration form instead.
        if (!$this->IsPasswordPage($template, $auth['url'], $authenticateUrl)) {
            if ($template === 'registerCredentials' || strpos($this->UrlWithoutQuery($auth['url']), '/register') !== false) {
                throw new Exception('Anmeldung abgebrochen, Passwort nicht gesendet: VW kennt diese E-Mail-Adresse für die Marke ' . $brandState . ' nicht und bietet eine Registrierung an. Bitte die Marke in der Instanz prüfen (z. B. CUPRA).', 203);
            }
            throw new Exception('Anmeldung abgebrochen, Passwort nicht gesendet: unerwartete Seite nach dem E-Mail-Schritt (' . ($template !== '' ? $template : '?') . ', ' . $this->UrlWithoutQuery($auth['url']) . ').', 203);
        }

        $fields = $step['fields'];
        $fields['email'] = $user;
        $fields['password'] = $password;
        $this->SendDebug('PortalLogin', 'Passwort-Schritt (' . ($template !== '' ? $template : '?') . '): ' . $this->UrlWithoutQuery($authenticateUrl), 0);

        $landing = $this->PortalRequest($ch, 'POST', $authenticateUrl, ['Referer: ' . $auth['url']], $fields);
        if ($landing['error'] !== '' || $landing['code'] >= 400) {
            $error = $this->LoginErrorText($this->ExtractTemplateModel($landing['body']) ?? []);
            throw new Exception('Anmeldung abgelehnt (HTTP ' . $landing['code'] . ')' . ($error !== '' ? ': ' . $this->DescribeLoginError($error) : '') . ' ' . $landing['error'], 203);
        }
        return $landing;
    }

    /**
     * True if the page after the e-mail step is the login password page. Without a template
     * name only a /login/authenticate target outside the registration is accepted.
     */
    private function IsPasswordPage(string $template, string $pageUrl, string $targetUrl): bool
    {
        if ($template !== '') {
            return $template === 'loginAuthenticate';
        }
        return strpos($this->UrlWithoutQuery($targetUrl), '/login/authenticate') !== false
            && strpos($this->UrlWithoutQuery($pageUrl), '/register') === false;
    }

    /**
     * Newer identity login with a single username/password form (see evcc vwidentity.loginNew).
     */
    private function PortalLoginNew($ch, string $html, string $user, string $password): array
    {
        $state = '';
        if (preg_match_all('/<input\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if ($this->HtmlAttr($tag, 'name') === 'state') {
                    $state = (string)$this->HtmlAttr($tag, 'value');
                    break;
                }
            }
        }
        if ($state === '') {
            throw new Exception('Anmeldung: kein Login-Formular erkannt - Login-Seite von VW geändert?', 203);
        }

        $res = $this->PortalRequest($ch, 'POST', self::IDENTITY_BASE . '/u/login?state=' . rawurlencode($state), [], [
            'username' => $user,
            'password' => $password,
            'state'    => $state
        ]);
        if ($res['error'] !== '' || $res['code'] >= 400) {
            throw new Exception('Anmeldung fehlgeschlagen (HTTP ' . $res['code'] . ') ' . $res['error'], 203);
        }
        return $res;
    }

    /**
     * VW periodically interjects an optional marketing consent page after an otherwise
     * successful login. It is skipped without consenting by following its callback.
     */
    private function SkipMarketingConsent($ch, array $res): array
    {
        if (strpos((string)parse_url($res['url'], PHP_URL_PATH), '/consent/marketing/') !== false) {
            parse_str((string)parse_url($res['url'], PHP_URL_QUERY), $query);
            $callback = is_string($query['callback'] ?? null) ? $query['callback'] : '';
        } else {
            $model = $this->ExtractTemplateModel($res['body']);
            if (($model['template'] ?? '') !== 'marketConsent') {
                return $res;
            }
            $callback = is_string($model['callback'] ?? null) ? $model['callback'] : '';
        }
        if ($callback === '') {
            throw new Exception('Marketing-Einwilligung ohne Callback-URL - bitte einmal im Browser im Portal anmelden.', 203);
        }

        $this->SendDebug('PortalLogin', 'Überspringe Marketing-Einwilligung (ohne Zustimmung).', 0);
        return $this->PortalRequest($ch, 'GET', $this->NormalizeUrlQuery($this->ResolveUrl($res['url'], $callback)), ['Referer: ' . $res['url']]);
    }

    /**
     * Login form of an identity page: form action and inputs, with hmac/relayState taken
     * from window._IDK.templateModel and _csrf from csrf_token when present.
     */
    private function LoginFields(string $html): array
    {
        $form = $this->ParseHtmlForm($html, 'emailPasswordForm') ?? $this->ParseHtmlForm($html, null);
        $fields = $form['inputs'] ?? [];
        $model = $this->ExtractTemplateModel($html) ?? [];

        foreach (['hmac', 'relayState'] as $name) {
            if (is_string($model[$name] ?? null) && $model[$name] !== '') {
                $fields[$name] = $model[$name];
            }
        }
        if (($fields['_csrf'] ?? '') === '') {
            $csrf = $this->ExtractCsrf($html);
            if ($csrf !== null) {
                $fields['_csrf'] = $csrf;
            }
        }

        return ['action' => $form['action'] ?? null, 'fields' => $fields, 'model' => $model];
    }

    private function LoginErrorText(array $model): string
    {
        $error = $model['error'] ?? null;
        if (empty($error)) {
            $error = $model['errorCode'] ?? null;
        }
        if (empty($error)) {
            return '';
        }
        if (is_array($error)) {
            $text = $error['text'] ?? $error['errorCode'] ?? null;
            return is_scalar($text) ? (string)$text : (string)json_encode($error);
        }
        return is_scalar($error) ? (string)$error : '';
    }

    /**
     * Readable text for the error codes the identity service reports.
     */
    private function DescribeLoginError(string $code): string
    {
        if (preg_match('/password_invalid/i', $code)) {
            return 'Passwort falsch';
        }
        if (preg_match('/email_invalid|user_id|identifier/i', $code)) {
            return 'E-Mail-Adresse bei VW nicht bekannt';
        }
        if (preg_match('/throttle|rate_limit|too_many/i', $code)) {
            return 'zu viele Fehlversuche, VW sperrt das Konto vorübergehend - ca. 30 Minuten warten';
        }
        if (preg_match('/account_disabled|locked|blocked/i', $code)) {
            return 'VW-Konto gesperrt oder deaktiviert';
        }
        if (preg_match('/tenants?\.?notAuthorized|client_not_allowed/i', $code)) {
            return 'Konto ist nicht für das EU-Data-Act-Portal freigeschaltet - Ersteinrichtung im Browser abschließen';
        }
        return $code;
    }

    /**
     * Action and input values of the form with the given id (null: first form with an
     * action), or null if absent.
     */
    private function ParseHtmlForm(string $html, ?string $id): ?array
    {
        if (!preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $forms)) {
            return null;
        }
        foreach ($forms[0] as $form) {
            if (!preg_match('/^<form\b[^>]*>/i', $form, $open)) {
                continue;
            }
            if ($id !== null && $this->HtmlAttr($open[0], 'id') !== $id) {
                continue;
            }
            $action = $this->HtmlAttr($open[0], 'action');
            if ($action === null) {
                if ($id === null) {
                    continue;
                }
                return null;
            }
            $inputs = [];
            if (preg_match_all('/<input\b[^>]*>/i', $form, $tags)) {
                foreach ($tags[0] as $tag) {
                    $name = $this->HtmlAttr($tag, 'name');
                    if ($name !== null) {
                        $inputs[$name] = (string)$this->HtmlAttr($tag, 'value');
                    }
                }
            }
            return ['action' => $action, 'inputs' => $inputs];
        }
        return null;
    }

    private function HtmlAttr(string $tag, string $name): ?string
    {
        if (!preg_match('/[\s"\']' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $m)) {
            return null;
        }
        $value = $m[1];
        if ($value === '' && isset($m[2]) && $m[2] !== '') {
            $value = $m[2];
        }
        if ($value === '' && isset($m[3])) {
            $value = $m[3];
        }
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Decodes window._IDK.templateModel, which the identity pages embed as plain JSON.
     */
    private function ExtractTemplateModel(string $html): ?array
    {
        $from = strpos($html, 'window._IDK');
        $idx = strpos($html, 'templateModel', $from === false ? 0 : $from);
        if ($idx === false) {
            return null;
        }
        $start = strpos($html, '{', $idx);
        if ($start === false) {
            return null;
        }
        $end = $this->MatchBrace($html, $start);
        if ($end < 0) {
            return null;
        }
        $model = json_decode(substr($html, $start, $end - $start + 1), true);
        return is_array($model) ? $model : null;
    }

    /**
     * Position of the brace closing the object that opens at $start; braces inside string
     * literals are ignored. Returns -1 if unbalanced.
     */
    private function MatchBrace(string $text, int $start): int
    {
        $depth = 0;
        $quote = '';
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $c = $text[$i];
            if ($quote !== '') {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }

    private function ExtractCsrf(string $html): ?string
    {
        return preg_match('/csrf_token\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $html, $m) ? $m[1] : null;
    }

    /**
     * Resolves a (possibly relative) form action or link against the page url.
     */
    private function ResolveUrl(string $base, string $ref): string
    {
        if ($ref === '') {
            return $base;
        }
        if (preg_match('#^https?://#i', $ref)) {
            return $ref;
        }
        $parts = parse_url($base);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https';
        if (strncmp($ref, '//', 2) === 0) {
            return $scheme . ':' . $ref;
        }
        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '/';
        if ($ref[0] === '?') {
            return $origin . $path . $ref;
        }

        $query = '';
        $q = strpos($ref, '?');
        if ($q !== false) {
            $query = substr($ref, $q);
            $ref = substr($ref, 0, $q);
        }
        $joined = $ref[0] === '/' ? $ref : substr($path, 0, (int)strrpos($path, '/') + 1) . $ref;

        $segments = [];
        foreach (explode('/', $joined) as $segment) {
            if ($segment === '..') {
                if (count($segments) > 1) {
                    array_pop($segments);
                }
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        return $origin . implode('/', $segments) . $query;
    }

    /**
     * Re-encodes the query of a url (callback urls may contain raw spaces).
     */
    private function NormalizeUrlQuery(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $query);
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '') . '?' . http_build_query($query);
    }

    /**
     * Url for log output: the query carries session tokens and is dropped.
     */
    private function UrlWithoutQuery(string $url): string
    {
        return explode('?', $url, 2)[0];
    }

    private function RandomString(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $res = '';
        for ($i = 0; $i < $length; $i++) {
            $res .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $res;
    }

    /**
     * Datasets with content, oldest first. While the vehicle is idle the portal emits
     * "..._no_content_found.zip" placeholders; those are skipped.
     */
    private function ContentDatasets(array $list): array
    {
        $content = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = (string)($item['name'] ?? '');
            if ($name === '' || stripos($name, 'no_content_found') !== false) {
                continue;
            }

            $ts = 0;
            if (!empty($item['createdOn'])) {
                $t = strtotime((string)$item['createdOn']);
                if ($t !== false) {
                    $ts = $t;
                }
            }
            if ($ts === 0) {
                $ts = $this->StampFromName($name);
            }
            if ($ts === 0) {
                $this->SendDebug('ContentDatasets', 'Überspringe Datensatz ohne Zeitangabe: ' . $name, 0);
                continue;
            }

            $content[] = ['name' => $name, 'ts' => $ts];
        }

        usort($content, function ($a, $b) {
            return $a['ts'] <=> $b['ts'];
        });

        return $content;
    }

    /**
     * Datasets that still need importing, oldest first. Without a previous import only the
     * newest MAX_BACKFILL datasets are returned.
     */
    private function PendingDatasets(array $content, int $after): array
    {
        if ($after === 0) {
            return array_slice($content, -self::MAX_BACKFILL);
        }
        return array_values(array_filter($content, function ($dataset) use ($after) {
            return $dataset['ts'] > $after;
        }));
    }

    /**
     * Keeps only the newest KEEP_EXPORTS archives in the download folder.
     */
    private function PruneExports(string $dir): void
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.zip');
        if (!is_array($files) || count($files) <= self::KEEP_EXPORTS) {
            return;
        }
        usort($files, function ($a, $b) {
            return $this->ExportSortKey($b) <=> $this->ExportSortKey($a);
        });
        foreach (array_slice($files, self::KEEP_EXPORTS) as $old) {
            @unlink($old);
        }
    }

    /**
     * Main Ingestion & Data Update function
     */
    public function UpdateData()
    {
        $sourceMode = $this->ReadPropertyString('SourceMode');
        if ($sourceMode === 'api') {
            // After a failed login the timer pauses, so repeated attempts cannot get the VW
            // account locked. The button (DownloadAndImport) always tries immediately.
            $failedAt = $this->ReadAttributeInteger('LoginFailedAt');
            if ($failedAt > 0 && time() - $failedAt < self::LOGIN_RETRY_DELAY) {
                $this->SendDebug('UpdateData', 'Automatischer Abruf pausiert nach fehlgeschlagener Anmeldung bis ' . date('H:i', $failedAt + self::LOGIN_RETRY_DELAY) . ' Uhr (Schutz vor Kontosperre).', 0);
                return;
            }
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

        // Determine actual ZIP file path.
        // FIX: sort by the export timestamp encoded in the FILENAME (not by filemtime,
        // which changes on copy/sync/restore and made old exports win), and only accept
        // archives belonging to the configured VIN.
        $actualZipFile = '';
        $wantVin = strtoupper(trim($this->ReadPropertyString('PortalVIN')));

        if (is_dir($filePath)) {
            $zipFiles = glob(rtrim($filePath, '/\\') . '/*.zip');
            if (!empty($zipFiles)) {
                $candidates = [];
                foreach ($zipFiles as $zipCandidate) {
                    $base = basename($zipCandidate);
                    if (strpos($base, 'no_content_found') !== false || filesize($zipCandidate) <= 100) {
                        continue;
                    }
                    if ($wantVin !== '' && strpos(strtoupper($base), $wantVin) === false) {
                        $this->SendDebug('UpdateData', "Ueberspringe fremde VIN: {$base}", 0);
                        continue;
                    }
                    $candidates[] = $zipCandidate;
                }
                if (!empty($candidates)) {
                    usort($candidates, function ($a, $b) {
                        $ka = $this->ExportSortKey($a);
                        $kb = $this->ExportSortKey($b);
                        return $kb <=> $ka;
                    });
                    $actualZipFile = $candidates[0];
                    $this->SendDebug('UpdateData', 'Neuester Export: ' . basename($actualZipFile) . ' (Export-Zeit ' . date('d.m.Y H:i:s', $this->ExportSortKey($actualZipFile)) . ')', 0);
                }
            }
        } elseif (is_file($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'zip') {
            $actualZipFile = $filePath;
        }

        if (empty($actualZipFile) || !file_exists($actualZipFile)) {
            $this->SetStatus(202);
            $this->SendDebug('UpdateData', 'Keine gültige ZIP-Datei gefunden in: ' . $filePath, 0);
            return;
        }

        $this->ProcessZipFile($actualZipFile, $this->StampFromName($actualZipFile));
    }

    /**
     * Delivery time encoded in an export filename, e.g. "20260721121617_VIN.zip".
     * The portal writes this stamp in UTC. Returns 0 if the name carries no stamp.
     */
    private function StampFromName(string $file): int
    {
        if (preg_match('/(?<!\d)(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(?!\d)/', basename($file), $m)) {
            $t = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
            if ($t !== false && $t > 0) {
                return $t;
            }
        }
        return 0;
    }

    /**
     * Sortable export time: the stamp in the filename, else filemtime.
     */
    private function ExportSortKey(string $file): int
    {
        $t = $this->StampFromName($file);
        return $t > 0 ? $t : (int)@filemtime($file);
    }

    /**
     * Process a ZIP file (Memory Extraction & Variable Update).
     * $deliveredAt is the delivery time of the dataset, 0 if unknown.
     */
    private function ProcessZipFile(string $actualZipFile, int $deliveredAt = 0): bool
    {
        // Open ZIP archive in memory
        $zip = new ZipArchive();
        if ($zip->open($actualZipFile) !== true) {
            $this->SetStatus(202);
            $this->SendDebug('ProcessZipFile', 'ZIP-Archiv konnte nicht geöffnet werden: ' . $actualZipFile, 0);
            return false;
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
            return false;
        }

        $payload = json_decode($jsonString, true);
        if (!$payload || !is_array($payload)) {
            $this->SetStatus(202);
            $this->SendDebug('ProcessZipFile', 'JSON-Payload konnte nicht dekodiert werden.', 0);
            return false;
        }

        $this->SetStatus(102);

        // Process telemetry payload
        if (!$this->ProcessPayload($payload, $deliveredAt)) {
            return false;
        }

        // Render HTML Tile Dashboard
        $this->RenderTileVisualization();
        return true;
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
     * Map JSON data into IP-Symcon Status Variables.
     * Datasets can be partial: variables whose fields are missing keep their value.
     */
    private function ProcessPayload(array $payload, int $deliveredAt = 0): bool
    {
        $vin = (string)($payload['vin'] ?? '');

        // FIX: never overwrite this instance's variables with another vehicle's export.
        $wantVin = strtoupper(trim($this->ReadPropertyString('PortalVIN')));
        if ($wantVin !== '' && $vin !== '' && strtoupper($vin) !== $wantVin) {
            $this->SendDebug('ProcessPayload', "Export gehoert zu VIN {$vin}, erwartet {$wantVin} - verworfen.", 0);
            $this->SetStatus(204);
            return false;
        }

        if ($vin !== '') {
            $this->SetValue('VIN', $vin);
        }

        $dataItems = $payload['Data'] ?? [];
        if (!is_array($dataItems)) {
            return false;
        }

        // FIX: the export lists history, not only the current state. The same normalized
        // field name occurs hundreds of times (e.g. powerCurve.[*].timeCurve.[*].soc) and
        // the same 'key' hash is reused for every point of a curve. Plain last-wins picked
        // an arbitrary historical sample. Rank every entry and keep only the newest/highest.
        $fieldMap = [];
        $rankMap  = [];

        // A 'key' is only usable as an alias if every data point carrying it belongs to
        // the same (normalized) field.
        $keyFields = [];
        foreach ($dataItems as $item) {
            if (is_array($item) && isset($item['key'], $item['dataFieldName'])) {
                $keyFields[(string)$item['key']][preg_replace('/\[\d+\]/', '[*]', (string)$item['dataFieldName'])] = true;
            }
        }

        $newestDataTs = 0;

        foreach ($dataItems as $item) {
            if (!is_array($item) || !isset($item['dataFieldName'])) {
                continue;
            }
            $fn  = (string)$item['dataFieldName'];
            $val = $item['value'] ?? null;
            $key = isset($item['key']) ? (string)$item['key'] : '';

            // Rank: real timestamp if present (the export uses 1970-epoch placeholders for
            // curve points), otherwise the largest array index inside the field name.
            $rank = 0;
            $tsRaw = $item['timestampUtc'] ?? '';
            if (is_string($tsRaw) && $tsRaw !== '' && strpos($tsRaw, '1970') !== 0) {
                $t = strtotime($tsRaw);
                // The export also contains garbage stamps such as "N/A" and
                // "+58488-10-05T11:48:29Z"; only accept a plausible window.
                if ($t !== false && $t > 1420070400 && $t < time() + 86400) {
                    $rank = $t;
                    if ($t > $newestDataTs) {
                        $newestDataTs = $t;
                    }
                }
            }
            if ($rank === 0 && preg_match_all('/\[(\d+)\]/', $fn, $mm)) {
                foreach ($mm[1] as $ix) {
                    $rank = max($rank, (int)$ix);
                }
            }

            $this->RankedSet($fieldMap, $rankMap, $fn, $val, $rank);

            $norm = preg_replace('/\[\d+\]/', '[*]', $fn);
            $this->RankedSet($fieldMap, $rankMap, $norm, $val, $rank);

            if ($key !== '' && count($keyFields[$key] ?? []) === 1) {
                $this->RankedSet($fieldMap, $rankMap, $key, $val, $rank);
            }
        }

        // LastUpdate reports the age of the DATA, not the moment of the import: the delivery
        // time of the dataset if known (timestampUtc is unreliable across datasets), else
        // the newest plausible data stamp.
        $this->SetValue('LastUpdate', $deliveredAt > 0 ? $deliveredAt : ($newestDataTs > 0 ? $newestDataTs : time()));

        // System / Metadata
        $isConnected = $fieldMap['isConnected'] ?? null;
        if ($isConnected !== null) {
            $this->SetValue('IsConnected', filter_var($isConnected, FILTER_VALIDATE_BOOLEAN));
        }

        // Cluster 1: Hochvolt-Akku & PV-Laden
        if ($this->ReadPropertyBoolean('EnableHVBattery')) {
            $soc = $this->ExtractFloat($fieldMap, ['hvsoc_info.value', 'batteryStatus.currentSOC_pct', 'battery_state_report.soc', 'hv_soc', 'state_of_charge', 'battery_level_HV.value', '506cb83e-f99f-3af3-bbeb-0429b69a78d9', '0a18a053-b4b0-3db1-be44-a6c5dba629b1']);
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

            $remChargeTime = $this->ExtractFloat($fieldMap, ['remaining_charging_time_complete', 'ChargingEvent.[*].ChargingStatus.[*].remainingChargingTimeToCompleteMin', 'remaining_charging_time']);
            if ($remChargeTime !== null && $remChargeTime < 65535) {
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

            $chargeState = $fieldMap['chargingStatus.currentChargeState'] ?? $fieldMap['charging_state_report.current_charge_state'] ?? '';
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

            $plugState = $fieldMap['plugStatusItem.plugConnectionState'] ?? $fieldMap['plug_state'] ?? '';
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
            // Only touch variables whose field is part of this (possibly partial) dataset.
            $flags = [
                'DoorLockFL'   => ['door_info.front_left.door_lock_status.value', 'LOCKED'],
                'DoorStatusFL' => ['door_info.front_left.door_status.value', 'OPEN'],
                'DoorLockFR'   => ['door_info.front_right.door_lock_status.value', 'LOCKED'],
                'DoorStatusFR' => ['door_info.front_right.door_status.value', 'OPEN'],
                'DoorLockRL'   => ['door_info.rear_left.door_lock_status.value', 'LOCKED'],
                'DoorStatusRL' => ['door_info.rear_left.door_status.value', 'OPEN'],
                'DoorLockRR'   => ['door_info.rear_right.door_lock_status.value', 'LOCKED'],
                'DoorStatusRR' => ['door_info.rear_right.door_status.value', 'OPEN'],
                'TrunkLock'    => ['trunk_lid_info.trunk_lid_lock_status.value', 'LOCKED'],
                'TrunkStatus'  => ['trunk_lid_info.trunk_lid_status.value', 'OPEN'],
                'HoodLock'     => ['hood_info.hood_lock_status.value', 'LOCKED'],
                'HoodStatus'   => ['hood_info.hood_status.value', 'OPEN']
            ];
            foreach ($flags as $ident => [$field, $match]) {
                if (isset($fieldMap[$field])) {
                    $this->SetValue($ident, $fieldMap[$field] === $match);
                }
            }

            $windows = [
                'WindowOpenFL' => 'window_info.front_left.window_percentage_open.value',
                'WindowOpenFR' => 'window_info.front_right.window_percentage_open.value',
                'WindowOpenRL' => 'window_info.rear_left.window_percentage_open.value',
                'WindowOpenRR' => 'window_info.rear_right.window_percentage_open.value'
            ];
            foreach ($windows as $ident => $field) {
                $open = $this->ExtractFloat($fieldMap, [$field]);
                if ($open !== null) {
                    $this->SetValue($ident, $open);
                }
            }

            if (isset($fieldMap['parking_lights_info.left_status.value']) || isset($fieldMap['parking_lights_info.right_status.value'])) {
                $parkingLeft = $fieldMap['parking_lights_info.left_status.value'] ?? 'OFF';
                $parkingRight = $fieldMap['parking_lights_info.right_status.value'] ?? 'OFF';
                $this->SetValue('ParkingLights', $parkingLeft !== 'OFF' || $parkingRight !== 'OFF');
            }
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

            if (isset($fieldMap['envelope.[*].report.windowHeatingState'])) {
                $this->SetValue('WindowHeating', $fieldMap['envelope.[*].report.windowHeatingState'] !== 'OFF');
            }

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
            $mileage = $this->ExtractFloat($fieldMap, ['mileage_info.value', 'inventoryData.[*].odometer', 'mileage', 'mileage.value']);
            if ($mileage !== null) {
                $this->SetValue('MileageKm', $mileage);
            }

            $range = $this->ExtractFloat($fieldMap, ['cruise_range_primary_info.value', 'cruising_range_combined', 'batteryStatus.cruisingRange.range', 'cruising_range_secondary_engine', 'cruising_range_primary_engine', '0ca40e18-0564-3eda-bcc0-7aee9ef44f04']);
            if ($range !== null) {
                $this->SetValue('CruisingRangeKm', $range);
            }

            $serviceDays = $this->ExtractFloat($fieldMap, ['service_maintenance_info.due_in_time.value', 'time to next inspection']);
            if ($serviceDays !== null) {
                $this->SetValue('ServiceInspectionDays', $serviceDays);
            }

            if (array_key_exists('vehicleError.errorDescription', $fieldMap)) {
                $this->SetValue('VehicleErrorDescription', (string)$fieldMap['vehicleError.errorDescription']);
            }

            if (array_key_exists('vehicleError.errorNumber', $fieldMap)) {
                $this->SetValue('VehicleErrorNumber', (int)$fieldMap['vehicleError.errorNumber']);
            }
        }

        return true;
    }

    /**
     * Store a value only if it ranks at least as high (newer / higher index)
     * as whatever is already stored under that name.
     */
    private function RankedSet(array &$fieldMap, array &$rankMap, string $name, $value, int $rank)
    {
        if (!array_key_exists($name, $fieldMap) || $rank >= ($rankMap[$name] ?? PHP_INT_MIN)) {
            $fieldMap[$name] = $value;
            $rankMap[$name]  = $rank;
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
