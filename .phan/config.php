<?php
$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config-library.php';

// Fix PHP 8 build. https://phabricator.wikimedia.org/T325321
$cfg['plugins'] = [];

$cfg['file_list'] = [
    'index.php',
];
$cfg['directory_list'] = [
    'vendor/krinkle/',
    'vendor/wikimedia/',
    'src/',
];
$cfg['exclude_analysis_directory_list'][] = 'vendor/';

$cfg['minimum_target_php_version'] = '7.4';

return $cfg;
