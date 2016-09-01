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

class lb_app {
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
        $this->aTP('Create connection to '.$cluster);
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

        try {
            // Establish connection
            $pdo = new PDO('mysql:host='.$host.';dbname='.$dbname.';', settings::getSetting('user'), settings::getSetting('password'));
        } catch (PDOException $e) {
            throw new lb_Exception('Database error: Unable to connect to '.  $dbname);
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
        if (!$clusterNr) {
            throw new lb_Exception('Invalid DB cluster specification');
        }
        // Bereits vorhanden?
        if (!isset($this->clusters[$clusterNr])) {
            $this->clusters[$clusterNr] = $this->openDB($database, $clusterNr);
        }
        $pdo = $this->clusters[$clusterNr];

        // Select the right database on this cluster server
        $m = $pdo->prepare('USE `'.$database.'_p`;');
        $m->execute();

        return $pdo;
    }

    public function aTP($text) {
        $this->times[] = array(microtime(true), $text);
    }

    public function printTimes() {
        print nl2br(htmlspecialchars($this->getTimes()));
    }

    public function getTimes() {
        $this->aTP('Finish');
        $timebefore = null;
        $first = null;
        $out = '';
        foreach ($this->times as $nr => $data) {
            $diff = ($timebefore === null) ? 0.0 : $data[0] - $timebefore;
            if ($timebefore === null) {
                $first = $data[0];
            }
            $out .= '+' . round($diff, 2) . 's: ' . $data[1] . "\n";

            $timebefore = $data[0];
        }
        $out .= "\nTotal: ".round((microtime(true) - $first), 2)."s.\n";
        $out .= 'Current time: '.date('r');
        return $out;
    }


    /**
     * Take a mediawiki timestamp and returns a unix timestamp
     * @param string $tstime
     * @return DateTime
     */
    public function parseMwDate($tstime) {
        // Based on MWTimestamp::setTimestamp for TS_MW
        $da = array();
        preg_match('/^(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)$/D', $tstime, $da);

        $da = array_map('intval', $da);
        $da[0] = '%04d-%02d-%02dT%02d:%02d:%02d.00+00:00';
        $strtime = call_user_func_array('sprintf', $da);

        return new DateTime($strtime, new DateTimeZone('GMT'));
    }

    /**
     * Take a mediawiki timestamp and convert to a different format
     * @param string $tstime
     * @return string
     */
    public function formatMwDate($tstime, $timeformat = 'H:i, d M Y') {
        if ($tstime === 'infinity') {
            return $tstime;
        }
        $date = $this->parseMwDate($tstime);
        return $date->format($timeformat);
    }

    /**
     * Parse the edit summary of a user contribution
     *
     * Based on MediaWiki 1.25's Linker::formatComment.
     *
     * @param string $comment
     * @param string $page
     * @param string $server
     * @return string HTML
     */
    public function wikiparser($comment, $page, $server)  {
        $comment = str_replace("\n", ' ', $comment);
        $comment = _mwWikitextHtmlEscape($comment);

        // Simplified version of MediaWiki/Linker::formatAutocomments.
        // Parse "/* Section */ Comment" into "<a href=#..>→‎</a>Section: Comment"
        $append = '';
        $comment = preg_replace_callback(
            '!(?:(?<=(.)))?/\*\s*(.*?)\s*\*/(?:(?=(.)))?!',
            function ($match) use ($page, $server, &$append) {
                $match += array('', '', '', '');
                $isPre = $match[1] !== '';
                $auto = $match[2];
                $isPost = $match[3] !== '';
                $link = '';

                $section = $auto;
                // Remove any links
                $section = str_replace('[[:', '', $section);
                $section = str_replace('[[', '', $section);
                $section = str_replace(']]', '', $section);
                // See MediaWiki/Sanitizer::normalizeSectionNameWhitespace() and Language::getArrow()
                $section =  trim(preg_replace('/[ _]+/', ' ', $section));
                $link = '<a href="' . htmlspecialchars(
                    "$server/w/index.php?title=" . htmlspecialchars(_wpurlencode($page)) . "#$section"
                ) . '">→</a>';

                if ($isPost) {
                    // mw-msg: colon-sep
                    $auto .= ':&#32;';
                }
                $auto = '<span class="autocomment">' . $auto . '</span>';
                // See MediaWiki/Language::getDirMark (LEFT-TO-RIGHT MARK)
                // Wrap the rest in a <span>. In order to include uncaptured content we use
                // $append to add the close tag afterwards. But only if there was a match.
                $html = $link . "\xE2\x80\x8E" . '<span dir="auto">' . $auto;
                $append .= '</span>';
                return $html;
            },
            $comment
        );
        $comment .= $append;

        // Simplified version of MediaWiki/Linker::formatLinksInComment.
        // Parse "[[Link]]" into "<a href=..>Linker</a>"
        $comment = preg_replace_callback(
            '/
                \[\[
                :? # ignore optional leading colon
                ([^\]|]+) # 1. link target
                (?:\|
                    # 2. a pipe-separated substring; stop match at | and ]]
                    ((?:]?[^\]|])*+)
                )*
                \]\]
                ([^[]*) # 3. link trail
            /x',
            function ($match) use ($page, $server) {
                $comment = $match[0];
                $text = $match[2] != '' ? $match[2] : $match[1];

                if (isset($match[1][0]) && $match[1][0] === ':') {
                    $match[1] = substr($match[1], 1);
                }
                $target = $match[1];

                $link = '<a href="' . htmlspecialchars("$server/w/index.php?title="
                    . htmlspecialchars(_wpurlencode($target)))
                    . '">' . htmlspecialchars($text) . '</a>';
                $comment = preg_replace(
                    '/\[\[(.*?)\]\]/',
                    _mwPregReplaceEscape($link),
                    $comment,
                    1
                );
                return $comment;
            },
            $comment
        );

        return $comment;
    }

    /**
     * Returns the Name of a Namespace, performs an api request if not already cached.
     * @param int $id
     * @param string $dbName
     * @param string $server
     * @return string Namespace name
     */
    public function getNamespaceName($id, $dbName, $server) {
        static $cache = null;
        $id = intval($id);

        // Initialize cache
        if (!is_array($cache)) {
            if (is_readable(settings::getSetting('cacheFile'))) {
                $cache = json_decode(file_get_contents(settings::getSetting('cacheFile')), true);
            } else {
                $cache = array();
            }
        }

        // Wiki and namespace exist in cache?
        if (isset($cache[$dbName][$id])) {
            return $cache[$dbName][$id];
        }

        // Get information from API
        $apiData = $this->apiRequest($server, array('meta' => 'siteinfo', 'siprop' => 'namespaces'));
        if (!is_array($apiData['query']['namespaces'])) {
            throw new Exception('Unable to retrieve namespaces from '.$server);
        }

        $cache[$dbName] = array();
        foreach ($apiData['query']['namespaces'] as $ns) {
            $cache[$dbName][intval($ns['id'])] = $ns['*'];
        }

        // Save cache
        file_put_contents(settings::getSetting('cacheFile'), json_encode($cache));

        if (!isset($cache[$dbName][$id])) {
            throw new Exception('Unknown namespace number '.$id.' for '.$server);
        }

        // Return namespace name
        return $cache[$dbName][$id];
    }


    /**
     * Performs a api request (post)
     * @param string $server
     * @param array $params
     * @return array or string
     */
    public function apiRequest($server, $params) {
        if (!is_array($params)) {
            throw new Exception('Invalid api parameters.');
        }
        $url = $server.'/w/api.php';
        // Set defaults
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
 * Based on MediaWiki's wfUrlencode()
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

/**
 * Based on MediaWiki 1.25's Sanitizer::escapeHtmlAllowEntities
 *
 * @param string $wikitext
 * @return string HTML
 */
function _mwWikitextHtmlEscape($wikitext) {
    $text = html_entity_decode($wikitext);
    $html = htmlspecialchars($text, ENT_QUOTES);
    return $html;
}

/**
 * Based on MediaWiki 1.25's StringUtils::escapeRegexReplacement
 *
 * @param string $str
 * @return string
 */
function _mwPregReplaceEscape($str) {
    $str = str_replace('\\', '\\\\', $str);
    $str = str_replace('$', '\\$', $str);
    return $str;
}
