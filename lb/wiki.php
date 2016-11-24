<?php
/**
 * Copyright 2016 by Timo Tijhof
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

class guc_Wiki {
    public $dbname;
    public $slice;
    public $family;

    /**
     * @var string
     */
    public $domain;

    /**
     * HTTP or HTTPS
     * @var string
     */
    public $canonicalServer;

    /**
     * May be protocol-relative, or HTTPS
     * @var string
     */
    public $url;

    private function __construct() {
    }

    /**
     * See guc::_getWikis().
     *
     * @param stdClass $metaRow Row from meta_p table
     * @return guc_Wiki
     */
    public static function newFromRow(stdClass $row) {
        $wiki = new self();

        $wiki->dbname = $row->dbname;
        $wiki->slice = $row->slice;
        $wiki->family = $row->family;

        $wiki->domain = preg_replace('#^https?://#', '', $row->url);

        $wiki->canonicalServer = $row->url;

        // Convert "http://" to "//".
        // Keep https:// as-is since we should not override that. (phabricator:T94351)
        $wiki->url = preg_replace('#^http://#', '//', $row->url);

        return $wiki;
    }

    public function getUrl($pageName) {
        return $this->url . '/wiki/' . _wpurlencode($pageName);
    }

    public function getLongUrl($query) {
        return $this->url . '/w/index.php?' . $query;
    }
}
