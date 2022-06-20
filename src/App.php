<?php

namespace Guc;

use DateTime;
use DateTimeZone;
use Exception;
use PDO;
use PDOException;

class App {
    const FLD_PDO = 0;
    const FLD_HOST = 1;

    private $times = array();
    private $openDbCount = 0;
    private $maxConSeen = 0;

    /** Map of host name to IP */
    private $hostIPs = array();

    /**
     * Map of IP to hostnames we gave connections for.
     * This is used as basic ref-counting approach to only close DBs once we
     * know it is truly intended to do so. E.g. if host A and B are the same IP,
     * and closeDB('A') is called, but getDB('B') was also called, then we only
     * close the connection once closeDB('B') is also called.
     */
    private $ipHostsInUse = array();

    /** Map of IP to PDO and original hostname */
    private $connections = array();

    /**
     * @param string $host
     * @return string|false
     */
    protected function getHostIp($host) {
      return @gethostbyname($host);
    }

    /**
     * Open a connection to the database.
     *
     * @param string $host
     * @param string|null $dbname
     * @return PDO
     */
    protected function openDB($host, $dbname = null) {
        $this->debug('Create connection to ' . $host);

        try {
            // Establish a connection
            $dbFrag = $dbname === null ? '' : ('dname=' . $dbname . ';');
            $pdo = new PDO('mysql:host='.$host.';' . $dbFrag, Settings::getSetting('user'), Settings::getSetting('password'));
        } catch (PDOException $e) {
            throw new Exception('Database error: Unable to connect to '.  $host);
        }

        return $pdo;
    }

    /**
     * @param string $cluster
     * @return string
     * @throws Exception Invalid DB cluster
     */
    private function normaliseHost($cluster = 's1') {
        if (!is_string($cluster)) {
            throw new Exception('Invalid DB cluster specification');
        }
        if (strpos($cluster, '.') === false) {
            // Default suffix
            $host = "{$cluster}.labsdb";
        } else {
            $host = $cluster;
        }

        // FIXME: Workaround https://phabricator.wikimedia.org/T176686
        $host = preg_replace(
            '/\.labsdb$/',
            '.web.db.svc.eqiad.wmflabs',
            $host
        );

        return $host;
    }

    /**
     * Get a connection to a database (cached)
     *
     * @param string $cluster
     * @param string|null $database
     * @return PDO
     * @throws Exception Invalid DB cluster
     */
    public function getDB($cluster = 's1', $database = null) {
        $host = $this->normaliseHost($cluster);
        $dbname = $database === null ? null : "{$database}_p";

        // Resolve hostname to IP address to reduce connections, because
        // the Wiki Replicas in Wikimedia Cloud, while logically represented
        // with hostnames matching production clusters, their replicas are
        // more consolidated.
        // - Fixes https://phabricator.wikimedia.org/T186436
        // - Works around https://phabricator.wikimedia.org/T186675
        if (!isset($this->hostIPs[$host])) {
          $this->hostIPs[$host] = $this->getHostIp($host) ?: $host;
        }
        $ip = $this->hostIPs[$host];

        // Reuse existing connection if possible
        if (!isset($this->connections[$ip])) {
            $this->connections[$ip] = [
              self::FLD_PDO => $this->openDB($host, $dbname),
              self::FLD_HOST => $host
            ];
            $this->openDbCount++;
            $this->maxConSeen = max($this->maxConSeen, count($this->connections));
        }
        $pdo = $this->connections[$ip][self::FLD_PDO];

        // Record ip con as in-use for host
        $this->ipHostsInUse[$ip][$host] = true;

        // Select the right database on this host
        if ($dbname !== null) {
          $statement = $pdo->prepare('USE `' . $dbname . '`;');
          $statement->execute();
          $statement = null;
        }

        return $pdo;
    }

    /**
     * @param string $cluster Cluster name, host name or IP
     * @throws Exception Invalid DB cluster
     */
    public function closeDB($cluster = 's1') {
        $host = $this->normaliseHost($cluster);
        $ip = $this->hostIPs[$host] ?? $host;

        unset($this->ipHostsInUse[$ip][$host]);

        if (isset($this->connections[$ip]) && !$this->ipHostsInUse[$ip]) {
            $this->debug('Close connection to ' . $host);
            unset($this->connections[$ip]);
        }
    }

    public function closeAllDBs() {
        foreach ($this->connections as $pair) {
            $this->debug('Close remaining connection for ' . $pair[self::FLD_HOST]);
        }
        $this->ipHostsInUse = [];
        $this->connections = [];
    }

    public function preShutdown() {
      // Try to close any remaining connections
      $this->closeAllDBs();
      $this->debug('Connections opened: ' . intval($this->openDbCount));
      $this->debug('Highest connection count: ' . intval($this->maxConSeen));
    }

    public function debug($text) {
        $this->times[] = array(microtime(true), $text);
    }

    public function printTimes() {
        print htmlspecialchars($this->getTimes());
    }

    public function getTimes() {
        $this->debug('Finish');
        $start = $_SERVER['REQUEST_TIME_FLOAT'];
        $end = microtime(true);
        $out = "* Starting PHP process for web request\n";
        $previous = $start;
        foreach ($this->times as $nr => $data) {
            $diff = round($data[0] - $previous, 2);
            $previous = $data[0];
            if ($diff > 0.0) {
              $out .= '  ⤷ +' . $diff . 's' . "\n";
            }
            $out .= '* ' . $data[1] . "\n";

            $timebefore = $data[0];
        }
        $out .= "\nTotal backend time: " . round($end - $start, 2) . "s.\n";
        $out .= 'Current time: ' . date('r');
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
        $comment = Wiki::wikitextHtmlEscape($comment);

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
                // See MediaWiki/Sanitizer::escapeId() – called by Linker::makeCommentLink(),
                // via LinkerRenderer, via Title::escapeFragmentForURL().
                $link = '<a href="' . htmlspecialchars(
                    "$server/w/index.php?title=" . Wiki::urlencode($page) .
                    "#" . Wiki::escapeId($section)
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
                    . Wiki::urlencode($target))
                    . '">' . htmlspecialchars($text) . '</a>';
                $comment = preg_replace(
                    '/\[\[(.*?)\]\]/',
                    Wiki::pregReplaceEscape($link),
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
        file_put_contents(
            settings::getSetting('cacheFile'),
            json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

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
     * @return array
     */
    public function apiRequest($server, $params) {
        if (!is_array($params)) {
            throw new Exception('Invalid api parameters.');
        }
        $url = $server.'/w/api.php';
        $params['format'] = 'json';
        // Set defaults
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
        return json_decode($data, true);
    }
}
