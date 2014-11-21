<?php
/**
 * Copyright 2014 by Luxo
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

class lb_app
{
    private $times = array();
    private $clusters = array();

    /**
     * Open a connection to the database.
     *
     * @param string $database
     * @param int|string $cluster
     * @return PDO
     */
    private function openDB($database = 'wikidatawiki', $cluster = null) {
        $this->aTP('Open new Connection to '.$cluster);
        if (is_string($database)) {
            $host = $database.'.labsdb';
            $dbname = $database.'_p';
        }
        if (is_int($cluster)) {
            $host = 's'.$cluster.'.labsdb';
            $dbname = (is_string($database)) ? $database.'_p': 'information_schema';
        }

        if (is_string($cluster)) {
            $host = $cluster;
            $dbname = (is_string($database)) ? $database.'_p': 'information_schema';
        }

        //Verbindung aufbauen
        try {
            $pdo = new PDO('mysql:host='.$host.';dbname='.$dbname.';', settings::getSetting('user'), settings::getSetting('password'));
        } catch (PDOException $e) {
            throw new lb_Exception("Database error! can't connect to ".  htmlspecialchars($dbname));
        }
        return $pdo;
    }

    /**
     * Gibt die Verbindung zu einem Wiki zurück (cache)
     *
     * @param string $database
     * @param $clusterNr
     * @return PDO
     */
    public function getDB($database = 'meta', $clusterNr = 's1.labsdb') {
        $con = null;

        if (!$clusterNr) {
            throw new lb_Exception("try to open DB without cluster specification");
        }
        // Bereits vorhanden?
        if (key_exists($clusterNr, $this->clusters)) {
            $con = $this->clusters[$clusterNr];
        } else {
            // Datenbankverbindung öffnen
            $con = $this->openDB($database, $clusterNr);
        }
        // Datenbank auswählen
        $m = $con->prepare('USE `'.$database.'_p`;');
        $m->execute();
        unset($m);

        // Verbindung ablegen
        $this->clusters[$clusterNr] = $con;

        return $con;
    }

    public function aTP($text) {
        $this->times[] = array(microtime(true), $text);
    }

    public function printTimes() {
        $this->aTP("Finish");
        $timebefore = null;
        $first = null;
        foreach ($this->times as $nr => $data) {

            $diff = ($timebefore === null) ? 0.0 : $data[0] - $timebefore;
            if ($timebefore === null) {
                $first = $data[0];
            }
            print('+' . round($diff, 2) . 's: ' . $data[1] . '<br />');

            $timebefore = $data[0];
        }
        print('<br />Allover:'.round((microtime(true) - $first), 2).'s.<br />');
        print('the time now is '.date('r'));
    }


    /**
     * Take a mediawiki timestamp and returns a unix timestamp
     * @param string $tstime
     * @return int unixtimestamp
     */
    public function TsToUnixTime($tstime) {
        $regYear  = substr($tstime, 0, 4);
        $regMonth = substr($tstime, 4, 2);
        $regDay   = substr($tstime, 6, 2);
        $regHour  = substr($tstime, 8, 2);
        $regMin   = substr($tstime, 10, 2);
        $regSec   = substr($tstime, 12, 2);

        return mktime($regHour, $regMin, $regSec, $regMonth, $regDay, $regYear);

    }

    /**
     * returns a formated timestamp
     * @param string $tstime
     * @param array $months
     * @return string
     */
    public function TStoUserTime($tstime, $timeformat, $months) {
        if ($tstime == "infinity") {
            return $tstime;
        } else {
            $regYear = substr($tstime, 0, 4);
            $regMonth = substr($tstime, 4, 2);

            $tempdates = array("01", "02", "03", "04", "05", "06", "07", "08", "09", "10", "11", "12");
            $regMonthW = str_replace($tempdates, $months, $regMonth);

            $regDay = substr($tstime, 6, 2);
            $regHour = substr($tstime, 8, 2);
            $regMin = substr($tstime, 10, 2);
            $regSec = substr($tstime, 12, 2);

            /*  variables:
            Y = year,   eg. "2008"
            m = month,  eg. "02"
            F = month,  eg. "February"
            d = day,    eg. "13"
            H = hour,   eg. "15"
            i = minute, eg. "08"
            s = second, eg. "10"
            */

            return str_replace(array('Y','m','F','d','H','i','s'), array($regYear, $regMonth, $regMonthW, $regDay, $regHour, $regMin, $regSec), $timeformat);


        }
    }

    /**
     * parses the summary of contributions
     * @param type $sum
     * @param type $page
     * @param type $project
     * @return type
     */
    public function wikiparser($sum, $page, $project)
    {

        $page = htmlspecialchars($page, ENT_QUOTES);
        $sum = htmlspecialchars($sum);
        //   Sektionen /* SEKTION */ parsen
        $sum =preg_replace("/\/\*\s(.*)\s\*\/(.*)$/e", "'<span class=\'autocomment\'><a href=\'//$project/w/index.php?title=$page#'._wpsectdecode('\\1').'\'>→</a> \\1'.((strlen(trim('$2'))>2)?':':'').'</span>'", $sum);

        //Interne Links parsen
        //[[LINK]]
        //nichts ersetzen falls es ein | enthält
        $sum =preg_replace("/\[\[([^\|\[\]]*)\]\]/e", "'<a href=\'//$project/w/index.php?title='._wpurldecode('\\1').'\'>\\1</a>'", $sum);

        //[LINK|BESCHREIBUNG]]
        $sum =preg_replace("/\[\[([^\|\[\]]*)\|{1}([^\|\[\]]*)\]\]/e", "'<a href=\'//$project/w/index.php?title='._wpurldecode('\\1').'\'>\\2</a>'", $sum);

        return $sum;
    }

    /**
     * Returns the Name of a Namespace, performs a api request if not already cached.
     * @staticvar string $cache
     * @param int $id
     * @param string $dbName
     * @param string $url
     * @return string Namespace name
     */
    public function getNamespaceName($id, $dbName, $url) {
        static $cache = '';
        $id = intval($id);

        // Initialize cache
        if (!is_array($cache)) {
            if (is_readable(settings::getSetting('cacheFile'))) {
                $cache = json_decode(file_get_contents(settings::getSetting('cacheFile')), true);
            } else {
                $cache = array();
            }
        }

        // Wiki exist in cache?
        if (key_exists($dbName, $cache)) {
            // Namespace exist in cache?
            if (key_exists($id, $cache[$dbName])) {
                return $cache[$dbName][$id];
            }
        }

        // Get information from API
        $apiData = $this->apiRequest($url, array('meta' => 'siteinfo', 'siprop' => 'namespaces'));
        if (!is_array($apiData['query']['namespaces'])) {
            throw new Exception('Error on namespace resolution for '.$url);
        }

        $cache[$dbName] = array();
        foreach ($apiData['query']['namespaces'] as $ns) {
            $cache[$dbName][intval($ns['id'])] = $ns['*'];
        }

        //save cache
        file_put_contents(settings::getSetting('cacheFile'), json_encode($cache));

        //return namespace name
        if (!key_exists($dbName, $cache) || !key_exists($id, $cache[$dbName])) {
            throw new Exception('Unknown namespace numer '.$id.' for '.$url);
        }
        return $cache[$dbName][$id];
    }


    /**
     * Performs a api request (post)
     * @param string $url
     * @param array $params
     * @return array or string
     */
    public function apiRequest($url, $params) {
        if (!is_array($params)) {
            throw new Exception('invalid api parameters.');
        }
        $url = 'https://'.$url.'/w/api.php';
        //set defaults
        if (!key_exists('format', $params)) {
            $params['format'] = 'json';
        }
        if (!key_exists('action', $params)) {
            $params['action'] = 'query';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        curl_close($ch);
        if ($params['format'] == 'json') {
            return json_decode($data, true);
        } else {
            return $data;
        }
    }
}


// Funktionen innerhalb von regexp = global.....
/**
 * Decode section links to mediawiki's comical code
 * @param string $str
 * @return stromg
 */
function _wpsectdecode($str){
    $str = trim($str);
    $str = str_replace(" ", "_", $str);
    $str = urlencode($str);
    $str = ucwords($str);
    $str = str_replace("%", ".", $str);
    return $str;
}


/**
 * Decode Mediawiki url's
 * @param string $str
 * @return string
 */
function _wpurldecode($str) {
    $str = trim($str);
    $str = str_replace(" ", "_", $str);
    return ucwords($str);
}

/**
 * Based on mediawiki-core's wfUrlencode()
 *
 * @param string $pageName
 * @return string
 */
function _wpurlencode($pageName) {
    static $needle = null;
    if ($needle === null) {
        $needle = array('%3B', '%40', '%24', '%21', '%2A', '%28', '%29', '%2C', '%2F', '%3A');
    }
    return str_ireplace(
        $needle,
        array(';', '@', '$', '!', '*', '(', ')', ',', '/', ':'),
        urlencode(str_replace(' ', '_', $pageName))
    );
}
