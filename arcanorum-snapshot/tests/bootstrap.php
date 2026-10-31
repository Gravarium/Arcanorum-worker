<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

$testPublicBaseUrl = 'https://arcanorum.example.test';
$_SERVER['PUBLIC_BASE_URL'] = $testPublicBaseUrl;
$_ENV['PUBLIC_BASE_URL'] = $testPublicBaseUrl;
putenv('PUBLIC_BASE_URL='.$testPublicBaseUrl);
