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

/**
 * Represents the contributions query and results for one wiki.
 *
 * @class
 */
class lb_wikicontribs {
    const CONTRIB_LIMIT = 20;
    const MW_DATE_FORMAT = 'YmdHis';
    const MW_RC_EDIT = 0;
    const MW_RC_NEW = 1;
    // Other RC types: Log events, Categorization, External (e.g. Wikidata)

    private $app;
    private $wiki;

    private $user;
    private $isIp;
    private $options;

    private $editcount;
    private $centralAuth;
    private $hasManyMatches = false;
    private $contribs = null;
    private $registeredUsers = array();

    /**
     *
     * @param lb_app $app
     * @param string $user Search query for wiki users
     * @param boolean $isIP
     * @param guc_Wiki $wiki
     * @param int $editcount
     * @param null|false|object $centralAuth
     * @param array $options
     */
    public function __construct(lb_app $app, $user, $isIP, $wiki, $editcount, $centralAuth, $options = array()) {
        if (!$user) {
            throw new Exception('No username or IP');
        }
        $this->app = $app;
        $this->wiki = $wiki;

        $this->user = $user;
        $this->isIp = $isIP;
        $this->options = $options += array(
            'isPrefixPattern' => false,
            'src' => 'all',
        );

        $this->editcount = $editcount;
        $this->centralAuth = $centralAuth;

        if ($this->isIp !== true) {
            $this->app->aTP('Query user data for ' . $wiki->domain);
            // Get user data
            $statement = $this->app->getDB($wiki->dbname, $wiki->slice)->prepare(
                "SELECT
                    `user_id`,
                    `user_name`
                FROM `user`
                WHERE ".(
                    ($this->options['isPrefixPattern'])
                        ? "`user_name` LIKE :userlike"
                        : "`user_name` = :user"
                )."
                LIMIT 10;"
            );
            if ($this->options['isPrefixPattern']) {
                $statement->bindParam(':userlike', $this->user);
            } else {
                $statement->bindParam(':user', $this->user);
            }
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_OBJ);

            // Limit quering of user ids to 10. If it's more than that, make the database
            // query for contributions like for IP-addresses by using wildcard user_text.
            if (count($rows) > 10) {
                $this->hasManyMatches = true;
            } elseif ($rows) {
                foreach ($rows as $row) {
                    $this->registeredUsers[$row->user_id] = $row->user_name;
                }
            }
            // Else: 'user' does not exist, or is an IP, or an IP pattern,
            // or a user name pattern with many matches.
        }

        $this->contribs = $this->fetchContribs();
    }

    /**
     * @param PDO $pdo
     * @param array $userIds
     * @return PDOStatement
     */
    private function prepareRevisionQuery(PDO $pdo) {
        // Optimisation: Use rev_user index where possible
        $userIdCond = '';
        if ($this->registeredUsers) {
            $userIdCond = (count($this->registeredUsers) === 1)
                ? '`rev_user` = ' . $pdo->quote(key($this->registeredUsers))
                : '`rev_user` IN (' . join(',', array_map(
                    array($pdo, 'quote'),
                    array_keys($this->registeredUsers)
                )) . ')';
        }
        $sql = "SELECT
                `rev_comment`,
                `rev_timestamp`,
                `rev_minor_edit`,
                `rev_len`,
                `rev_id`,
                `rev_parent_id`,
                `rev_user_text`,
                `page_title`,
                `page_namespace`,
                `page_latest` = `rev_id` AS `guc_is_cur`
            FROM
                `revision_userindex`
            INNER JOIN
                `page` ON `rev_page` = `page_id`
            WHERE
                `rev_deleted` = 0 AND
                ".(
                    ($userIdCond)
                        ? $userIdCond
                        : (
                            ($this->options['isPrefixPattern'])
                                ? 'rev_user_text LIKE :userlike'
                                : 'rev_user_text = :user'
                        )
                )."
            ORDER BY `rev_timestamp` DESC
            LIMIT 0, " . intval(self::CONTRIB_LIMIT) .
            ";";
        $statement = $pdo->prepare($sql);
        if (!$userIdCond) {
            if ($this->options['isPrefixPattern']) {
                $statement->bindParam(':userlike', $this->user);
            } else {
                $statement->bindParam(':user', $this->user);
            }
        }
        return $statement;
    }

    /**
     * @param PDO $pdo
     * @param array $extraConds
     * @return PDOStatement
     */
    private function prepareRecentchangesQuery(PDO $pdo, $extraConds = []) {
        // Avoid use of rc_user. Contrary to revision table, it has no index.
        // Simply use rc_user_text instead, which has a good index.
        $conds = [
            '`rc_deleted` = 0',
            ($this->options['isPrefixPattern'])
                ? 'rc_user_text LIKE :userlike'
                : 'rc_user_text = :user',
            // Ignore RC entries for log events and things like
            // Wikidata and categorization updates
            '`rc_type` IN (' . join(',', array_map(
                'intval',
                array(self::MW_RC_EDIT, self::MW_RC_NEW)
            )) . ')'
        ];
        $conds = array_merge($conds, $extraConds);
        $sqlCond = implode(' AND ', $conds);
        $sql = 'SELECT
                `rc_comment` as `rev_comment`,
                `rc_timestamp` as `rev_timestamp`,
                `rc_minor` as `rev_minor_edit`,
                `rc_new_len` as `rev_len`,
                `rc_this_oldid` as `rev_id`,
                `rc_last_oldid` as `rev_parent_id`,
                `rc_user_text` as `rev_user_text`,
                `rc_title` as `page_title`,
                `rc_namespace` as `page_namespace`,
                "0" AS `guc_is_cur`
            FROM
                `recentchanges_userindex`
            WHERE
                ' . $sqlCond . '
            ORDER BY `rc_timestamp` DESC
            LIMIT 0, ' . intval(self::CONTRIB_LIMIT) .
            ';';
        $statement = $pdo->prepare($sql);
        if ($this->options['isPrefixPattern']) {
            $statement->bindParam(':userlike', $this->user);
        } else {
            $statement->bindParam(':user', $this->user);
        }
        return $statement;
    }

    /**
     * @param PDO $pdo
     * @return PDOStatement
     */
    private function prepareLastHourQuery(PDO $pdo) {
        // UTC timestamp of 1 hour ago
        $cutoff = gmdate(self::MW_DATE_FORMAT, time() - 3600);
        $conds = [
            'rc_timestamp >= ' . $pdo->quote($cutoff)
        ];
        return $this->prepareRecentchangesQuery($pdo, $conds);
    }

    /**
     * Fetch contributions from the database
     */
    private function fetchContribs() {
        $this->app->aTP('Query contributions on ' . $this->wiki->domain);
        $pdo = $this->app->getDB($this->wiki->dbname, $this->wiki->slice);

        if ($this->options['src'] === 'rc') {
            $statement = $this->prepareRecentchangesQuery($pdo);
        } elseif ($this->options['src'] === 'hr') {
            $statement = $this->prepareLastHourQuery($pdo);
        } else {
            $statement = $this->prepareRevisionQuery($pdo);
        }
        $statement->execute();

        $contribs = $statement->fetchAll(PDO::FETCH_OBJ);

        foreach ($contribs as $rc) {
            // Normalise
            $rc->rev_user_text = str_replace('_', ' ', $rc->rev_user_text);

            // Localised namespace prefix
            $rc->guc_namespace_name = $this->app->getNamespaceName(
                $rc->page_namespace,
                $this->wiki->dbname,
                $this->wiki->canonicalServer
            );
            // Full page name
            $rc->guc_pagename = $rc->guc_namespace_name
                ? ($rc->guc_namespace_name . ':' . $rc->page_title)
                // Main namespace
                : $rc->page_title;
            // Normalise
            $rc->guc_pagename = str_replace('_', ' ', $rc->guc_pagename);
        }

        return $contribs;
    }

    /**
     * Whether the user represents a single IP address.
     * @return boolean
     */
    public function isIP() {
        return $this->isIp;
    }

    /**
     * Get the fetched contributions
     * @return array of objects with the following properties:
     * - page_namespace
     * - page_title
     * - rev_comment
     * - rev_id
     * - rev_len
     * - rev_minor_edit
     * - rev_parent_id
     * - rev_timestamp
     * - rev_user_text (Normalised)
     * - guc_is_cur
     * - guc_namespace_name (Localised namespace prefix)
     * - guc_pagename (Full page name)
     */
    public function getContribs() {
        return $this->contribs;
    }


    /**
     * @return boolean
     */
    public function hasContribs() {
        return !!$this->contribs;
    }

    /**
     * @return array Map of user id to user name
     */
    public function getRegisteredUsers() {
        return $this->registeredUsers;
    }


    /**
     * Get relevant info about account blocks in recent history.
     * @return null|array
     */
    public function getBlocks() {
        return $this->getIpBlocks() ?: $this->getUserBlocks();
    }

    private function getIpBlocks() {
        if (!$this->isIP()) {
            return null;
        }
        $qry = "SELECT
                    ipblocks.*,
                    `user`.user_name AS admin_username
            FROM ipblocks
            INNER JOIN `user` ON ipb_by = `user`.user_id
            WHERE ipblocks.ipb_address = :ipaddress LIMIT 0,100;";
        $db = $this->app->getDB($this->dbname, $this->slice)->prepare($qry);
        $db->bindParam(':ipaddress', $this->user);
        $db->execute();
        $res = $db->fetchAll(PDO::FETCH_ASSOC);
        return $res;
    }

    private function getUserBlocks() {
        if ($this->isIP() || $this->options['isPrefixPattern'] || !$this->registeredUsers) {
            return null;
        }
        $userId = key($this->registeredUsers);
        $qry = "SELECT
                    ipblocks.*,
                    `user`.user_name AS admin_username
            FROM ipblocks
            INNER JOIN `user` ON ipb_by = `user`.user_id
            WHERE ipblocks.ipb_user = :id LIMIT 0,100;";
        $db = $this->app->getDB($this->dbname, $this->slice)->prepare($qry);
        $db->bindParam(':id', $userId);
        $db->execute();
        $res = $db->fetchAll(PDO::FETCH_ASSOC);
        return $res;
    }

    private function getUserTools($userName) {
        return 'For <a href="'.htmlspecialchars($this->wiki->getUrl("User:$userName")).'">'.htmlspecialchars($userName).'</a> ('
            . '<a href="'.htmlspecialchars($this->wiki->getUrl("Special:Contributions/$userName")).'" title="Special:Contributions">contribs</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->wiki->getUrl("User_talk:$userName")).'">talk</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->wiki->getUrl("Special:Log/block").'?page=User:'._wpurlencode($userName)).'" title="Special:Log/block">block log</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->wiki->getUrl("Special:ListFiles/$userName")).'" title="Special:ListFiles">uploads</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->wiki->getUrl("Special:Log/$userName")).'" title="Special:Log">logs</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->wiki->getUrl("Special:AbuseLog").'?wpSearchUser='._wpurlencode($userName)).'" title="Edit Filter log for this user">filter log</a>'
            . ')';
    }

    public function getDataHtml() {
        $html = '';
        $html .= '<h1>'.$this->wiki->domain.'</h1>';
        $userinfo = array();
        if (!$this->options['isPrefixPattern']) {
            $userinfo[] = $this->getUserTools($this->user);
        } else {
            if ($this->registeredUsers) {
                if (count($this->registeredUsers) === 1) {
                    $userinfo[] = $this->getUserTools(current($this->registeredUsers));
                } else {
                    $userinfo[] = ($this->hasManyMatches ? 'More than ' : '')
                        . count($this->registeredUsers) . ' users: '
                        . implode(', ', array_values($this->registeredUsers));
                }
            }
        }
        $userinfo[] = $this->editcount . ' edits';
        if ($this->centralAuth) {
            $userinfo[] = 'SUL: Account attached at '.$this->app->formatMwDate($this->centralAuth->lu_attached_timestamp);
        }
        if ($this->markAsNotUnified()) {
            $userinfo[] = 'SUL: Account not attached.';
        }
        if ($userinfo) {
            $html .= '<p class="wikiinfo">' . join(' | ', $userinfo) . '</p>';
        }
        $html .= '<ul>';
        foreach ($this->getContribs() as $rc) {
            $html .= $this->formatChangeLine($rc);
        }
        $html .= '</ul>';
        return $html;
    }

    protected function formatChangeLine($rc) {
        $chunks = self::formatChange($this->app, $this->wiki, $rc);
        unset($chunks['wiki']);
        if (!$this->options['isPrefixPattern']) {
            unset($chunks['user']);
        }
        return '<li>' . join('&nbsp;', $chunks) . '</li>';
    }

    public static function formatChange(lb_app $app, guc_Wiki $wiki, stdClass $rc) {
        $item = array();

        // Diff and history
        $item[] =
            '(<a href="'.htmlspecialchars($wiki->getLongUrl('title='._wpurlencode($rc->guc_pagename).'&diff=prev&oldid='.urlencode($rc->rev_id))).'">diff</a>'
            . '&nbsp;|&nbsp;'
            . '<a href="'.htmlspecialchars($wiki->getLongUrl('title='._wpurlencode($rc->guc_pagename).'&action=history')).'">hist</a>)'
            ;

        // Date
        $item[] = $app->formatMwDate($rc->rev_timestamp);

        // Wiki (used by guc_ChronologyContribs)
        $item['wiki'] = '. .&nbsp;' . $wiki->domain . '&nbsp;. .';

        // When using isPrefixPattern, different edits may be from different users.
        // Show user name and basic tools for each entry.
        $item['user'] = '<a href="'.htmlspecialchars($wiki->getUrl("User:{$rc->rev_user_text}")).'">'
            . htmlspecialchars($rc->rev_user_text).'</a>'
            . '&nbsp;(<a href="'.htmlspecialchars($wiki->getUrl("User_talk:{$rc->rev_user_text}")).'">talk</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($wiki->getUrl("Special:Contributions/{$rc->rev_user_text}")).'" title="Special:Contributions">contribs</a>)&nbsp;. .';

        // Minor edit
        if ($rc->rev_minor_edit) {
            $item[] = '<span class="minor">M</span>';
        }

        // Link to the page
        $item[] = '<a href="'.htmlspecialchars($wiki->getUrl($rc->guc_pagename)).'">'
            . htmlspecialchars($rc->guc_pagename)."</a>";

        // Edit summary
        if ($rc->rev_comment) {
            $item[] = '<span class="comment">('.$app->wikiparser($rc->rev_comment, $rc->guc_pagename, $wiki->url).')</span>';
        }

        // Cur revision
        if ($rc->guc_is_cur) {
            $item[] = '<span class="rev_cur">(current)</span>';
        }

        return $item;
    }

    /**
     * Whether the user should be considered to be ununified.
     *
     * @see guc::_getCentralauthData: False means CentralAuth is unavailable
     * or the user is an IP or pattern. Null means we're dealing with a proper
     * user name but the account is not attached on this wiki.
     * @return boolean
     */
    public function markAsNotUnified() {
        return $this->centralAuth === null;
    }
}
