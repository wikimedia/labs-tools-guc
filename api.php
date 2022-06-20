<?php

use Krinkle\Intuition\Intuition;
use Krinkle\Toolbase\LabsDB;

require_once __DIR__ . '/vendor/autoload.php';

global $kgReq;

/**
 * Init
 * -------
 */

// Reset PHP error handling
ini_set('display_errors', 0);

// Reset timezone
date_default_timezone_set('UTC');

// Reset default headers (strips "x-powered-by" header)
header_remove();

// Allow CORS
$kgReq->setHeader('Access-Control-Allow-Origin', '*');

// JSON response body
$kgReq->setHeader('Content-Type', 'application/json; charset=utf-8');

// Apply a blind TTL for any edge or client-side HTTP cache
$maxAge = 10 * 60;
$kgReq->setHeader(
    'Cache-Control',
    'public, max-age=' . intval($maxAge) . ', s-maxage=' . intval($maxAge)
);


/**
 * Fetch data
 * -------
 */

try {
    if ($kgReq->getVal('q') !== 'replag') {
        http_response_code(400);
        $data = [
            'error' => 'Invalid query. Available query: q=replag',
        ];
    } else {
        $data = [
            'lagged' => false,
            'lag' => [],
        ];
        $metaRows = LabsDB::query(
            LabsDB::getMetaDB(),
            'SELECT DISTINCT(slice) as slice FROM wiki ORDER BY slice ASC'
        );
        if ($metaRows) {
            foreach ($metaRows as $row) {
                // FIXME: Workaround https://phabricator.wikimedia.org/T176686
                $host = preg_replace(
                    '/\.labsdb$/',
                    '.web.db.svc.eqiad.wmflabs',
                    $row['slice']
                );
                list($shard) = explode('.', $host);
                $res = LabsDB::query(
                    LabsDB::getConnection($host, 'heartbeat'),
                    'SELECT lag FROM heartbeat WHERE shard = ?',
                    [ $shard ]
                );
                if ($res) {
                    $lag = (int)$res[0]['lag'];
                    if (!isset($data['lag'][$shard]) || $data['lag'][$shard] < $lag) {
                        $data['lag'][$shard] = $lag;
                    }
                }
            }
        }
        $max = max($data['lag']);
        if ($max > 0) {
            $int = new Intuition(array(
                'domain' => 'guc',
            ));
            $int->registerDomain('guc', __DIR__ . '/i18n');

            $data['lagged'] = [
                'max' => $max,
                'html' => $int->msg('results-lag-warning', [
                    'externallinks' => true,
                    'escape' => 'html',
                ]),
            ];
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    $data = [
        'error' => strval($e),
    ];
}


/**
 * Output data
 * -------
 */

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
