<?php
// Global user contributions
// Copyright 2014 Luxo
// Copyright 2014-2019 Timo Tijhof

use Guc\App;
use Guc\ChronologyOutput;
use Guc\PerWikiOutput;
use Guc\Contribs;
use Guc\Main;
use Krinkle\Intuition\Intuition;
use Krinkle\Toolbase\Html;
use Krinkle\Toolbase\HtmlSelect;

require_once __DIR__ . '/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

$data = new stdClass();
$data->Method = @$_SERVER['REQUEST_METHOD'] ?: 'GET';
$data->Referer = @$_SERVER['HTTP_REFERER'] ?: null;
$data->Username = @$_REQUEST['user'] ?: null;
$data->debug = isset($_REQUEST['debug']);
$data->options = array(
    'isPrefixPattern' => @$_REQUEST['isPrefixPattern'] === '1',
    'src' => @$_REQUEST['src'] ?: 'all',
    'by' => @$_REQUEST['by'] ?: 'date',
);

$int = new Intuition(array(
    'domain' => 'guc',
));
$int->registerDomain('guc', __DIR__ . '/i18n');

// Create app
$app = $guc = $appError = $robotsPolicy = $canonicalUrl = null;
try {
    $app = new App();
    if ($data->Method === 'POST') {
        $app->debug('Handling form submission');
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

$langCode = $int->getLang();
$langDir = $int->getDir();
?>
<!DOCTYPE html>
<html dir="<?php echo htmlspecialchars($langDir); ?>" lang="<?php echo htmlspecialchars($langCode); ?>">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="resources/style.css">
<title><?php echo htmlspecialchars($int->msg('title')); ?></title>
<script>
GucData = <?php print json_encode($data); ?>;
</script>
<script defer src="resources/frontend.js"></script>
<?php
if ($headRobots) {
print "$headRobots\n";
}
if ($headCanonical) {
print "$headCanonical\n";
}

$sep = $int->msg('colon-separator', array('domain' => 'general'));
?></head>
<body>
    <div class="header">
        <h2><?php echo htmlspecialchars($int->msg('title')); ?></h2>
        <p><?php echo htmlspecialchars($int->msg('description')); ?></p>
    </div>
    <form action="./" method="POST" class="container" id="searchForm">
        <p><label><?php echo htmlspecialchars($int->msg('form-user') . $sep); ?> <input name="user" value="<?php
        if ($data->Username) {
            print htmlspecialchars($data->Username);
        }
        ?>"></label></p>
        <p><label><?php echo htmlspecialchars($int->msg('form-isPrefixPattern') . $sep); ?> <input name="isPrefixPattern" type="checkbox" value="1"<?php
        if ($data->options['isPrefixPattern']) {
            print ' checked';
        }
        ?>></label></p>
        <p><label><?php echo htmlspecialchars($int->msg('form-from') . $sep); ?> <?php
            $resultSelect = new HtmlSelect([
                'all' => $int->msg('form-from-all'),
                'rc' => $int->msg('form-from-rc'),
                'hr' => $int->msg('form-from-hr'),
            ]);
            $resultSelect->setDefault($data->options['src']);
            $resultSelect->setName('src');
            print $resultSelect->getHTML();
            ?></label></p>
        <p><?php echo htmlspecialchars($int->msg('form-sort') . $sep); ?>
        <label><input name="by" type="radio" value="wiki"<?php
        if ($data->options['by'] !== 'date') {
            print ' checked';
        }
        ?>> <?php echo htmlspecialchars($int->msg('form-sort-wiki')); ?></label>
        <label><input name="by" type="radio" value="date"<?php
        if ($data->options['by'] === 'date') {
            print ' checked';
        }
        ?>> <?php echo htmlspecialchars($int->msg('form-sort-date')); ?></label></p>
        <?php
        if ($data->debug) {
            echo '<input type="hidden" name="debug" value="1">' . "\n";
        }
        ?>
        <?php
        if ($int->getUseRequestParam()) {
            $paramName = $int->getParamName('userlang');
            if (isset($_GET[$paramName]) || isset($_POST[$paramName])) {
                echo Html::element('input', [
                    'type' => 'hidden',
                    'name' => $paramName,
                    'value' => $int->getLang(),
                ]) . "\n";
            }
        }
        ?>
        <p><input type="submit" value="<?php echo htmlspecialchars($int->msg('form-submit')); ?>" class="submitbutton" id="submitButton"/><span id="loadLine" class="form-progress" style="display: none;">&nbsp;</span></p>
    </form>
    <?php
    if ($appError) {
        print '<div class="container error"><p>';
        print 'Error: ' . htmlspecialchars($appError->getMessage());
        print '</p></div>';
    }
    if ($guc) {
        print '<div class="container">';
        print '<p>'.$guc->getWikiCount().' wikis searched. Found edits';
        if ($guc->getResultWikiCount()) {
            print ' from '.$guc->getResultWikiCount().' wikis';
        }
        print '.</p>';
        print '<p>' . htmlspecialchars($int->msg('results-limited', [
            'variables' => [ (string)Contribs::CONTRIB_LIMIT ]
        ])) . '</p>';
        print '<div class="results">';
        $infos = array_filter($guc->getIPInfos());
        if ($infos) {
            print '<table class="box">';
            foreach ($infos as $ip => $info) {
                print '<tr>'
                    . '<td><span class="hostname"></span>' . htmlspecialchars($ip) . '</td>'
                    . '<td>' . (isset($info['host'])
                        ? (' <tt>' . htmlspecialchars($info['host']) .'</tt>')
                        : ''
                    ) . '</td>'
                    . '<td>('
                        . '<a href="https://meta.wikimedia.org/wiki/Special:GlobalBlockList/' . htmlspecialchars($ip) . '" target="_blank">' . htmlspecialchars($int->msg('ipinfo-globalblocklist')) . '</a>'
                        . ' &bull; <a href="https://meta.wikimedia.org/wiki/Special:GlobalBlock/' . htmlspecialchars($ip) . '" target="_blank">' . htmlspecialchars($int->msg('ipinfo-globalblock')) . '</a>'
                    . ')</td>'
                    . '<td>' . (isset($info['asn'])
                        ? (' <a href="http://bgp.he.net/AS' . htmlspecialchars($info['asn']) . '#_whois" target="_blank" rel="noopener noreferrer">AS' . htmlspecialchars($info['asn']) . '</a>')
                        : ''
                    ) . (isset($info['description'])
                        ? (' <a href="https://ipinfo.io/AS' . htmlspecialchars($info['asn']) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($info['description']) . '</a>')
                        : ''
                    ) . '</td>'
                    . '<td>' . (isset($info['range'])
                        ? (' <tt>' . htmlspecialchars($info['range']) .'</tt>')
                        : ''
                    ) . '</td>'
                    . '</tr>';
            }
            if (count($infos) >= 10) {
                print '<tr><td colspan="3"><em>(Limited hostname lookups)</em></td></tr>';
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
        $app->closeAllDBs();
        $formatter->output();
        print '</div>';
        print '</div>';
    }
    if ($data->debug) {
        $app->preShutdown();
        print '<pre class="container guc-debug">';
        $app->printTimes();
        print '</pre>';
    }
    ?>
    <div class="footer">
        by <a href="https://wikitech.wikimedia.org/wiki/User:Luxo">Luxo</a> · <a href="https://meta.wikimedia.org/wiki/User:Krinkle">Krinkle</a>
        <br>
        <a href="https://github.com/wikimedia/labs-tools-guc">Source repository</a> · <a href="https://phabricator.wikimedia.org/tag/guc/">Issue tracker</a>
    </div>
</body>
</html>
