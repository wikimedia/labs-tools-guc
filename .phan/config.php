<?php
$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config-library.php';

$cfg['minimum_target_php_version'] = '8.3';

$cfg['suppress_issue_types'][] = 'PhanThrowTypeAbsent';
$cfg['suppress_issue_types'][] = 'PhanUnusedVariableCaughtException';

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

return $cfg;
