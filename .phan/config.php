<?php
$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';
$cfg['directory_list'][] = 'vendor/';
$cfg['exclude_analysis_directory_list'][] = 'vendor/';

$cfg['minimum_target_php_version'] = '7.3';

return $cfg;
