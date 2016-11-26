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

class PerWikiOutput implements IOutput {
    private $app;
    private $datas;
    private $options;

    public function __construct(App $app, stdClass $datas, array $options = array()) {
        $this->app = $app;
        $this->datas = $datas;
        $this->options = $options += array(
            'rcUser' => false,
        );
    }

    public function output() {
        foreach ($this->datas as $data) {
            if ($data->error) {
                print '<div class="error">';
                if (isset($data->wiki->domain)) {
                    print '<h1>'.htmlspecialchars($data->wiki->domain).'</h1>';
                }
                print htmlspecialchars($data->error->getMessage());
                print '</div>';
            } else {
                $contribs = $data->contribs;
                if ($contribs->hasContribs()) {
                    print '<div class="wiki'.($contribs->isUnattached()?' noSul':'').'">';
                    print $this->makeWikiSection($data->wiki, $contribs);
                    print '</div>';
                }
            }
        }
    }

    public function makeWikiSection(Wiki $wiki, Contribs $contribs) {
        $html = '';
        $html .= '<h1>'.$wiki->domain.'</h1>';

        $userinfo = array();

        $users = $contribs->getUsers();
        if ($users) {
            if (count($users) === 1) {
                $userinfo[] = $this->getUserTools($wiki, current($users));
            } else {
                $userinfo[] = count($users) . ' users: '
                    . implode(', ', array_values($users));
            }
        } elseif ($contribs->hasManyUsers()) {
            $userinfo[] = 'More than 10 users';
        }

        $userinfo[] = $contribs->getEditcount() . ' edits';

        if ($contribs->isUnattached()) {
            $userinfo[] = 'SUL: Account not attached.';
        } else {
            $ca = $contribs->getCentralAuth();
            if ($ca) {
                $userinfo[] = 'SUL: Account attached at '.$this->app->formatMwDate($ca->lu_attached_timestamp);
            }
        }

        if ($userinfo) {
            $html .= '<p class="wikiinfo">' . join(' | ', $userinfo) . '</p>';
        }

        $html .= '<ul>';
        foreach ($contribs->getContribs() as $rc) {
            $html .= $this->makeChangeLine($wiki, $rc);
        }
        $html .= '</ul>';

        return $html;
    }

    private function makeChangeLine(Wiki $wiki, $rc) {
        $chunks = Contribs::formatChange($this->app, $wiki, $rc);
        unset($chunks['wiki']);
        if (!$this->options['rcUser']) {
            unset($chunks['user']);
        }
        return '<li>' . join('&nbsp;', $chunks) . '</li>';
    }

    private function getUserTools(Wiki $wiki, $userName) {
        return 'For <a href="'.htmlspecialchars($wiki->getUrl("User:$userName")).'">'.htmlspecialchars($userName).'</a> ('
            . '<a href="'.htmlspecialchars($wiki->getUrl("Special:Contributions/$userName")).'" title="Special:Contributions">contribs</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($wiki->getUrl("User_talk:$userName")).'">talk</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($wiki->getUrl("Special:Log/block").'?page=User:'.Wiki::urlencode($userName)).'" title="Special:Log/block">block log</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($wiki->getUrl("Special:ListFiles/$userName")).'" title="Special:ListFiles">uploads</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($wiki->getUrl("Special:Log/$userName")).'" title="Special:Log">logs</a>&nbsp;| '
            . '<a href="'.htmlspecialchars($wiki->getUrl("Special:AbuseLog").'?wpSearchUser='.Wiki::urlencode($userName)).'" title="Edit Filter log for this user">filter log</a>'
            . ')';
    }
}
