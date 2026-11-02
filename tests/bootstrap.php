<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
	throw new RuntimeException('Run "composer install" in the portalcheck app directory before running PHPUnit.');
}
require_once $autoload;

if (!defined('PHPUNIT_RUNNING')) {
	define('PHPUNIT_RUNNING', true);
}
// deterministic Ed25519 fixture key for PTL2 codec tests
putenv('PTL_VENDOR_PUBLIC_KEY_B64=' . \OCA\PortalCheck\Config\VendorPublicKey::TEST_PUBLIC_KEY_B64);
