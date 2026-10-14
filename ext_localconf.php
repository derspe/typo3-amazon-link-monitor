<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') or die();

// Creators API access tokens
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['derspe_amazon_link_monitor'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'options' => [
        'defaultLifetime' => 3000,
    ],
];
