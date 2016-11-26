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

    public function __construct(App $app, stdClass $datas) {
        $this->app = $app;
        $this->datas = $datas;
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
            } elseif ($data->contribs->hasContribs()) {
                print '<div class="wiki'.(($data->contribs->markAsNotUnified())?' noSul':'').'">';
                print $data->contribs->getDataHtml();
                print '</div>';
            }
        }
    }
}
