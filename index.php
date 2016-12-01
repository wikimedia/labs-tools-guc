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

use Guc\App;
use Guc\ChronologyOutput;
use Guc\PerWikiOutput;
use Guc\Contribs;
use Guc\Main;

require_once __DIR__ . '/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

$data = new stdClass();
$data->Method = @$_SERVER['REQUEST_METHOD'] ?: 'GET';
$data->Referer = @$_SERVER['HTTP_REFERER'] ?: null;
$data->Username = @$_REQUEST['user'] ?: null;
$data->options = array(
    'isPrefixPattern' => @$_REQUEST['isPrefixPattern'] === '1',
    'src' => @$_REQUEST['src'] ?: 'all',
    'by' => @$_REQUEST['by'] ?: 'wiki',
);

// Create app
$app = $guc = $appError = $robotsPolicy = $canonicalUrl = null;
try {
    $app = new App();
    if ($data->Method === 'POST') {
        $app->aTP('Got input, start search');
        $guc = new Main($app, $data->Username, $data->options);
        $robotsPolicy = 'noindex,follow';
    } else {
        $guc = null;
        if ($data->Username) {
            $robotsPolicy = 'noindex,follow';
        }
        $canonicalUrl = './';
    }
} catch (Exception $e) {
    $appError = $e;
}

$query = $data->options;
if ($data->Username) {
    $query['user'] = $data->Username;
}
// Strip defaults
$query = array_diff_assoc($query, Main::getDefaultOptions());
$data->Permalink = './' . ( !$query ? '' : '?' . http_build_query($query) );

$headRobots = !$robotsPolicy ? '' :
    '<meta name="robots" content="' . htmlspecialchars($robotsPolicy) . '">';
$headCanonical = !$canonicalUrl ? '' :
    '<link rel="canonical" href="' . htmlspecialchars($canonicalUrl) . '">';

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="resources/style.css">
<title>Global user contributions</title>
<?php
if ($headRobots) {
print "$headRobots\n";
}
if ($headCanonical) {
print "$headCanonical\n";
}
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
                <p><label>Results from: <?php
                    $resultSelect = new \HtmlSelect([
                        'all' => 'All contributions',
                        'rc' => 'Recent changes (last 30 days)',
                        'hr' => 'Last hour only'
                    ]);
                    $resultSelect->setDefault($data->options['src']);
                    $resultSelect->setName('src');
                    print $resultSelect->getHTML();
                ?></label></p>
                <p>Sort results:
                <label><input name="by" type="radio" value="wiki"<?php
                if ($data->options['by'] !== 'date') {
                    print ' checked';
                }
                ?>> By wiki</label>
                <label><input name="by" type="radio" value="date"<?php
                if ($data->options['by'] === 'date') {
                    print ' checked';
                }
                ?>> By date and time</label></p>
                <input type="submit" value="Search" class="submitbutton" id="submitButton">
                <div id="loadLine" style="display: none;">&nbsp;</div>
            </form>
            <?php
            if ($appError) {
                print '<div class="error">';
                print 'Error: ' . htmlspecialchars($appError->getMessage());
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
                $infos = array_filter($guc->getIPInfos());
                if ($infos) {
                    print '<table class="box">';
                    foreach ($infos as $ip => $info) {
                        print '<tr>'
                            . '<td class="hostname">' . htmlspecialchars($ip) . '</td>'
                            . '<td>' . (isset($info['host'])
                                ? (' <tt>' . htmlspecialchars($info['host']) .'</tt>')
                                : ''
                            ) . '</td>'
                            . '<td>' . (isset($info['description'])
                                ? (' ' . htmlspecialchars($info['description']))
                                : ''
                            ) . '</td>'
                            . '<td>' . (isset($info['range'])
                                ? (' <tt>' . htmlspecialchars($info['range']) .'</tt>')
                                : ''
                            ) . '</td>'
                            . '</tr>';
                    }
                    if (count($infos) >= 10) {
                        print '<tr><td colspan="3">(Limited hostname lookups)</td></tr>';
                    }
                    print '</table>';
                }
                if ($data->options['by'] === 'date') {
                    // Sort results by date
                    $formatter = new ChronologyOutput($app, $guc->getData());
                } else {
                    // Sort results by wiki
                    $formatter = new PerWikiOutput($app, $guc->getData(), array(
                        'rcUser' => $data->options['isPrefixPattern']
                    ));
                }
                $formatter->output();
                print '</div>';
                print '<p>Limited to ' . intval(Contribs::CONTRIB_LIMIT) . ' results per wiki.</p>';
            }
            // print '<pre>';
            // $app->printTimes();
            // print '</pre>';
            ?>
        </div>
        <div class="footer">
            by <a href="https://wikitech.wikimedia.org/wiki/User:Luxo">Luxo</a> · <a href="https://meta.wikimedia.org/wiki/User:Krinkle">Krinkle</a>
            <br>
            <a href="https://github.com/wikimedia/labs-tools-guc">Source repository</a> · <a href="https://phabricator.wikimedia.org/tag/guc/">Issue tracker</a>
        </div>
        <script src="resources/frontend.js"></script>
    </body>
</html>
