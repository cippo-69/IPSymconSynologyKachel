<?php

declare(strict_types=1);

/**
 * Synology Kachel (HTML-SDK)
 *
 * Kachel für eine Synology-NAS auf Basis des Store-Moduls „Synology“ (timo-u):
 * zeigt die Variablen der Instanzen System, Storage, Surveillance Station und UPS
 * und liest über die gemeinsame Synology IO zusätzliche Daten (Modell, Auslastung,
 * Datenträger, Kameras, Home Mode, Pakete, DSM-Update).
 *
 * Bedienung: Aktualisieren, Home Mode, Aufnahme je Kamera, neu anmelden,
 * Neustart und Herunterfahren der NAS (mit Rückfrage).
 * USV-Alarm: Batteriebetrieb und kritischer Ladestand werden an eine Kachel-Visu gemeldet.
 */
class SynologyKachel extends IPSModule
{
    private const IO_GUID = '{F308439D-89E4-5486-74B4-44D498C9CD07}';
    private const SYS_GUID = '{D04449C0-D59B-5C08-90C1-CB00EA0F92D1}';
    private const STO_GUID = '{E94AC765-F5C1-0E77-7870-83F9D7EBFD6F}';
    private const SVS_GUID = '{63071943-5B26-AB52-4311-9ADC12E53F3D}';
    private const UPS_GUID = '{846DBFDC-7BAE-4F37-AA06-7647541B9B9A}';
    private const DATA_GUID = '{59B36CB0-EF4D-D794-FED4-89C69D410CDD}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('SystemID', 0);
        $this->RegisterPropertyInteger('StorageID', 0);
        $this->RegisterPropertyInteger('SurveillanceID', 0);
        $this->RegisterPropertyInteger('UpsID', 0);
        $this->RegisterPropertyInteger('ApiInterval', 60);
        $this->RegisterPropertyString('DsmUrl', '');
        $this->RegisterPropertyBoolean('AllowReboot', true);
        $this->RegisterPropertyBoolean('AllowShutdown', true);
        $this->RegisterPropertyInteger('NotifyVisuID', 0);
        $this->RegisterPropertyInteger('LowCharge', 40);
        $this->RegisterPropertyInteger('LowRuntime', 10);

        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeString('Extra', '{}');
        $this->RegisterAttributeString('Action', '{}');      // letzte Aktion: name, ts, ok
        $this->RegisterAttributeString('Alarm', '{"level":0,"since":0}');
        $this->RegisterAttributeString('PwHash', '');                 // Hash des Passworts für Neustart/Herunterfahren
        $this->RegisterAttributeString('PwFail', '{"n":0,"until":0}');

        $this->RegisterTimer('ApiUpdate', 0, 'SYK_UpdateExtra($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->WatchDesign();

        foreach ((array) json_decode($this->ReadAttributeString('Watched'), true) as $w) {
            $this->UnregisterMessage((int) $w[0], (int) $w[1]);
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        $watch = [];
        foreach ($this->Instances() as $iid) {
            $this->RegisterReference($iid);
            foreach (IPS_GetChildrenIDs($iid) as $vid) {
                if (IPS_VariableExists($vid)) {
                    $this->RegisterMessage($vid, VM_UPDATE);
                    $watch[] = [$vid, VM_UPDATE];
                }
            }
        }
        $io = $this->IO();
        if ($io > 0) {
            $this->RegisterMessage($io, IM_CHANGESTATUS);
            $watch[] = [$io, IM_CHANGESTATUS];
        }
        $this->WriteAttributeString('Watched', json_encode($watch));

        if (!$this->InstOk($this->ReadPropertyInteger('SystemID'), self::SYS_GUID)) {
            $this->SetStatus(201);
        } elseif ($io === 0) {
            $this->SetStatus(202);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
        // Zusatzdaten kurz nach dem Start holen (nicht im ApplyChanges blockieren)
        $this->SetTimerInterval('ApiUpdate', $io > 0 ? 2000 : 0);
        $this->Push();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IM_CHANGESETTINGS) { // Theme-Wechsel in „Visu Design“
            $this->Push();
            return;
        }
        $this->CheckAlarm();
        $this->Push();
    }

    public function ReceiveData($JSONString)
    {
        return '';
    }

    /**
     * Farben und Layout aus dem Modul „Visu Design“ (W5D, ab 1.5); das module.html hat kein eigenes CSS.
     * Das CSS steht im Element #w5d und kommt zusätzlich mit jedem Zustand mit (Feld design), damit ein
     * Theme-Wechsel auch in zwischengespeicherten Kacheln ohne Neuladen ankommt.
     */
    private function DesignStyle(): string
    {
        return '<style id="w5d">' . $this->DesignCSS() . '</style>';
    }

    private function DesignID(): int
    {
        $guid = '{018EEC85-8FFC-4E1F-A966-702C4BBDC2E8}';
        if (!IPS_ModuleExists($guid)) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID($guid);
        sort($ids);
        return $ids[0] ?? 0;
    }

    /** Farben und Kachel-Bausteine aus Visu Design (ab 1.5); die Kachel bringt kein eigenes CSS mit */
    private function DesignCSS(): string
    {
        $id = $this->DesignID();
        if ($id <= 0 || !function_exists('W5D_GetCSS')) {
            return '';
        }
        return W5D_GetCSS($id) . (function_exists('W5D_GetTileCSS') ? W5D_GetTileCSS($id) : '');
    }

    /** Hell/Dunkel-Erkennung (w5tTheme) aus Visu Design */
    private function DesignScript(): string
    {
        $id = $this->DesignID();
        return $id > 0 && function_exists('W5D_GetTileJS') ? '<script>' . W5D_GetTileJS($id) . '</script>' : '';
    }

    /** Theme-Wechsel in „Visu Design“ melden lassen (IM_CHANGESETTINGS → Zustand mit neuem Design-CSS senden). */
    private function WatchDesign(): void
    {
        $id = $this->DesignID();
        if ($id > 0) {
            $this->RegisterMessage($id, IM_CHANGESETTINGS);
        }
    }

    public function GetVisualizationTile()
    {
        $initial = '<script>handleMessage(' . json_encode(json_encode($this->BuildState())) . ');</script>';
        return $this->DesignStyle() . file_get_contents(__DIR__ . '/module.html') . $this->DesignScript() . $initial;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Refresh':
                $this->Act('Aktualisieren', function () {
                    foreach ([['SystemID', 'SYNOSYS_Update'], ['StorageID', 'SYNOSTORAGE_Update'], ['SurveillanceID', 'SYNOSVS_Update'], ['UpsID', 'SYNOUPS_Update']] as [$p, $fn]) {
                        $id = $this->ReadPropertyInteger($p);
                        if ($id > 0 && IPS_InstanceExists($id)) {
                            @$fn($id);
                        }
                    }
                    return $this->UpdateExtra();
                });
                break;
            case 'HomeMode':
                $svs = $this->ReadPropertyInteger('SurveillanceID');
                if ($this->InstOk($svs, self::SVS_GUID)) {
                    $on = (bool) $Value;
                    $this->Act('Home Mode ' . ($on ? 'ein' : 'aus'), fn () => (bool) @SYNOSVS_SetHomeMode($svs, $on));
                    $this->UpdateExtra();
                }
                break;
            case 'Record':
                $svs = $this->ReadPropertyInteger('SurveillanceID');
                $p = json_decode((string) $Value, true);
                if ($this->InstOk($svs, self::SVS_GUID) && is_array($p) && isset($p['id'])) {
                    $cam = (int) $p['id'];
                    $on = (bool) ($p['on'] ?? false);
                    $this->Act('Aufnahme Kamera ' . $cam . ' ' . ($on ? 'starten' : 'stoppen'), fn () => (bool) @SYNOSVS_SetRecording($svs, $cam, $on));
                    $this->UpdateExtra();
                }
                break;
            case 'Login':
                $io = $this->IO();
                if ($io > 0) {
                    $this->Act('Neu anmelden', function () use ($io) {
                        @SYNOIO_Logout($io);
                        return (bool) @SYNOIO_Login($io, true);
                    });
                    $this->UpdateExtra();
                }
                break;
            case 'Reboot':
                $sys = $this->ReadPropertyInteger('SystemID');
                if ($this->ReadPropertyBoolean('AllowReboot') && $this->InstOk($sys, self::SYS_GUID) && $this->CheckPassword('Neustart', $Value)) {
                    $this->LogMessage('Neustart der NAS über die Kachel angefordert', KL_WARNING);
                    $this->Act('Neustart', fn () => (bool) @SYNOSYS_Reboot($sys));
                }
                break;
            case 'Shutdown':
                $sys = $this->ReadPropertyInteger('SystemID');
                if ($this->ReadPropertyBoolean('AllowShutdown') && $this->InstOk($sys, self::SYS_GUID) && $this->CheckPassword('Herunterfahren', $Value)) {
                    $this->LogMessage('Herunterfahren der NAS über die Kachel angefordert', KL_WARNING);
                    $this->Notify('Synology wird heruntergefahren', 'Das Herunterfahren wurde über die Kachel ausgelöst.', 'Warning');
                    $this->Act('Herunterfahren', fn () => (bool) @SYNOSYS_Shutdown($sys));
                }
                break;
            case 'DismissAction':
                $this->WriteAttributeString('Action', '{}');
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->Push();
    }

    /* ---------- Passwort für Neustart/Herunterfahren ---------- */

    /** Setzt das Passwort (gespeichert wird nur der Hash). Leer = Passwort entfernen, dann sind Neustart und Herunterfahren gesperrt. */
    public function SetActionPassword(string $Password): bool
    {
        if ($Password === '') {
            $this->WriteAttributeString('PwHash', '');
            $this->LogMessage('Passwort für Neustart/Herunterfahren entfernt', KL_NOTIFY);
            $this->Push();
            return true;
        }
        if (mb_strlen($Password) < 4) {
            echo "Das Passwort muss mindestens 4 Zeichen haben.\n";
            return false;
        }
        $this->WriteAttributeString('PwHash', password_hash($Password, PASSWORD_DEFAULT));
        $this->WriteAttributeString('PwFail', '{"n":0,"until":0}');
        $this->LogMessage('Passwort für Neustart/Herunterfahren gesetzt', KL_NOTIFY);
        $this->Push();
        return true;
    }

    public function GetConfigurationForm()
    {
        $f = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $set = $this->ReadAttributeString('PwHash') !== '';
        array_unshift($f['actions'], ['type' => 'Label', 'caption' => 'Passwort für Neustart und Herunterfahren: ' . ($set ? 'eingerichtet' : 'NICHT eingerichtet (beides gesperrt)')]);
        return json_encode($f);
    }

    /** Prüft das Passwort aus der Kachel. Nach 5 Fehlversuchen 5 Minuten gesperrt. */
    private function CheckPassword(string $what, $Value): bool
    {
        $p = json_decode((string) $Value, true);
        $pw = is_array($p) ? (string) ($p['pw'] ?? '') : '';
        $hash = $this->ReadAttributeString('PwHash');
        $fail = json_decode($this->ReadAttributeString('PwFail'), true) ?: ['n' => 0, 'until' => 0];
        $msg = '';
        if ($hash === '') {
            $msg = 'kein Passwort eingerichtet';
        } elseif ((int) $fail['until'] > time()) {
            $msg = 'gesperrt bis ' . date('H:i', (int) $fail['until']);
        } elseif (!password_verify($pw, $hash)) {
            $fail['n'] = (int) $fail['n'] + 1;
            $msg = 'Passwort falsch';
            if ($fail['n'] >= 5) {
                $fail = ['n' => 0, 'until' => time() + 300];
                $msg = 'Passwort 5-mal falsch, 5 Minuten gesperrt';
            }
            $this->WriteAttributeString('PwFail', json_encode($fail));
            $this->LogMessage($what . ' der NAS: falsches Passwort in der Kachel', KL_WARNING);
        } else {
            $this->WriteAttributeString('PwFail', '{"n":0,"until":0}');
            return true;
        }
        $this->WriteAttributeString('Action', json_encode(['name' => $what, 'ts' => time(), 'ok' => false, 'msg' => $msg]));
        return false;
    }

    /** Rohabfrage der DSM-API über die IO (zum Testen): z. B. SYK_ApiRaw($id, 'SYNO.Core.System', 'info', '1,2,3', ''). */
    public function ApiRaw(string $Api, string $Method, string $Versions, string $Extra): string
    {
        $r = $this->Api($Api, $Method, $Versions, $Extra === '' ? [] : explode('&', $Extra));
        return json_encode($r);
    }

    /** Liest die Zusatzdaten aus der DSM-API (Timer). */
    public function UpdateExtra(): bool
    {
        $this->SetTimerInterval('ApiUpdate', max(30, $this->ReadPropertyInteger('ApiInterval')) * 1000);
        if ($this->IO() === 0) {
            return false;
        }
        $old = json_decode($this->ReadAttributeString('Extra'), true) ?: [];
        $x = ['ts' => time(), 'lastOk' => (int) ($old['lastOk'] ?? 0)];

        $info = $this->Api('SYNO.Core.System', 'info', '1,2,3');
        $x['ok'] = is_array($info);
        if (!$x['ok']) {
            // alte Daten behalten, nur als nicht erreichbar markieren
            $this->WriteAttributeString('Extra', json_encode(array_merge($old, ['ts' => $x['ts'], 'ok' => false])));
            $this->Push();
            return false;
        }
        $x['lastOk'] = $x['ts'];
        $x['sys'] = [
            'model'   => (string) ($info['model'] ?? ''),
            'ram'     => isset($info['ram_size']) ? (int) $info['ram_size'] : null,
            'cpu'     => trim(implode(' ', array_filter([(string) ($info['cpu_vendor'] ?? ''), (string) ($info['cpu_family'] ?? ''), (string) ($info['cpu_series'] ?? '')]))),
            'cores'   => isset($info['cpu_cores']) ? (int) $info['cpu_cores'] : null,
            'clock'   => isset($info['cpu_clock_speed']) ? (int) $info['cpu_clock_speed'] : null,
            'fw'      => (string) ($info['firmware_ver'] ?? ''),
            'fwDate'  => (string) ($info['firmware_date'] ?? ''),
            'temp'    => isset($info['sys_temp']) ? (int) $info['sys_temp'] : null,
            'tempWarn'=> (bool) ($info['sys_tempwarn'] ?? $info['temperature_warning'] ?? false),
            'uptime'  => $this->UptimeSec($info['up_time'] ?? null)
        ];

        $u = $this->Api('SYNO.Core.System.Utilization', 'get', '1');
        if (is_array($u)) {
            $cpu = (array) ($u['cpu'] ?? []);
            $mem = (array) ($u['memory'] ?? []);
            $net = ['rx' => null, 'tx' => null];
            foreach ((array) ($u['network'] ?? []) as $n) {
                if (($n['device'] ?? '') === 'total') {
                    $net = ['rx' => (int) ($n['rx'] ?? 0), 'tx' => (int) ($n['tx'] ?? 0)];
                }
            }
            $x['util'] = [
                'cpu'  => isset($cpu['user_load']) ? (int) $cpu['user_load'] + (int) ($cpu['system_load'] ?? 0) + (int) ($cpu['other_load'] ?? 0) : null,
                'mem'  => isset($mem['real_usage']) ? (int) $mem['real_usage'] : null,
                'memTotal' => isset($mem['total_real']) ? (int) $mem['total_real'] * 1024 : null,
                'memAvail' => isset($mem['avail_real']) ? (int) $mem['avail_real'] * 1024 : null,
                'disk' => isset($u['disk']['total']['utilization']) ? (int) $u['disk']['total']['utilization'] : null,
                'rx'   => $net['rx'],
                'tx'   => $net['tx']
            ];
        }

        if ($this->InstOk($this->ReadPropertyInteger('StorageID'), self::STO_GUID)) {
            $s = $this->Api('SYNO.Storage.CGI.Storage', 'load_info', '1');
            if (is_array($s)) {
                $x['disks'] = [];
                foreach ((array) ($s['disks'] ?? []) as $d) {
                    $x['disks'][] = [
                        'name'   => (string) ($d['longName'] ?? $d['name'] ?? $d['id'] ?? ''),
                        'model'  => trim((string) ($d['vendor'] ?? '') . ' ' . (string) ($d['model'] ?? '')),
                        'type'   => (string) ($d['diskType'] ?? ''),
                        'size'   => isset($d['size_total']) ? (float) $d['size_total'] : null,
                        'temp'   => isset($d['temp']) ? (int) $d['temp'] : null,
                        'status' => (string) ($d['status'] ?? ''),
                        'smart'  => (string) ($d['smart_status'] ?? ''),
                        'fw'     => (string) ($d['firm'] ?? '')
                    ];
                }
                $x['volumes'] = [];
                foreach ((array) ($s['volumes'] ?? []) as $v) {
                    $x['volumes'][] = [
                        'name'   => (string) ($v['vol_path'] ?? $v['id'] ?? ''),
                        'desc'   => (string) ($v['desc'] ?? ''),
                        'fs'     => (string) ($v['fs_type'] ?? ''),
                        'status' => (string) ($v['status'] ?? ''),
                        'total'  => isset($v['size']['total']) ? (float) $v['size']['total'] : null,
                        'used'   => isset($v['size']['used']) ? (float) $v['size']['used'] : null
                    ];
                }
                $x['pools'] = [];
                foreach ((array) ($s['storagePools'] ?? []) as $p) {
                    $x['pools'][] = [
                        'name'   => (string) ($p['desc'] ?? $p['id'] ?? ''),
                        'raid'   => (string) ($p['device_type'] ?? $p['raidType'] ?? ''),
                        'status' => (string) ($p['status'] ?? ''),
                        'disks'  => count((array) ($p['disks'] ?? []))
                    ];
                }
            }
        }

        if ($this->InstOk($this->ReadPropertyInteger('SurveillanceID'), self::SVS_GUID)) {
            $h = $this->Api('SYNO.SurveillanceStation.HomeMode', 'GetInfo', '1');
            $x['homeMode'] = is_array($h) && isset($h['on']) ? (bool) $h['on'] : null;
            $c = $this->Api('SYNO.SurveillanceStation.Camera', 'List', '9,8,7,6,5,4,3,2,1', ['basic=true']);
            if (is_array($c)) {
                $x['cams'] = [];
                foreach ((array) ($c['cameras'] ?? []) as $cam) {
                    $x['cams'][(string) ($cam['id'] ?? '')] = [
                        'name'    => (string) ($cam['newName'] ?? $cam['name'] ?? ''),
                        'model'   => trim((string) ($cam['vendor'] ?? '') . ' ' . (string) ($cam['model'] ?? '')),
                        'ip'      => (string) ($cam['ip'] ?? $cam['host'] ?? ''),
                        'rec'     => isset($cam['recStatus']) ? (int) $cam['recStatus'] : null,
                        'enabled' => isset($cam['enabled']) ? (bool) $cam['enabled'] : null
                    ];
                }
            }
        }

        $up = $this->Api('SYNO.Core.Upgrade.Server', 'check', '1,2');
        if (is_array($up)) {
            $x['upgrade'] = [
                'available' => (bool) ($up['update']['available'] ?? $up['available'] ?? false),
                'version'   => (string) ($up['update']['version'] ?? $up['version'] ?? '')
            ];
        }

        $pk = $this->Api('SYNO.Core.Package', 'list', '1,2', ['additional=%5B%22status%22%5D']);
        if (is_array($pk)) {
            $x['packages'] = [];
            foreach ((array) ($pk['packages'] ?? []) as $p) {
                $st = (string) ($p['additional']['status'] ?? '');
                $x['packages'][] = ['name' => (string) ($p['name'] ?? $p['id'] ?? ''), 'version' => (string) ($p['version'] ?? ''), 'status' => $st];
            }
            usort($x['packages'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        $ux = $this->Api('SYNO.Core.ExternalDevice.UPS', 'get', '1');
        if (is_array($ux)) {
            $x['ups'] = [
                'model'   => trim(trim((string) ($ux['manufacture'] ?? '')) . ' ' . trim((string) ($ux['model'] ?? ''))),
                'enabled' => isset($ux['enable']) ? (bool) $ux['enable'] : null,
                'mode'    => (string) ($ux['mode'] ?? ''),
                'delay'   => isset($ux['delay_time']) ? (int) $ux['delay_time'] : null   // -1 = bis Batterie schwach, sonst Minuten
            ];
        }

        $this->WriteAttributeString('Extra', json_encode($x));
        $this->Push();
        return true;
    }

    /* ---------- USV-Alarm ---------- */

    /** 0 = Netzbetrieb, 1 = Batteriebetrieb, 2 = kritisch (Ladestand/Restlaufzeit unter Schwelle oder lowbatt) */
    private function UpsLevel(array $u): int
    {
        if ($u === [] || $u['state'] === '') {
            return 0;
        }
        $st = strtolower($u['state']);
        $onBatt = strpos($st, 'onbatt') !== false || strpos($st, 'lowbatt') !== false || strpos($st, 'battery') !== false;
        if (!$onBatt) {
            return 0;
        }
        $crit = strpos($st, 'lowbatt') !== false
            || ($u['charge'] !== null && $u['charge'] < $this->ReadPropertyInteger('LowCharge'))
            || ($u['runtime'] !== null && $u['runtime'] > 0 && $u['runtime'] < $this->ReadPropertyInteger('LowRuntime'));
        return $crit ? 2 : 1;
    }

    private function CheckAlarm(): void
    {
        $u = $this->UpsVars();
        $lvl = $this->UpsLevel($u);
        $sem = 'SYK_Alarm_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 2000)) {
            return;
        }
        try {
            $a = json_decode($this->ReadAttributeString('Alarm'), true) ?: ['level' => 0, 'since' => 0];
            $old = (int) $a['level'];
            if ($lvl === $old) {
                return;
            }
            $a = ['level' => $lvl, 'since' => time()];
            $this->WriteAttributeString('Alarm', json_encode($a));
        } finally {
            IPS_SemaphoreLeave($sem);
        }
        $info = 'Ladestand ' . ($u['charge'] === null ? '–' : round($u['charge']) . ' %') . ', Restlaufzeit ' . ($u['runtime'] === null ? '–' : $u['runtime'] . ' min');
        if ($lvl === 2) {
            $this->LogMessage('USV kritisch: ' . $info, KL_ERROR);
            $this->Notify('Synology: USV fast leer', $info . '. NAS jetzt herunterfahren? Kachel öffnen.', 'Alert');
        } elseif ($lvl === 1 && $old === 0) {
            $this->LogMessage('Stromausfall, USV im Batteriebetrieb: ' . $info, KL_WARNING);
            $this->Notify('Synology: Stromausfall', 'USV im Batteriebetrieb. ' . $info . '.', 'Warning');
        } elseif ($lvl === 0) {
            $this->LogMessage('USV wieder im Netzbetrieb: ' . $info, KL_NOTIFY);
            $this->Notify('Synology: Strom wieder da', 'USV wieder im Netzbetrieb. ' . $info . '.', 'Info');
        }
    }

    private function Notify(string $title, string $text, string $type): void
    {
        $visu = $this->ReadPropertyInteger('NotifyVisuID');
        if ($visu <= 0 || !IPS_InstanceExists($visu)) {
            return;
        }
        // Tippen auf die Meldung öffnet die Kachel (Instanz), sonst ohne Ziel
        if (@VISU_PostNotification($visu, $title, $text, $type, $this->InstanceID) === false) {
            if (@VISU_PostNotification($visu, $title, $text, $type, 0) === false) {
                $this->LogMessage('Meldung an Visu ' . $visu . ' fehlgeschlagen: ' . $title, KL_WARNING);
            }
        }
    }

    /* ---------- Hilfen ---------- */

    private function Act(string $name, callable $fn): void
    {
        $ok = false;
        try {
            $ok = (bool) $fn();
        } catch (Throwable $e) {
            $this->SendDebug('Aktion', $name . ': ' . $e->getMessage(), 0);
        }
        $this->WriteAttributeString('Action', json_encode(['name' => $name, 'ts' => time(), 'ok' => $ok]));
        $this->SendDebug('Aktion', $name . ' => ' . ($ok ? 'OK' : 'Fehler'), 0);
    }

    /** DSM-API über die Synology IO; liefert apidata->data als Array oder null */
    private function Api(string $api, string $method, string $versions, array $extra = []): ?array
    {
        $io = $this->IO();
        if ($io === 0 || !$this->HasActiveParent()) {
            return null;
        }
        $v = @SYNOIO_GetMaxVersion($io, $api, $versions);
        if ($v === false || $v === null) {
            $this->SendDebug('API', $api . ': keine passende Version', 0);
            return null;
        }
        $param = ['subpath' => '/webapi/entry.cgi', 'getparameter' => array_merge(['api=' . $api, 'version=' . $v, 'method=' . $method], $extra)];
        try {
            $r = $this->SendDataToParent(json_encode(['DataID' => self::DATA_GUID, 'Parameter' => $param]));
        } catch (Throwable $e) {
            $this->SendDebug('API', $api . ': ' . $e->getMessage(), 0);
            return null;
        }
        $d = json_decode((string) $r, true);
        if (!is_array($d) || !($d['apidata']['success'] ?? false)) {
            $this->SendDebug('API', $api . '.' . $method . ' v' . $v . ': ' . json_encode($d['apidata'] ?? $r), 0);
            return null;
        }
        return (array) ($d['apidata']['data'] ?? []);
    }

    private function UptimeSec($t): ?int
    {
        if (is_numeric($t)) {
            return (int) $t;
        }
        if (is_string($t) && preg_match('/^(\d+):(\d+):(\d+)$/', $t, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }
        return null;
    }

    private function IO(): int
    {
        $c = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        return $c > 0 && IPS_InstanceExists($c) && IPS_GetInstance($c)['ModuleInfo']['ModuleID'] === self::IO_GUID ? $c : 0;
    }

    private function InstOk(int $id, string $guid): bool
    {
        return $id > 0 && @IPS_InstanceExists($id) && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === $guid;
    }

    private function Instances(): array
    {
        $r = [];
        foreach ([['SystemID', self::SYS_GUID], ['StorageID', self::STO_GUID], ['SurveillanceID', self::SVS_GUID], ['UpsID', self::UPS_GUID]] as [$p, $g]) {
            $id = $this->ReadPropertyInteger($p);
            if ($this->InstOk($id, $g)) {
                $r[$p] = $id;
            }
        }
        return $r;
    }

    private function V(int $iid, string $ident)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $iid);
        return $vid !== false && IPS_VariableExists($vid) ? GetValue($vid) : null;
    }

    private function UpsVars(): array
    {
        $ups = $this->ReadPropertyInteger('UpsID');
        if (!$this->InstOk($ups, self::UPS_GUID)) {
            return [];
        }
        $c = $this->V($ups, 'Charge');
        $r = $this->V($ups, 'Runtime');
        return [
            'state'   => (string) ($this->V($ups, 'UpsState') ?? ''),
            'charge'  => $c === null ? null : (float) $c,
            'runtime' => $r === null ? null : (int) $r,
            'nas'     => $this->V($ups, 'ConnectionState'),
            'ups'     => $this->V($ups, 'UpsConnectionState')
        ];
    }

    private function Push(): void
    {
        $this->UpdateVisualizationValue(json_encode(['design' => $this->DesignCSS()] + $this->BuildState()));
    }

    private function BuildState(): array
    {
        $sys = $this->ReadPropertyInteger('SystemID');
        if (!$this->InstOk($sys, self::SYS_GUID)) {
            return ['error' => 'Keine Synology-System-Instanz ausgewählt'];
        }
        $io = $this->IO();
        $s = [
            'error'   => '',
            'now'     => time(),
            'io'      => $io > 0 ? IPS_GetInstance($io)['InstanceStatus'] : 0,
            'dsm'     => $this->DsmUrl(),
            'canReboot'   => $this->ReadPropertyBoolean('AllowReboot'),
            'canShutdown' => $this->ReadPropertyBoolean('AllowShutdown'),
            'pwSet'   => $this->ReadAttributeString('PwHash') !== '',
            'pwLock'  => (int) (json_decode($this->ReadAttributeString('PwFail'), true)['until'] ?? 0),
            'action'  => json_decode($this->ReadAttributeString('Action'), true) ?: null,
            'x'       => json_decode($this->ReadAttributeString('Extra'), true) ?: null,
            'sys'     => [
                'online'  => $this->V($sys, 'State'),
                'since'   => ($vid = @IPS_GetObjectIDByIdent('State', $sys)) ? IPS_GetVariable($vid)['VariableChanged'] : 0,
                'cpu'     => $this->V($sys, 'CpuLoad5Min'),
                'mem'     => $this->V($sys, 'MemoryPercent'),
                'temp'    => $this->V($sys, 'SystemTemperature'),
                'fw'      => $this->V($sys, 'FirmwareVersion'),
                'upgrade' => $this->V($sys, 'UpgradeReady'),
                'uptimeH' => $this->V($sys, 'Uptime'),
                'crashed' => $this->V($sys, 'SystemCrashed'),
                'rx'      => $this->V($sys, 'NetworkTotalRx'),
                'tx'      => $this->V($sys, 'NetworkTotalTx')
            ],
            'storage' => null,
            'cams'    => null,
            'ups'     => null
        ];

        $sto = $this->ReadPropertyInteger('StorageID');
        if ($this->InstOk($sto, self::STO_GUID)) {
            $list = [];
            foreach (IPS_GetChildrenIDs($sto) as $vid) {
                if (!IPS_VariableExists($vid)) {
                    continue;
                }
                $o = IPS_GetObject($vid);
                $list[] = ['ident' => $o['ObjectIdent'], 'name' => $o['ObjectName'], 'value' => GetValue($vid), 'fmt' => GetValueFormatted($vid)];
            }
            $s['storage'] = $list;
        }

        $svs = $this->ReadPropertyInteger('SurveillanceID');
        if ($this->InstOk($svs, self::SVS_GUID)) {
            $cams = [];
            foreach (IPS_GetChildrenIDs($svs) as $vid) {
                $o = IPS_GetObject($vid);
                if (!IPS_VariableExists($vid) || !preg_match('/^CameraState(\d+)$/', $o['ObjectIdent'], $m)) {
                    continue;
                }
                $name = preg_replace('/^Kamera:\s*\d+\s*\((.*)\)$/u', '$1', $o['ObjectName']);
                $cams[] = ['id' => (int) $m[1], 'name' => $name, 'state' => GetValue($vid), 'fmt' => GetValueFormatted($vid)];
            }
            usort($cams, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
            $s['cams'] = $cams;
        }

        $u = $this->UpsVars();
        if ($u !== []) {
            $a = json_decode($this->ReadAttributeString('Alarm'), true) ?: ['level' => 0, 'since' => 0];
            $u['level'] = $this->UpsLevel($u);
            $u['alarmSince'] = (int) $a['since'];
            $u['lowCharge'] = $this->ReadPropertyInteger('LowCharge');
            $u['lowRuntime'] = $this->ReadPropertyInteger('LowRuntime');
            $s['ups'] = $u;
        }
        return $s;
    }

    private function DsmUrl(): string
    {
        $u = trim($this->ReadPropertyString('DsmUrl'));
        if ($u === '' && $this->IO() > 0) {
            $u = (string) @IPS_GetProperty($this->IO(), 'Url');   // Adresse ohne Zugangsdaten
        }
        return preg_match('#^https?://[^\s@]+$#i', $u) ? $u : '';
    }
}
