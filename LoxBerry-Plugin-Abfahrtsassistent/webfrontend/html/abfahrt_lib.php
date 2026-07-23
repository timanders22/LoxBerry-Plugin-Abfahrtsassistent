<?php
/**
 * Abfahrts-Assistent - gemeinsame Bibliothek
 *
 * Kalender (bis zu 10 iCal-URLs) + Verkehrslage (TomTom / Google / HERE)
 * -> naechster Termin mit Ortsangabe, aktuelle Fahrzeit, Abfahrts-Countdown.
 *
 * Keine persoenlichen Daten im Code - alles kommt aus der Plugin-Konfiguration
 * ($LBHOMEDIR/config/plugins/<plugin>/abfahrt.json), die ueber die
 * Admin-Oberflaeche gepflegt wird.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

function abfahrt_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(dirname(__DIR__, 1));
    // Public html dir: .../webfrontend/html/plugins/<plugindir>/ -> plugin name is folder name
    $self = basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $self)) {
        $plugindir = $self;
    }
    if ($lbhomedir) {
        return [
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/abfahrt.json',
            'tmp' => '/tmp/abfahrtsassistent',
        ];
    }
    // Fallback (Entwicklung/Test): relativ zum Skript
    return [
        'config' => dirname(__DIR__, 2) . '/config/abfahrt.json',
        'tmp' => sys_get_temp_dir() . '/abfahrtsassistent',
    ];
}

function abfahrt_config() {
    $p = abfahrt_paths();
    $abfcfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: []) : [];
    // Defaults
    $abfcfg += [
        'calendars' => [],
        'provider' => 'tomtom',
        'api_key' => '',
        'home_address' => '',
        'buffer_min' => 10,
        'arrival_min' => 10,
        'lookahead_hours' => 15,
        'ignore_locations' => 'online, teams, zoom, webex, google meet, skype, videokonferenz, telefontermin',
        'tts' => [],
        'notify' => [],
        'quiet' => [],
    ];
    $abfcfg['notify'] += ['audio' => 1, 'push' => 1];
    for ($d = 1; $d <= 7; $d++) {
        if (!isset($abfcfg['quiet'][$d]) || !is_array($abfcfg['quiet'][$d])) {
            $abfcfg['quiet'][$d] = [];
        }
        $abfcfg['quiet'][$d] += ['on' => 0, 'from' => '20:00', 'to' => '07:00'];
    }
    $abfcfg['tts'] += [
        'mode' => 'musicserver',
        'ip' => '',
        'port' => 7091,
        'zones' => '1~25',
        'volume' => 20,
        'lang' => 'de',
        'template' => '',
    ];
    return $abfcfg;
}

function abfahrt_tmpdir() {
    $p = abfahrt_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    return $p['tmp'];
}

function abfahrt_http_get($url, $timeout = 12) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'LoxBerry Abfahrts-Assistent',
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'LoxBerry Abfahrts-Assistent']]);
    return @file_get_contents($url, false, $ctx);
}


/** Liegt "jetzt" in der Audio-Sperrzeit des heutigen Wochentags? */
function abfahrt_in_quiet(array $abfcfg, &$info = '') {
    $d = (int) date('N'); // 1 = Montag ... 7 = Sonntag
    $q = $abfcfg['quiet'][$d] ?? null;
    if (!$q || empty($q['on'])) {
        return false;
    }
    $now = (int) date('H') * 60 + (int) date('i');
    $p = function ($s) { $x = explode(':', (string) $s); return ((int) ($x[0] ?? 0)) * 60 + (int) ($x[1] ?? 0); };
    $from = $p($q['from']);
    $to = $p($q['to']);
    $in = ($from <= $to) ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    if ($in) {
        $info = 'Sperrzeit ' . $q['from'] . '-' . $q['to'] . ' Uhr';
    }
    return $in;
}

/** Audio-Freigabe (Checkbox + Sperrzeit). */
function abfahrt_audio_allowed(array $abfcfg, &$why = '') {
    if (empty($abfcfg['notify']['audio'])) {
        $why = 'Audioausgabe deaktiviert (Plugin-Einstellung)';
        return false;
    }
    $info = '';
    if (abfahrt_in_quiet($abfcfg, $info)) {
        $why = $info;
        return false;
    }
    return true;
}



/** Ist die Ortsangabe ein Online-/Video-Termin (keine Fahrt noetig)? */
function abfahrt_loc_ignored($loc, array $abfcfg) {
    $loc = trim((string) $loc);
    if ($loc === '') {
        return false;
    }
    if (preg_match('#^https?://#i', $loc)) {
        return true; // Meeting-Link statt Adresse
    }
    foreach (explode(',', (string) ($abfcfg['ignore_locations'] ?? '')) as $kw) {
        $kw = trim($kw);
        if ($kw === '') {
            continue;
        }
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/ui', $loc)) {
            return true;
        }
    }
    return false;
}

/* ---------------- Logging ---------------- */

function abfahrt_logfile() {
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $self = basename(__DIR__);
    if ($lbhomedir) {
        $dir = $lbhomedir . '/log/plugins/' . $self;
    } else {
        $dir = sys_get_temp_dir() . '/abfahrtsassistent';
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/abfahrt.log';
}

function abfahrt_log($msg) {
    $f = abfahrt_logfile();
    if (is_file($f) && filesize($f) > 512000) { // Rotation: letzte 200 Zeilen behalten
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/* ---------------- iCal ---------------- */

/** ICS einer Kalender-URL holen (10 Minuten Cache). */
function abfahrt_fetch_ics($url) {
    $cache = abfahrt_tmpdir() . '/ics_' . md5($url);
    if (is_file($cache) && time() - filemtime($cache) < 600) {
        return (string) file_get_contents($cache);
    }
    $neu = abfahrt_http_get($url);
    if ($neu !== false && strpos($neu, 'BEGIN:VCALENDAR') !== false) {
        file_put_contents($cache, $neu);
        return $neu;
    }
    return is_file($cache) ? (string) file_get_contents($cache) : false;
}

/**
 * DTSTART/EXDATE/RECURRENCE-ID-Rohwert -> Unix-ts (null bei Ganztages-/Parsefehler).
 */
function abfahrt_dt2ts($raw, $tzid) {
    $raw = trim($raw);
    if (strlen($raw) == 8) {
        return null; // reines Datum (ganztaegig)
    }
    if (substr($raw, -1) == 'Z') {
        return strtotime($raw);
    }
    try {
        $tz = $tzid ? new DateTimeZone($tzid) : new DateTimeZone('Europe/Berlin');
    } catch (Exception $e) {
        $tz = new DateTimeZone('Europe/Berlin');
    }
    $d = DateTime::createFromFormat('Ymd\THis', $raw, $tz);
    return $d ? $d->getTimestamp() : null;
}

/**
 * Alle Kalender parsen: naechster zukuenftiger Termin MIT Ortsangabe im
 * Zeitfenster. Serientermine werden vollstaendig expandiert:
 * RRULE FREQ=DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, BYDAY (woechentlich),
 * UNTIL, COUNT; EXDATE; verschobene/geloeschte Einzel-Instanzen einer Serie
 * (RECURRENCE-ID / STATUS:CANCELLED). Zeitzonen-/DST-sicher via DateTime.
 * Rueckgabe: [ts, location, summary, calendar_name] oder null.
 */
function abfahrt_next_event(array $abfcfg, &$diag = []) {
    $now = time();
    $maxTs = $now + max(1, (int) $abfcfg['lookahead_hours']) * 3600;
    $WD = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $best = null;

    foreach ($abfcfg['calendars'] as $cal) {
        $url = trim((string) ($cal['url'] ?? ''));
        $name = trim((string) ($cal['name'] ?? ''));
        if ($url === '') {
            continue;
        }
        $ics = abfahrt_fetch_ics($url);
        if ($ics === false) {
            $diag[] = "Kalender '$name': nicht ladbar";
            continue;
        }
        $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // Zeilenfaltung aufloesen

        $singles = [];    // [ts, loc, sum]
        $masters = [];
        $overridden = []; // "uid|origTs" => 1
        foreach (explode('BEGIN:VEVENT', $ics) as $i => $ev) {
            if ($i === 0) {
                continue;
            }
            if (!preg_match('/DTSTART(?:;TZID=([^:;]+))?(?:;VALUE=DATE)?:([0-9TZ]+)/', $ev, $md)) {
                continue;
            }
            $tzid = $md[1];
            $ts = abfahrt_dt2ts($md[2], $tzid);
            $uid = '';
            if (preg_match('/UID:([^\r\n]+)/', $ev, $mu)) {
                $uid = trim($mu[1]);
            }
            $sum = '';
            if (preg_match('/SUMMARY(?:;[^:]*)?:([^\r\n]+)/', $ev, $ms)) {
                $sum = trim(str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $ms[1]));
            }
            $loc = '';
            if (preg_match('/LOCATION:([^\r\n]+)/', $ev, $ml)) {
                $loc = trim(str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $ml[1]));
            }
            if ($loc !== '' && abfahrt_loc_ignored($loc, $abfcfg)) {
                $loc = ''; // Online-/Videotermin: keine Fahrzeitberechnung
            }
            $cancelled = (bool) preg_match('/STATUS:CANCELLED/', $ev);

            // Verschobene/geloeschte Einzel-Instanz einer Serie
            if (preg_match('/RECURRENCE-ID(?:;TZID=([^:;]+))?(?:;VALUE=DATE)?:([0-9TZ]+)/', $ev, $mr)) {
                $orig = abfahrt_dt2ts($mr[2], $mr[1] ?: $tzid);
                if ($orig !== null) {
                    $overridden[$uid . '|' . $orig] = 1;
                }
                if (!$cancelled && $ts !== null) {
                    $singles[] = [$ts, $loc, $sum];
                }
                continue;
            }
            if ($cancelled || $ts === null) {
                continue;
            }

            if (preg_match('/RRULE:([^\r\n]+)/', $ev, $mrr)) {
                $ex = [];
                if (preg_match_all('/EXDATE(?:;TZID=([^:;]+))?(?:;VALUE=DATE)?:([^\r\n]+)/', $ev, $me, PREG_SET_ORDER)) {
                    foreach ($me as $e) {
                        foreach (explode(',', trim($e[2])) as $v) {
                            $x = abfahrt_dt2ts($v, $e[1] ?: $tzid);
                            if ($x !== null) {
                                $ex[$x] = 1;
                            }
                        }
                    }
                }
                $masters[] = ['uid' => $uid, 'ts' => $ts, 'tzid' => $tzid ?: 'Europe/Berlin',
                              'loc' => $loc, 'sum' => $sum, 'rrule' => trim($mrr[1]), 'ex' => $ex];
            } else {
                $singles[] = [$ts, $loc, $sum];
            }
        }

        // Serien expandieren (Vorkommen im Fenster jetzt..maxTs)
        foreach ($masters as $mst) {
            $r = [];
            foreach (explode(';', $mst['rrule']) as $kv) {
                $p = explode('=', $kv, 2);
                if (count($p) == 2) {
                    $r[strtoupper($p[0])] = strtoupper(trim($p[1]));
                }
            }
            $freq = $r['FREQ'] ?? '';
            if ($freq === '') {
                continue;
            }
            $iv = max(1, (int) ($r['INTERVAL'] ?? 1));
            $until = null;
            if (isset($r['UNTIL'])) {
                $until = abfahrt_dt2ts($r['UNTIL'], null);
                if ($until === null) {
                    $until = strtotime(substr($r['UNTIL'], 0, 8) . ' 23:59:59');
                }
            }
            $count = isset($r['COUNT']) ? (int) $r['COUNT'] : null;
            try {
                $tz = new DateTimeZone($mst['tzid']);
            } catch (Exception $e) {
                $tz = new DateTimeZone('Europe/Berlin');
            }
            $start = (new DateTime('@' . $mst['ts']))->setTimezone($tz);

            $byday = [];
            if ($freq == 'WEEKLY') {
                foreach (explode(',', $r['BYDAY'] ?? '') as $d) {
                    $d = preg_replace('/[^A-Z]/', '', $d);
                    if (isset($WD[$d])) {
                        $byday[$WD[$d]] = 1;
                    }
                }
                if (!$byday) {
                    $byday[(int) $start->format('N')] = 1;
                }
            }

            $emitted = 0;
            if ($freq == 'DAILY' || $freq == 'WEEKLY') {
                $wkRef = clone $start;
                $wkRef->modify('monday this week')->setTime(12, 0, 0);
                $cur = clone $start;
                $iter = 0;
                while ($iter++ < 40000) {
                    $ts = $cur->getTimestamp();
                    if ($freq == 'DAILY') {
                        $days = (int) $start->diff($cur)->format('%a');
                        $okDay = ($days % $iv) == 0;
                    } else {
                        $wk = clone $cur;
                        $wk->modify('monday this week')->setTime(12, 0, 0);
                        $weeks = (int) round(((int) $wkRef->diff($wk)->format('%a')) / 7);
                        $okDay = isset($byday[(int) $cur->format('N')]) && ($weeks % $iv) == 0;
                    }
                    if ($okDay && $ts >= $mst['ts']) {
                        $emitted++;
                        if ($count !== null && $emitted > $count) {
                            break;
                        }
                        if ($until !== null && $ts > $until) {
                            break;
                        }
                        if ($ts > $now && $ts <= $maxTs && !isset($mst['ex'][$ts]) && !isset($overridden[$mst['uid'] . '|' . $ts])) {
                            $singles[] = [$ts, $mst['loc'], $mst['sum']];
                        }
                    }
                    if ($ts > $maxTs || ($until !== null && $ts > $until)) {
                        break;
                    }
                    $cur->modify('+1 day');
                }
            } elseif ($freq == 'MONTHLY' || $freq == 'YEARLY') {
                $step = ($freq == 'MONTHLY') ? 'month' : 'year';
                for ($k = 0; $k < 1200; $k++) {
                    $occ = clone $start;
                    if ($k > 0) {
                        $occ->modify('+' . ($k * $iv) . ' ' . $step);
                    }
                    $ts = $occ->getTimestamp();
                    $emitted++;
                    if ($count !== null && $emitted > $count) {
                        break;
                    }
                    if ($until !== null && $ts > $until) {
                        break;
                    }
                    if ($ts > $maxTs) {
                        break;
                    }
                    if ($ts > $now && !isset($mst['ex'][$ts]) && !isset($overridden[$mst['uid'] . '|' . $ts])) {
                        $singles[] = [$ts, $mst['loc'], $mst['sum']];
                    }
                }
            }
        }

        $count2 = 0;
        foreach ($singles as $s) {
            list($ts, $loc, $sum) = $s;
            if ($ts <= $now || $ts > $maxTs || $loc === '') {
                continue;
            }
            $count2++;
            if ($best === null || $ts < $best[0]) {
                $best = [$ts, $loc, $sum, $name];
            }
        }
        $diag[] = "Kalender '$name': $count2 Termin(e) mit Ort im Zeitfenster";
    }
    return $best;
}

/* ---------------- Routing (Verkehrslage) ---------------- */

/** Adresse -> "lat,lon" (dauerhafter Cache je Provider+Adresse). */
function abfahrt_geocode($address, array $abfcfg, &$err = '') {
    $key = $abfcfg['api_key'];
    $provider = $abfcfg['provider'];
    $cache = abfahrt_tmpdir() . '/geo_' . md5($provider . '|' . $address);
    if (is_file($cache)) {
        return trim((string) file_get_contents($cache));
    }
    $pos = null;
    if ($provider === 'tomtom') {
        $url = 'https://api.tomtom.com/search/2/geocode/' . rawurlencode($address) . '.json?key=' . rawurlencode($key) . '&limit=1&countrySet=DE,AT,CH';
        $g = @json_decode((string) abfahrt_http_get($url), true);
        if (isset($g['results'][0]['position'])) {
            $pos = $g['results'][0]['position']['lat'] . ',' . $g['results'][0]['position']['lon'];
        }
    } elseif ($provider === 'here') {
        $url = 'https://geocode.search.hereapi.com/v1/geocode?q=' . rawurlencode($address) . '&apiKey=' . rawurlencode($key);
        $g = @json_decode((string) abfahrt_http_get($url), true);
        if (isset($g['items'][0]['position'])) {
            $pos = $g['items'][0]['position']['lat'] . ',' . $g['items'][0]['position']['lng'];
        }
    } elseif ($provider === 'google') {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode($address) . '&key=' . rawurlencode($key);
        $g = @json_decode((string) abfahrt_http_get($url), true);
        if (isset($g['results'][0]['geometry']['location'])) {
            $l = $g['results'][0]['geometry']['location'];
            $pos = $l['lat'] . ',' . $l['lng'];
        }
    }
    if ($pos === null) {
        $err = "Geocoding fehlgeschlagen ($provider) fuer: $address";
        return false;
    }
    file_put_contents($cache, $pos);
    return $pos;
}

/**
 * Aktuelle Fahrzeit (Minuten, inkl. Verkehr) von der Abfahrtsadresse zum Ziel.
 * 5 Minuten Cache je Ziel.
 */
function abfahrt_route_minutes($destAddress, array $abfcfg, &$err = '') {
    $cache = abfahrt_tmpdir() . '/route_' . md5($abfcfg['provider'] . '|' . $destAddress);
    if (is_file($cache) && time() - filemtime($cache) < 300) {
        return (float) file_get_contents($cache);
    }
    $key = $abfcfg['api_key'];
    $provider = $abfcfg['provider'];
    $minutes = false;

    if ($provider === 'google') {
        // Google akzeptiert Adressen direkt (kein separates Geocoding noetig)
        $url = 'https://maps.googleapis.com/maps/api/directions/json?origin=' . rawurlencode($abfcfg['home_address'])
             . '&destination=' . rawurlencode($destAddress)
             . '&mode=driving&departure_time=now&traffic_model=best_guess&key=' . rawurlencode($key);
        $r = @json_decode((string) abfahrt_http_get($url), true);
        if (isset($r['routes'][0]['legs'][0])) {
            $leg = $r['routes'][0]['legs'][0];
            $sec = $leg['duration_in_traffic']['value'] ?? ($leg['duration']['value'] ?? null);
            if ($sec !== null) {
                $minutes = round($sec / 60, 1);
            }
        }
        if ($minutes === false) {
            $err = 'Google-Routing fehlgeschlagen' . (isset($r['status']) ? ' (' . $r['status'] . ')' : '');
        }
    } else {
        $home = abfahrt_geocode($abfcfg['home_address'], $abfcfg, $err);
        if ($home === false) {
            return false;
        }
        $dest = abfahrt_geocode($destAddress, $abfcfg, $err);
        if ($dest === false) {
            return false;
        }
        if ($provider === 'tomtom') {
            $url = 'https://api.tomtom.com/routing/1/calculateRoute/' . $home . ':' . $dest . '/json?key=' . rawurlencode($key) . '&traffic=true&travelMode=car';
            $r = @json_decode((string) abfahrt_http_get($url), true);
            if (isset($r['routes'][0]['summary']['travelTimeInSeconds'])) {
                $minutes = round($r['routes'][0]['summary']['travelTimeInSeconds'] / 60, 1);
            } else {
                $err = 'TomTom-Routing fehlgeschlagen';
            }
        } elseif ($provider === 'here') {
            $url = 'https://router.hereapi.com/v8/routes?transportMode=car&origin=' . $home . '&destination=' . $dest . '&return=summary&apikey=' . rawurlencode($key);
            $r = @json_decode((string) abfahrt_http_get($url), true);
            if (isset($r['routes'][0]['sections'][0]['summary']['duration'])) {
                $minutes = round($r['routes'][0]['sections'][0]['summary']['duration'] / 60, 1);
            } else {
                $err = 'HERE-Routing fehlgeschlagen';
            }
        } else {
            $err = 'Unbekannter Kartendienst: ' . $provider;
        }
    }
    if ($minutes !== false) {
        file_put_contents($cache, $minutes);
    }
    return $minutes;
}

/* ---------------- TTS ---------------- */

/** TTS-URL fuer die konfigurierte Ausgabe bauen. Fuer mode=audioserver: null. */
function abfahrt_tts_url($text, array $tts) {
    $mode = $tts['mode'];
    if ($mode === 'audioserver') {
        return null; // Original Loxone Audioserver: TTS nur ueber Loxone Config (Textgenerator -> TTS-Eingang)
    }
    if ($mode === 'musicserver') {
        // Zonenliste normalisieren: "2,4,6" + Lautstaerke-Feld -> "2~25,4~25,6~25".
        // Explizite Angaben "Zone~Lautstaerke" haben Vorrang.
        $vol = max(1, min(100, (int) $tts['volume']));
        $zones = [];
        foreach (explode(',', (string) $tts['zones']) as $z) {
            $z = trim($z);
            if ($z === '') {
                continue;
            }
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    // ms4h (MusicServer4Home / Audioserver4Home) und custom: Vorlage mit Platzhaltern
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') {
        // Standard-Vorlage MusicServer4Home
        $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}';
    }
    return str_replace(
        ['{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'],
        [$tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)],
        $tpl
    );
}
