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

namespace Guc;

use stdClass;

class Wiki {
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
     * See Main::getWikis().
     *
     * @param stdClass $metaRow Row from meta_p table
     * @return Wiki
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
        return $this->url . '/wiki/' . Wiki::urlencode($pageName);
    }

    public function getLongUrl($query) {
        return $this->url . '/w/index.php?' . $query;
    }

    /**
     * Based on MediaWiki's wfUrlencode()
     *
     * @param string $pageName
     * @return string
     */
    public static function urlencode($pageName) {
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
     * Based on MediaWiki's Sanitizer::escapeId()
     *
     * @param string $id
     * @return string
     */
    public static function escapeId($id) {
        // HTML4-style escaping
        static $replace = [
            '%3A' => ':',
            '%' => '.',
        ];

        $id = urlencode(strtr($id, ' ', '_'));
        $id = strtr($id, $replace);
        return $id;
    }

    /**
     * Based on MediaWiki 1.25's Sanitizer::escapeHtmlAllowEntities
     *
     * @param string $wikitext
     * @return string HTML
     */
    public static function wikitextHtmlEscape($wikitext) {
        $text = html_entity_decode($wikitext);
        $html = htmlspecialchars($text, ENT_QUOTES);
        return $html;
    }

    /**
     * Escape a string for use as preg_replace() replacement parameter.
     *
     * Based on MediaWiki 1.25's StringUtils::escapeRegexReplacement
     *
     * @param string $str
     * @return string
     */
    public static function pregReplaceEscape($str) {
        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace('$', '\\$', $str);
        return $str;
    }
}
