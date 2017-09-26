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

namespace Guc;

use Exception;
use PDO;
use stdClass;

class Main {
    private $app;
    private $user;
    private $options;

    private $isIP = false;
    private $ipInfos = array();
    private $globalEditCount = 0;

    private $data;
    private $wikis;

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
        $this->options = $options += self::getDefaultOptions();

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
            $this->app->aTP('Perfoming a pattern search: ' . $this->user);
        } else {
            // Check if input is an IP
            if (IPInfo::valid($this->user)) {
                $this->isIP = true;
                $this->addIP($this->user);
            }
        }

        $wikis = $this->getWikis();
        // Filter down wikis to only relevant ones.
        // Attaches 'wiki._editcount' property.
        $wikisWithEditcount = $this->getWikisWithContribs($wikis);

        $datas = new stdClass();
        foreach ($wikisWithEditcount as $dbname => $wikiRow) {
            $wiki = Wiki::newFromRow($wikiRow);

            $data = new stdClass();
            $data->wiki = $wiki;
            $data->error = null;
            $data->contribs = null;

            try {
                $contribs = new Contribs(
                    $this->app,
                    $this->user,
                    $this->isIP,
                    $wiki,
                    $wikiRow->_editcount,
                    $this->getCentralauthData($wikiRow->dbname),
                    $options
                );
                if ($this->options['isPrefixPattern'] && !$contribs->getRegisteredUsers()) {
                    foreach ($contribs->getContribs() as $rc) {
                        // Check before adding because this loop runs for each wiki
                        if (count($this->ipInfos) > 10) {
                            break;
                        }
                        $this->addIP($rc->rev_user_text);
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
        $this->wikisWithEditcount = $wikisWithEditcount;
        // Contributions, keyed by dbname
        $this->datas = $datas;
    }

    /**
     * Get all wikis
     * @return array of objects
     */
    private function getWikis() {
        $this->app->aTP('Get list of all wikis');
        $family = array(
            'wikipedia' => 1,
            'wikibooks' => 1,
            'wiktionary' => 1,
            'special' => 1,
            'wikiquote' => 1,
            'wikisource' => 1,
            'wikimedia' => 1,
            'wikinews' => 1,
            'wikiversity' => 1,
            'centralauth' => 0,
            'wikivoyage' => 1,
            'wikidata' => 1,
            'wikimania' => 1
        );
        $f_where = array();
        if (!$this->options['includeClosedWikis']) {
            $f_where[] = 'is_closed = 0';
        }
        foreach ($family as $name => $value) {
            if ($value !== 1) {
                $f_where[] = '`family` != \''.$name.'\'';
            }
        }
        $f_where = implode(' AND ', $f_where);
        $sql = 'SELECT * FROM `meta_p`.`wiki` WHERE '.$f_where.' LIMIT 1500;';
        $statement = $this->app->getDB()->prepare($sql);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * return the wikis with contribs
     * @param array $wikis List of meta_p rows
     * @return array
     */
    private function getWikisWithContribs(array $wikis) {
        $this->app->aTP('Query all wikis for matching revisions');
        $wikisWithEditcount = array();

        // Copied from Contribs::prepareLastHourQuery
        // (TODO: Refactor somehow)
        $cutoff = gmdate(Contribs::MW_DATE_FORMAT, time() - 3600);

        $slices = array();
        $wikisByDbname = array();
        foreach ($wikis as $wiki) {
            $wikisByDbname[$wiki->dbname] = $wiki;
            if ($this->options['src'] === 'rc' || $this->options['src'] === 'hr') {
                $sql = 'SELECT
                    COUNT(*) AS counter,
                    \''.$wiki->dbname.'\' AS dbname
                    FROM '.$wiki->dbname.'_p.recentchanges_userindex
                    WHERE '.(
                        ($this->options['isPrefixPattern'])
                            ? 'rc_user_text LIKE :userlike'
                            : 'rc_user_text = :user'
                    ).(
                        ($this->options['src'] === 'hr')
                            ? ' AND rc_timestamp >= :hrcutoff'
                            : ''
                    // Ignore RC entries for log events and things like
                    // Wikidata and categorization updates
                    ).' AND `rc_type` IN (' . join(',', array_map(
                        'intval',
                        array(Contribs::MW_RC_EDIT, Contribs::MW_RC_NEW)
                    )) . ')';
            } else {
                $sql = 'SELECT
                    COUNT(rev_id) AS counter,
                    \''.$wiki->dbname.'\' AS dbname
                    FROM '.$wiki->dbname.'_p.revision_userindex
                    WHERE '.(
                        ($this->options['isPrefixPattern'])
                            ? 'rev_user_text LIKE :userlike'
                            : 'rev_user_text = :user'
                    );
            }
            $slices[$wiki->slice][] = $sql;
        }

        $globalEditCount = 0;
        foreach ($slices as $sliceName => $queries) {
            if ($queries) {
                $sql = implode(' UNION ALL ', $queries);
                $pdo = $this->app->getDB('meta', $sliceName);
                $statement = $pdo->prepare($sql);
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
                foreach ($rows as $row) {
                    $wiki = $wikisByDbname[$row->dbname];
                    $wiki->_editcount = intval($row->counter);
                    if ($row->counter > 0) {
                        $globalEditCount += $row->counter;
                        $wikisWithEditcount[$row->dbname] = $wiki;
                    }
                }
            }
        }
        $this->globalEditCount = $globalEditCount;
        return $wikisWithEditcount;
    }

    /**
     * Get centralauth information
     * @staticvar null $centralauthData
     * @param string $dbname
     * @return object|null|bool False means CentralAuth query failed
     *  or the user is an IP or pattern. Null means we're dealing with a
     *  proper user name but the account is not attached on this wiki.
     */
    private function getCentralauthData($dbname) {
        static $centralauthData = null;
        if ($this->isIP || $this->options['isPrefixPattern']) {
            return false;
        }
        if ($centralauthData === null) {
            $centralauthData = array();
            $pdo = $this->app->getDB('centralauth', 'centralauth');
            $statement = $pdo->prepare('SELECT * FROM localuser WHERE lu_name = :user;');
            $statement->bindParam(':user', $this->user);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_OBJ);
            if (!$rows) {
                return false;
            }
            foreach ($rows as $row) {
                $centralauthData[$row->lu_wiki] = $row;
            }
        }
        if (!isset($centralauthData[$dbname])) {
            return null;
        }
        return $centralauthData[$dbname];
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
     * @return array[]
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
        return count($this->wikisWithEditcount);
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

    public function getGlobalEditcount() {
        return $this->globalEditCount;
    }
}
