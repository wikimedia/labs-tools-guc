<?php

namespace Guc;

use Exception;
use PDO;
use PDOStatement;
use stdClass;

/**
 * Represents the contributions query and results for one wiki.
 *
 * @class
 */
class Contribs {
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

    private $centralAuth;
    private $hasManyMatches = false;
    private $contribs = null;
    private $localUserId = null;

    /**
     *
     * @param App $app
     * @param string $user Search query for wiki users
     * @param boolean $isIP
     * @param Wiki $wiki
     * @param object|false $centralAuth
     * @param array $options
     */
    public function __construct(App $app, $user, $isIP, Wiki $wiki, $centralAuth, $options = array()) {
        if (!$user) {
            throw new Exception('No username or IP');
        }
        $this->app = $app;
        $this->wiki = $wiki;

        $this->user = $user;
        $this->isIp = $isIP;
        $this->options = $options + array(
            'isPrefixPattern' => false,
            'src' => 'all',
        );

        $this->centralAuth = $centralAuth;
        if ($centralAuth) {
            $this->localUserId = (int)$centralAuth->lu_local_id;
        }

        $this->contribs = $this->fetchContribs();
    }

    /**
     * @param PDO $pdo
     * @return PDOStatement
     */
    private function prepareRevisionQuery(PDO $pdo) {
        $sql = "SELECT
                comment_text,
                rev_timestamp,
                rev_minor_edit,
                rev_len,
                rev_id,
                actor_name,
                page_title,
                page_namespace,
                (page_latest = rev_id) AS guc_is_cur
            FROM
                revision_userindex
            JOIN
                actor_revision ON actor_id = rev_actor
            INNER JOIN
                page ON rev_page = page_id
            LEFT OUTER JOIN
                comment_revision ON rev_comment_id = comment_id
            WHERE
                rev_deleted = 0 AND
                ".(
                    ($this->localUserId)
                        ? 'actor_user = ' . $pdo->quote((string)$this->localUserId)
                        : (
                            ($this->options['isPrefixPattern'])
                                ? 'actor_name LIKE :userlike'
                                : 'actor_name = :user'
                        )
                )."
            ORDER BY rev_timestamp DESC
            LIMIT 0, " . (string)self::CONTRIB_LIMIT .
            ";";
        $this->app->debug("[SQL] " . preg_replace('#\s+#', ' ', $sql));
        $statement = $pdo->prepare($sql);
        if (!$this->localUserId) {
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
        $conds = [
            'rc_deleted = 0',
            (
                ($this->localUserId)
                    ? 'actor_user = ' . $pdo->quote((string)$this->localUserId)
                    : (
                        ($this->options['isPrefixPattern'])
                            ? 'actor_name LIKE :userlike'
                            : 'actor_name = :user'
                    )
            ),
            // Ignore RC entries for log events and things like
            // Wikidata and categorization updates
            'rc_type IN (' . join(',', array_map(
                'intval',
                array(self::MW_RC_EDIT, self::MW_RC_NEW)
            )) . ')'
        ];
        $conds = array_merge($conds, $extraConds);
        $sqlCond = implode(' AND ', $conds);
        $sql = 'SELECT
                comment_text,
                rc_timestamp as rev_timestamp,
                rc_minor as rev_minor_edit,
                rc_new_len as rev_len,
                rc_this_oldid as rev_id,
                actor_name,
                rc_title as page_title,
                rc_namespace as page_namespace,
                "0" AS guc_is_cur
            FROM
                recentchanges_userindex
            JOIN
                actor_recentchanges ON actor_id = rc_actor
            LEFT OUTER JOIN
                comment_revision ON rc_comment_id = comment_id
            WHERE
                ' . $sqlCond . '
            ORDER BY rc_timestamp DESC
            LIMIT 0, ' . (string)self::CONTRIB_LIMIT .
            ';';
        $statement = $pdo->prepare($sql);
        $this->app->debug("[SQL] " . preg_replace('#\s+#', ' ', $sql));
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
        $this->app->debug('Query contributions on ' . $this->wiki->domain);
        $pdo = $this->app->getDB($this->wiki->slice, $this->wiki->dbname);

        if ($this->options['src'] === 'rc') {
            $statement = $this->prepareRecentchangesQuery($pdo);
        } elseif ($this->options['src'] === 'hr') {
            $statement = $this->prepareLastHourQuery($pdo);
        } else {
            $statement = $this->prepareRevisionQuery($pdo);
        }

        $statement->execute();
        $contribs = $statement->fetchAll(PDO::FETCH_OBJ);
        $statement = null;

        foreach ($contribs as $row) {
            // Normalise
            $row->actor_name = str_replace('_', ' ', $row->actor_name);

            // Localised namespace prefix
            $row->guc_namespace_name = $this->app->getNamespaceName(
                $row->page_namespace,
                $this->wiki->dbname,
                $this->wiki->canonicalServer
            );
            // Full page name
            $row->guc_pagename = $row->guc_namespace_name
                ? ($row->guc_namespace_name . ':' . $row->page_title)
                // Main namespace
                : $row->page_title;
            // Normalise
            $row->guc_pagename = str_replace('_', ' ', $row->guc_pagename);
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
     * - rev_id
     * - rev_len
     * - rev_minor_edit
     * - rev_timestamp
     * - actor_name (Normalised)
     * - comment_text
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
     * @return bool
     */
    public function isOneUser() {
        return ($this->centralAuth !== null) || (!$this->options['isPrefixPattern']);
    }

    /**
     * @return string
     */
    public function getUserQuery() {
        return $this->user;
    }

    public static function formatChange(App $app, Wiki $wiki, stdClass $rc) {
        $item = array();

        // Diff and history
        $item[] =
            '(<a href="'.htmlspecialchars($wiki->getLongUrl('title='.Wiki::urlencode($rc->guc_pagename).'&diff=prev&oldid='.urlencode($rc->rev_id))).'">diff</a>'
            . '&nbsp;|&nbsp;'
            . '<a href="'.htmlspecialchars($wiki->getLongUrl('title='.Wiki::urlencode($rc->guc_pagename).'&action=history')).'">hist</a>)'
            ;

        // Date
        $item[] = $app->formatMwDate($rc->rev_timestamp);

        // Wiki (used by guc_ChronologyContribs)
        $item['wiki'] = '. .&nbsp;' . $wiki->domain . '&nbsp;. .';

        // When using isPrefixPattern, different edits may be from different users.
        // Show user name and basic tools for each entry.
        $item['user'] = '<a href="'.htmlspecialchars($wiki->getUrl("User:{$rc->actor_name}")).'">'
            . htmlspecialchars($rc->actor_name).'</a>'
            . '&nbsp;(<a href="'.htmlspecialchars($wiki->getUrl("User_talk:{$rc->actor_name}")).'">talk</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($wiki->getUrl("Special:Contributions/{$rc->actor_name}")).'" title="Special:Contributions">contribs</a>)&nbsp;. .';

        // Minor edit
        if ($rc->rev_minor_edit) {
            $item[] = '<span class="minor">m</span>';
        }

        // Link to the page
        $item[] = '<a href="'.htmlspecialchars($wiki->getUrl($rc->guc_pagename)).'">'
            . htmlspecialchars($rc->guc_pagename)."</a>";

        // Edit summary
        if ($rc->comment_text) {
            $item[] = '<span class="comment">('.$app->wikiparser($rc->comment_text, $rc->guc_pagename, $wiki->url).')</span>';
        }

        // Cur revision
        if ($rc->guc_is_cur) {
            $item[] = '<span class="rev_cur">(current)</span>';
        }

        return $item;
    }

    /**
     * Whether the user should be as unattached.
     *
     * @return boolean
     */
    public function isUnattached() {
        return $this->centralAuth === null;
    }

    /**
     * @see Main::getCentralauthRow()
     * @return stdClass|bool False if data is unavailable
     */
    public function getCentralAuth() {
        return $this->centralAuth;
    }
}
