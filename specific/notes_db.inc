<?php
include_once dirname(__DIR__) . '/include/env.php';

$dbuser = (string) getProjectEnvValue('DB_USER');
$dbpass = (string) getProjectEnvValue('DB_PASS');
$dbhost = (string) getProjectEnvValue('DB_HOST');
$dbname = (string) getProjectEnvValue('DB_NAME');
?>
