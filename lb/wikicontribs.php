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

class lb_wikicontribs {
    const CONTRIB_LIMIT = 20;

    private $app;
    private $wiki;

    private $user;
    private $isIp;
    private $options;

    private $centralAuth;
    private $hasManyMatches = false;
    private $recentchanges = null;
    private $hasContribs = false;
    private $registeredUsers = array();

    /**
     *
     * @param lb_app $app
     * @param string $user Search query for wiki users
     * @param boolean $isIP
     * @param object $wiki
     *  - dbname
     *  - slice
     *  - family
     *  - domain
     *  - url (may be protocol-relative, or HTTPS)
     *  - canonical_server (HTTP or HTTPS)
     * @param null|false|object $centralAuth
     * @param array $options
     */
    public function __construct(lb_app $app, $user, $isIP, $wiki, $centralAuth, $options = array()) {
        if (!$user) {
            throw new Exception("No username or IP");
        }
        $this->app = $app;

        $this->user = $user;
        $this->isIp = $isIP;
        $this->options = $options += array(
            'isPrefixPattern' => false,
            'onlyRecent' => false,
        );

        $this->wiki = $wiki;
        $this->centralAuth = $centralAuth;

        if ($this->isIp !== true) {
            $this->app->aTP('Query user data for ' . $wiki->domain);
            // Get user data
            $statement = $this->app->getDB($wiki->dbname, $wiki->slice)->prepare("SELECT
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
            unset($statement);

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

        $this->_getRecentChanges();
    }


    /**
     * gibt die letzten 20 änderungen zurück
     * @return mixed;
     */
    private function _getRecentChanges() {
        $this->app->aTP('Query recent changes for ' . $this->wiki->domain);
        $where = '';
        if ($this->options['onlyRecent']) {
            $where = " `rev_timestamp` >= '" . date('YmdHis') . "' AND ";
        }
        $pdo = $this->app->getDB($this->wiki->dbname, $this->wiki->slice);

        if ($this->registeredUsers) {
            $userIdCond = (count($this->registeredUsers) === 1)
                ? ' = ' . $pdo->quote(key($this->registeredUsers))
                : ' IN (' . join(',', array_map(array($pdo, 'quote'), array_keys($this->registeredUsers))) . ')';
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
                `page_latest` = `rev_id` AS `rev_cur`
            FROM
                `revision_userindex`
            INNER JOIN
                `page` ON `rev_page` = `page_id`
            WHERE
                ".$where."
                `rev_deleted` = 0 AND
                ".(
                    ($this->registeredUsers)
                        ? "`rev_user` $userIdCond"
                        : (
                            ($this->options['isPrefixPattern'])
                                ? 'rev_user_text LIKE :userlike'
                                : 'rev_user_text = :user'
                        )
                )."
            ORDER BY `revision_userindex`.`rev_timestamp` DESC
            LIMIT 0, " . intval(self::CONTRIB_LIMIT) .
            ";";
        $statement = $pdo->prepare($sql);
        if (!$this->registeredUsers) {
            if ($this->options['isPrefixPattern']) {
                $statement->bindParam(':userlike', $this->user);
            } else {
                $statement->bindParam(':user', $this->user);
            }
        }
        $statement->execute();
        $this->recentchanges = $statement->fetchAll(PDO::FETCH_OBJ);
        $this->hasContribs = !!$this->recentchanges;
        unset($statement);
        foreach ($this->recentchanges as $rc) {
            $rc->rev_user_text = str_replace('_', ' ', $rc->rev_user_text);

            // Expand page with prefixed namespace
            $rc->page_namespace_name = $this->app->getNamespaceName($rc->page_namespace, $this->wiki->dbname, $this->wiki->canonical_server);
            $rc->full_page_title = $rc->page_namespace_name
                ? ($rc->page_namespace_name . ':' . $rc->page_title)
                : $rc->page_title;
            $rc->full_page_title = str_replace('_', ' ', $rc->full_page_title);
        }
    }

    /**
     * Whether the user represents a single IP address.
     * @return boolean
     */
    public function isIP() {
        return $this->isIp;
    }

    /**
     * Get the latest contributions
     * @return array of objects
     */
    public function getRecentChanges() {
        return $this->recentchanges;
    }

    /**
     * @return bool|int
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
        unset($db);
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
        unset($db);
        return $res;
    }

    public function hasContribs() {
        return $this->hasContribs;
    }

    private function getUrl($pageName) {
        return $this->wiki->url . '/wiki/' . _wpurlencode($pageName);
    }

    private function getLongUrl($query) {
        return $this->wiki->url . '/w/index.php?' . $query;
    }

    private function getUserTools($userName) {
        return 'For <a href="'.htmlspecialchars($this->getUrl("User:$userName")).'">'.htmlspecialchars($userName).'</a> ('
            . '<a href="'.htmlspecialchars($this->getUrl("Special:Contributions/$userName")).'" title="Special:Contributions">contribs</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->getUrl("User_talk:$userName")).'">talk</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->getUrl("Special:Log/block").'?page=User:'._wpurlencode($userName)).'" title="Special:Log/block">block log</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->getUrl("Special:ListFiles/$userName")).'" title="Special:ListFiles">uploads</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->getUrl("Special:Log/$userName")).'" title="Special:Log">logs</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($this->getUrl("Special:AbuseLog").'wpSearchUser='._wpurlencode($userName)).'" title="Edit Filter log for this user">filter log</a>'
            . ')';
    }

    public function getDataHtml() {
        $return = '';
        $return .= '<h1>'.$this->wiki->domain.'</h1>';
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
        $userinfo[] = $this->wiki->_editcount . ' edits';
        if ($this->centralAuth) {
            $userinfo[] = 'SUL: Account attached at '.$this->app->TStoUserTime($this->centralAuth->lu_attached_timestamp);
        }
        if ($this->markAsNotUnified()) {
            $userinfo[] = 'SUL: Account not attached.';
        }
        if ($userinfo) {
            $return .= '<p class="wikiinfo">' . join(' | ', $userinfo) . '</p>';
        }
        $return .= '<ul>';
        foreach ($this->recentchanges as $rc) {
            $item = array();
            // Diff and history
            $item[] =
                '(<a href="'.htmlspecialchars($this->getLongUrl('title='._wpurlencode($rc->full_page_title).'&diff=prev&oldid='.urlencode($rc->rev_id))).'">diff</a>'
                . '&nbsp;|&nbsp;'
                . '<a href="'.htmlspecialchars($this->getLongUrl('title='._wpurlencode($rc->full_page_title).'&action=history')).'">hist</a>)'
                ;

            // Date
            $item[] = $this->app->TStoUserTime($rc->rev_timestamp);

            // Patterns may yield different users for different edits,
            // provide basic tools for each entry.
            if ($this->options['isPrefixPattern']) {
                $item[] = '<a href="'.htmlspecialchars($this->getUrl("User:{$rc->rev_user_text}")).'">'
                    . htmlspecialchars($rc->rev_user_text).'</a>'
                    . '&nbsp;(<a href="'.htmlspecialchars($this->getUrl("User_talk:{$this->user}")).'">talk</a>&nbsp;| '
                    . '<a href="'.htmlspecialchars($this->getUrl("Special:Contributions/{$this->user}")).'" title="Special:Contributions">contribs</a>)';
                $item[] = '. .';
            }
            // Minor edit
            if ($rc->rev_minor_edit) {
                $item[] = '<span class="minor">M</span>';
            }

            // Link to the page
            $item[] = '<a href="'.htmlspecialchars($this->getUrl($rc->full_page_title)).'">'
                . htmlspecialchars($rc->full_page_title)."</a>";

            // Edit summary
            if ($rc->rev_comment) {
                $item[] = '<span class="comment">('.$this->app->wikiparser($rc->rev_comment, $rc->full_page_title, $this->wiki->url).')</span>';
            }

            // Cur revision
            if ($rc->rev_cur) {
                $item[] = '<span class="rev_cur">(current)</span>';
            }
            $return .= '<li>' . join('&nbsp;', $item) . '</li>';
        }
        $return .= '</ul>';
        return $return;
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
