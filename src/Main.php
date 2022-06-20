<?php

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
    private $matchingWikis;

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
        $statement = $this->app->getDB('meta')->prepare($sql);
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
        // Fast path:
        //
        // The input looks a user name, prefix search is off,
        // and we found information in CentralAuth.
        //
        // Do: Reduce list of wikis to those with a local account.
        $caRows = $this->getCentralauthAll();
        if ($caRows) {
            $matchingWikis = array();
            $placeholdersByWiki = array();
            foreach ($wikis as $wiki) {
                if (isset($caRows[$wiki->dbname])) {
                    $matchingWikis[$wiki->dbname] = $wiki;
                    $placeholdersByWiki[$wiki->dbname] = [
                        'user_id' => $caRows[$wiki->dbname]->lu_local_id,
                    ];
                }
            }
            $subQuery = 'SELECT
                1,
                :dbname AS dbname
                FROM {dbname}_p.user
                WHERE user_id = :user_id
                AND user_editcount >= 1
                LIMIT 1';

            return $this->doBigUnionReduce(
                $matchingWikis,
                $subQuery,
                'with non-zero editcount',
                $placeholdersByWiki
            );
        }

        // Slow path:
        //
        // The input is an IP, or prefix search is used,
        // or we didn't find anything in CentralAuth.
        //
        // Do: Try to find at least 1 matching contribution by user name/IP
        // for the given search pattern.

        if ($this->options['src'] === 'rc' || $this->options['src'] === 'hr') {
            $subQuery = 'SELECT
                1,
                :dbname AS dbname
                FROM {dbname}_p.recentchanges_userindex
                JOIN {dbname}_p.actor_recentchanges ON actor_id = rc_actor
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
                LIMIT 1';
        } else {
            $subQuery = 'SELECT
                1,
                :dbname AS dbname
                FROM {dbname}_p.revision_userindex
                JOIN {dbname}_p.actor_revision ON actor_id = rev_actor
                WHERE '.(
                    ($this->options['isPrefixPattern'])
                        ? 'actor_name LIKE :userlike'
                        : 'actor_name = :user'
                ).'
                LIMIT 1';
        }

        return $this->doBigUnionReduce(
            $wikis,
            $subQuery,
            'with matching revisions'
        );
    }

    /**
     * @param stdClass[] $wikis List of meta_p rows
     * @param string $subQuery Query that does "SELECT 1 … LIMIT 1",
     *  which may use "{dbname}" (raw) or ":dbname" (quoted) as placeholders per-wiki,
     *  and also ":userlike", ":user" and ":hrcutoff" as placeholders globally.
     * @param string $subjectLabel
     * @param array[] $placeholdersByWiki
     * @return stdClass[] Reduced list of meta_p rows
     */
    private function doBigUnionReduce(array $wikis, $subQuery, $subjectLabel, $placeholdersByWiki = array()) {
        $resultsByWiki = array();

        // Copied from Contribs::prepareLastHourQuery
        // (TODO: Refactor somehow)
        $cutoff = gmdate(Contribs::MW_DATE_FORMAT, time() - 3600);

        $queriesBySlice = array();
        $wikiRowsByDbname = array();
        foreach ($wikis as $wiki) {
            $wikiRowsByDbname[$wiki->dbname] = $wiki;
            $queriesBySlice[$wiki->slice][$wiki->dbname] = $subQuery;
        }

        foreach ($queriesBySlice as $sliceName => $queries) {
            // Expand placeholders.
            // This would be nicer to do in the previous loop, but getting the PDO
            // for each slice multiple times and potentially out of order can be costly.
            // So do it here instead.
            $pdo = $this->app->getDB($sliceName);
            foreach ($queries as $dbname => &$query) {
                $placeholders = array(
                    '{dbname}' => $dbname,
                    ':dbname' => $pdo->quote($dbname),
                );
                if (isset($placeholdersByWiki[$dbname])) {
                    foreach ($placeholdersByWiki[$dbname] as $key => $value) {
                        $placeholders[':' . $key] = $pdo->quote($value);
                    }
                }
                $query = '('
                    . strtr($query, $placeholders)
                    . ')';
            }

            $sql = implode(' UNION ALL ', $queries);
            $this->app->debug("Finding wikis on `$sliceName` $subjectLabel");
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
                $resultsByWiki[$row->dbname] = $wikiRowsByDbname[$row->dbname];
            }
        }

        return $resultsByWiki;
    }

    /**
     * Get CentralAuth information about a specific wiki.
     *
     * @param string|null $dbname Wiki (Passing null is for internal use only.)
     * @return stdClass|false|stdClass[] False means there is no account by the given name
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
     * @return object[]|false False means there is no account by the given name
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
