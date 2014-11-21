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

// Standard-Dateien
require_once 'settings.php';
require_once 'app.php';

error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', 1);

// Komponenten einbinden
foreach (settings::getSetting('components') as $cmp) {
    if (is_file('lb/'.$cmp.'.php')) {
        require_once 'lb/'.$cmp.'.php';
    }
}

// Create app
$app = new lb_app();
if (key_exists('user', $_POST)) {
    $app->aTP('got username, start search.');
    $guc = new guc($app);
} else {
    $guc = null;
}
$jsData = new stdClass();
$jsData->Method = $_SERVER["REQUEST_METHOD"];
$jsData->Referer = $_SERVER['HTTP_REFERER'];
$jsData->Username = $_REQUEST['user'];

?>
<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <link rel="stylesheet" href="resources/style.css" type="text/css">
        <script src="resources/frontend.js"></script>
        <script src="lib/prototype.js"></script>
        <title>Global user contributions</title>
        <script type="text/javascript">
            var data = <?php print json_encode($jsData); ?>;
        </script>
    </head>
    <body>
        <div class="maincontent">
            <div class="header">
                <h2>global user contributions <span>beta</span></h2>
            <p>Tool to search contributions of a user in the wikimedia-wikis. Additional features like blocklog, sul-info or translation will follow in the future.</p>
            </div>
            <form method="POST" class="searchField" id="searchForm">
                <p> IP Address or username: <input name="user" value="<?php
                    if ($guc) {
                      print htmlspecialchars($guc->getUsername());
                    } elseif (key_exists('user', $_GET)) {
                      print htmlspecialchars($_GET['user']);
                    }
                ?>" class="usernameOrIp"></p>
                <input type="submit" value="Search" class="submitbutton" id="submitButton" onclick="onSearchClick(this);">
                <div id="loadLine" style="display: none;">&nbsp;</div>
            </form>
            <?php
            if ($guc) {
                print '<p class="statistics">'.$guc->wikisCount." wikis searched. ";
                if ($guc->getAlloverCount() > 0) {
                    print $guc->getAlloverCount().' edits found in '.$guc->wikisWithEditscount.' projects.';
                }
                print '</p>';
                print '<div class="results">';
                if ($guc->getHostname()) {
                    print '<div class="hostname">'.$guc->getUsername().' = '.$guc->getHostname().'</div>';
                }
                foreach ($guc->getData() as $wiki) {
                    if (is_object($wiki['data'])) {
                        if ($wiki['data']->hasContribs()) {
                            print '<div class="wiki'.(($wiki['data']->markAsNotUnified())?' noSul':'').'">';
                            print $wiki['data']->getDataHtml();
                            print '</div>';
                        }
                    } elseif ($wiki['error']) {
                        print('<div class="error">');
                        if ($wiki['url']) {
                            print'<h1>'.htmlspecialchars($wiki['url']).'</h1>';
                        }
                        print $wiki['error'];
                        print '</div>';
                    }

                }
                print '</div>';
            }
            ?>
            <div class="footer">
                by <a href="https://wikitech.wikimedia.org/wiki/User:Luxo">Luxo</a>
            </div>
        </div>
    </body>
</html>
