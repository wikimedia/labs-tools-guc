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

require_once __DIR__ . '/vendor/autoload.php';
require_once 'settings.php';
require_once 'app.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

// Load components
foreach (settings::getSetting('components') as $cmp) {
    if (is_readable('lb/' . $cmp . '.php')) {
        require_once 'lb/' . $cmp . '.php';
    }
}

$data = new stdClass();
$data->Method = @$_SERVER['REQUEST_METHOD'] ?: 'GET';
$data->Referer = @$_SERVER['HTTP_REFERER'] ?: null;
$data->Username = @$_REQUEST['user'] ?: null;
$data->options = array(
    'isPrefixPattern' => @$_REQUEST['isPrefixPattern'] === '1',
);

// Create app
$app = $guc = $error = $robotsPolicy = $canonicalUrl = null;
try {
    $app = new lb_app();
    if ($data->Method === 'POST') {
        $app->aTP('Got input, start search');
        $guc = new guc($app, $data->Username, $data->options);
        $robotsPolicy = 'noindex,follow';
    } else {
        $guc = null;
        if ($data->Username) {
            $robotsPolicy = 'noindex,follow';
        }
        $canonicalUrl = './';
    }
} catch (Exception $e) {
    $error = $e;
}

$query = $data->options;
if ($data->Username) {
    $query['user'] = $data->Username;
}
// Strip defaults
$query = array_diff_assoc( $query, guc::getDefaultOptions() );
$data->Permalink = './' . ( !$query ? '' : '?' . http_build_query( $query ) );

$headRobots = !$robotsPolicy ? '' :
    '<meta name="robots" content="' . htmlspecialchars( $robotsPolicy ) . '">';
$headCanonical = !$canonicalUrl ? '' :
    '<link rel="canonical" href="' . htmlspecialchars( $canonicalUrl ) . '">';

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="resources/style.css">
<title>Global user contributions</title>
<?php
    if ($headRobots) print "$headRobots\n";
    if ($headCanonical) print "$headCanonical\n";
?><script>
    var data = <?php print json_encode($data); ?>;
</script>
</head>
    <body>
        <div class="maincontent">
            <div class="header">
                <h2>Global user contributions <span>beta</span></h2>
            <p>Tool to search for contributions of users on the Wikimedia wikis. Additional features like blocklog, sul-info or translation will follow in the future.</p>
            </div>
            <form action="./" method="POST" class="searchField" id="searchForm">
                <p><label>IP address or username: <input name="user" value="<?php
                    if ($data->Username) {
                        print htmlspecialchars($data->Username);
                    }
                ?>"></label></p>
                <p><label>Activate prefix pattern search: <input name="isPrefixPattern" type="checkbox" value="1"<?php
                    if ($data->options['isPrefixPattern']) {
                        print ' checked';
                    }
                ?>></label></p>
                <input type="submit" value="Search" class="submitbutton" id="submitButton">
                <div id="loadLine" style="display: none;">&nbsp;</div>
            </form>
            <?php
            if ($error) {
                print '<div class="error">';
                print 'Error: ' . htmlspecialchars($error->getMessage());
                print '</div>';
            }
            if ($guc) {
                print '<p class="statistics">'.$guc->getWikiCount().' wikis searched. ';
                print $guc->getGlobalEditcount().' edits found';
                if ($guc->getResultWikiCount()) {
                    print ' in '.$guc->getResultWikiCount().' projects';
                }
                print '.</p>';
                print '<div class="results">';
                $hostnames = array_filter($guc->getHostnames());
                if ($hostnames) {
                    print '<div class="box">';
                    foreach ($guc->getHostnames() as $ip => $hostname) {
                        print '<div class="hostname">Hostname of ' . htmlspecialchars($ip) . ':&nbsp; <tt>' . htmlspecialchars($hostname).'</tt></div>';
                    }
                    if (count($hostnames) >= 10) {
                        print '<em>(Limited hostname lookups)</em>';
                    }
                     print '</div>';
                }
                foreach ($guc->getData() as $data) {
                    if ($data->error) {
                        print '<div class="error">';
                        if (isset($data->wiki->domain)) {
                            print'<h1>'.htmlspecialchars($data->wiki->domain).'</h1>';
                        }
                        print htmlspecialchars($data->error->getMessage());
                        print '</div>';
                    } else {
                        if ($data->contribs->hasContribs()) {
                            print '<div class="wiki'.(($data->contribs->markAsNotUnified())?' noSul':'').'">';
                            print $data->contribs->getDataHtml();
                            print '</div>';
                        }
                    }
                }
                print '</div>';
            }
            // print '<pre>';
            // $app->printTimes();
            // print '</pre>';
            ?>
            <div class="footer">
                by <a href="https://wikitech.wikimedia.org/wiki/User:Luxo">Luxo</a> &bull; <a href="https://meta.wikimedia.org/wiki/User:Krinkle">Krinkle</a>
            </div>
        </div>
        <script src="resources/frontend.js"></script>
    </body>
</html>
