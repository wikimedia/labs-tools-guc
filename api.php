<?php

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
        $servers = [
            's1' => 's1.labsdb',
            's2' => 's2.labsdb',
            's3' => 's3.labsdb',
            's4' => 's4.labsdb',
            's5' => 's5.labsdb',
            's6' => 's6.labsdb',
            's7' => 's7.labsdb',
        ];
        $data = [
            'lagged' => false,
        ];
        foreach ($servers as $cluster => $host) {
            $conn = \LabsDB::getConnection($host, 'heartbeat');
            $res = \LabsDB::query(
                $conn,
                'SELECT lag FROM heartbeat WHERE shard = ?',
                [ $cluster ]
            );
            $data['lag'][$cluster] = (int)$res[0]['lag'];
        }
        $max = max($data['lag']);
        if ($max > 0) {
            $int = new Intuition(array(
                'domain' => 'guc',
                'globalfunctions' => false,
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
