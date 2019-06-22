<?php
/**
 * @author Luxo
 * @copyright 2014
 * @file
 */

namespace Guc;

use Exception;
use PDO;
use stdClass;

class Main {
    private $app;
    private $user;
    private $actorId;
    private $options;

    private $isIP = false;
    private $ipInfos = array();

    private $datas;
    private $wikis;
    private $centralauthData;

    public static function getDefaultOptions() {
        return array(
            'isPrefixPattern' => false,
            'src' => 'all',
            'by' => 'wiki',
            'includeClosedWikis' => false,
        );
    }

    public function __construct(App $app, $user, $options = array()) {
        $this->app = $app;

        // Normalise
        $this->user = str_replace('_', ' ', ucfirst(trim($user)));

        // Defaults
        $this->options = $options + self::getDefaultOptions();

        if (!$this->user) {
            throw new Exception('No username or IP');
        }

        // Check if input is a pattern
        if ($this->options['isPrefixPattern']) {
            if (strpos($this->user, '_') !== false) {
                throw new Exception('Illegal "_" character found');
            }
            // Pattern search must be prefix-based for performance
            if (substr($this->user, 0, 1) === '%') {
                throw new Exception('Wildcard search can not start with "%".');
            }
            // Hidden feature: User can specify "%" somewhere in the query.
            // Though by default we'll assume a prefix search.

            if (substr($this->user, -1) !== '%') {
                $this->user .= '%';
            }
            $this->app->debug('Perfoming a pattern search: ' . $this->user);
        } else {
            // Check if input is an IP
            if (IPInfo::valid($this->user)) {
                $this->isIP = true;
                $this->addIP($this->user);
            }
        }

        $wikis = $this->getWikis();
        // Filter down wikis to only relevant ones.
        $matchingWikis = $this->reduceWikis($wikis);

        $datas = new stdClass();
        foreach ($matchingWikis as $dbname => $wikiRow) {
            $wiki = Wiki::newFromRow($wikiRow);

            $data = new stdClass();
            $data->wiki = $wiki;
            $data->error = null;
            $data->contribs = null;

            try {
                $caRow = $this->getCentralauthRow($wikiRow->dbname);
                $contribs = new Contribs(
                    $this->app,
                    $this->user,
                    $this->isIP,
                    $wiki,
                    $caRow,
                    $this->options
                );
                if ($this->options['isPrefixPattern'] && !$caRow) {
                    foreach ($contribs->getContribs() as $rc) {
                        // Check before adding because this loop runs for each wiki
                        if (count($this->ipInfos) > 10) {
                            break;
                        }
                        $this->addIP($rc->actor_name);
                    }
                }
                $data->contribs = $contribs;
            } catch (Exception $e) {
                $data->error = $e;
            }
            $datas->$dbname = $data;
        }

        // List of all wikis
        $this->wikis = $wikis;
        // Array of wikis with edit count, keyed by dbname
        $this->matchingWikis = $matchingWikis;
        // Contributions, keyed by dbname
        $this->datas = $datas;
    }

    /**
     * Get meta information about all wikis we want to check.
     *
     * @return stdClass[] List of meta_p rows
     */
    private function getWikis() {
        $this->app->debug('Get list of all wikis');
        $f_where = array();
        if (!$this->options['includeClosedWikis']) {
            $f_where[] = 'is_closed = 0';
        }
        $f_where = implode(' AND ', $f_where);
        $sql = 'SELECT * FROM meta_p.wiki WHERE '.$f_where.' LIMIT 1500;';
        $statement = $this->app->getDB()->prepare($sql);
        $this->app->debug("[SQL] " . preg_replace('#\s+#', ' ', $sql));
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_OBJ);
        $statement = null;
        return $rows;
    }

    /**
     * Reduce list of meta_p to those with one or more matching contributions
     *
     * @param stdClass[] $wikis
     * @return stdClass[] List of meta_p rows
     */
    private function reduceWikis(array $wikis) {
        $matchingWikis = array();

        // Fast path:
        //
        // The input looks a user name, prefix search is off,
        // and we found information in CentralAuth.
        //
        // Do: Reduce list of wikis to those with a local account.
        $caRows = $this->getCentralauthAll();
        if ($caRows) {
            foreach ($wikis as $wiki) {
                if (isset($caRows[$wiki->dbname])) {
                    $matchingWikis[$wiki->dbname] = $wiki;
                }
            }
            return $matchingWikis;
        }

        // Slow path:
        //
        // The input is an IP, or prefix search is used,
        // or we didn't find anything in CentralAuth.
        //
        // Do: Try to find at least 1 matching contribution by user name/IP
        // for the given search pattern.

        // Copied from Contribs::prepareLastHourQuery
        // (TODO: Refactor somehow)
        $cutoff = gmdate(Contribs::MW_DATE_FORMAT, time() - 3600);

        $queriesBySlice = array();
        $wikisByDbname = array();
        foreach ($wikis as $wiki) {
            $wikisByDbname[$wiki->dbname] = $wiki;
            if ($this->options['src'] === 'rc' || $this->options['src'] === 'hr') {
                $sql = '(SELECT
                    1,
                    \''.$wiki->dbname.'\' AS dbname
                    FROM '.$wiki->dbname.'_p.recentchanges_userindex
                    JOIN '.$wiki->dbname.'_p.actor_recentchanges ON actor_id = rc_actor
                    WHERE '.(
                        ($this->options['isPrefixPattern'])
                            ? 'actor_name LIKE :userlike'
                            : 'actor_name = :user'
                    ).(
                        ($this->options['src'] === 'hr')
                            ? ' AND rc_timestamp >= :hrcutoff'
                            : ''
                    // Ignore RC entries for log events and things like
                    // Wikidata and categorization updates
                    ).' AND rc_type IN (' . join(',', array_map(
                        'intval',
                        array(Contribs::MW_RC_EDIT, Contribs::MW_RC_NEW)
                    )) . ')
                    LIMIT 1)';
            } else {
                $sql = '(SELECT
                    1,
                    \''.$wiki->dbname.'\' AS dbname
                    FROM '.$wiki->dbname.'_p.revision_userindex
                    JOIN '.$wiki->dbname.'_p.actor_revision ON actor_id = rev_actor
                    WHERE '.(
                        ($this->options['isPrefixPattern'])
                            ? 'actor_name LIKE :userlike'
                            : 'actor_name = :user'
                    ).'
                    LIMIT 1)';
            }
            $queriesBySlice[$wiki->slice][] = $sql;
        }

        foreach ($queriesBySlice as $sliceName => $queries) {
            if ($queries) {
                $sql = implode(' UNION ALL ', $queries);
                $this->app->debug("Querying wikis on `$sliceName` for matching revisions");
                $pdo = $this->app->getDB($sliceName);
                $statement = $pdo->prepare($sql);
                $this->app->debug("[SQL] " . preg_replace('#\s+#', ' ', $sql));
                if ($this->options['isPrefixPattern']) {
                    $statement->bindParam(':userlike', $this->user);
                } else {
                    $statement->bindParam(':user', $this->user);
                }
                if ($this->options['src'] === 'hr') {
                    $statement->bindValue(':hrcutoff', $cutoff);
                }
                $statement->execute();
                $rows = $statement->fetchAll(PDO::FETCH_OBJ);
                $statement = null;

                foreach ($rows as $row) {
                    $matchingWikis[$row->dbname] = $wikisByDbname[$row->dbname];
                }
            }
        }
        return $matchingWikis;
    }

    /**
     * Get CentralAuth information about a specific wiki.
     *
     * @param string|null $dbname Wiki (Passing null is for internal use only.)
     * @return object|false False means there is no account by the given name
     *  locally on this wiki (including for IP and prefix searches).
     */
    private function getCentralauthRow($dbname) {
        if ($this->isIP || $this->options['isPrefixPattern']) {
            return false;
        }
        if ($this->centralauthData === null) {
            $this->app->debug("Querying CentralAuth database for SUL information");
            $centralauthData = array();
            $pdo = $this->app->getDB('centralauth');
            $sql = 'SELECT
                lu_name,
                lu_wiki,
                lu_attached_timestamp,
                lu_local_id
                FROM centralauth_p.localuser
                WHERE lu_name = :user;';
            $statement = $pdo->prepare($sql);
            $this->app->debug("[SQL] " . preg_replace('#\s+#', ' ', $sql));
            $statement->bindParam(':user', $this->user);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_OBJ);

            $statement = null;
            $pdo = null;
            // Close this connection early, we don't expect to re-use it.
            $this->app->closeDB('centralauth');

            if ($rows) {
                foreach ($rows as $row) {
                    // Normalise
                    $row->lu_name = str_replace('_', ' ', $row->lu_name);

                    $centralauthData[$row->lu_wiki] = $row;
                }
            }
            $this->centralauthData = $centralauthData;
        }
        if ($dbname === null) {
            return $this->centralauthData;
        } elseif (isset($this->centralauthData[$dbname])) {
            return $this->centralauthData[$dbname];
        } else {
            return false;
        }
    }

    /**
     * Get CentralAuth information for any wikis where an account with the given name exists.
     *
     * @return object|false False means there is no account by the given name
     *  locally on this wiki (including for IP and prefix searches).
     */
    private function getCentralauthAll() {
        return $this->getCentralauthRow(null);
    }

    /**
     * Add IP address to IP info map (if not already).
     */
    private function addIP($ip) {
        if (!isset($this->ipInfos[$ip])) {
            $this->ipInfos[$ip] = IPInfo::get($ip);
        }

        return $this->ipInfos[$ip] !== false;
    }

    /**
     * Get collected data grouped by wiki
     *
     * Each entry will contain:
     *
     * - {string} wiki Database name
     * - {null|Exception} error
     * - {null|Contribs} contribs
     *
     * @return stdClass
     */
    public function getData() {
        return $this->datas;
    }

    /**
     * @return int
     */
    public function getWikiCount() {
        return count($this->wikis);
    }

    /**
     * @return int
     */
    public function getResultWikiCount() {
        return count($this->matchingWikis);
    }

    /**
     * Get information about searched IP(s).
     *
     * If IP was not found, or the search for was a user name or pattern,
     * an empty array is returned.
     *
     * @return array
     */
    public function getIPInfos() {
        return $this->ipInfos;
    }
}
