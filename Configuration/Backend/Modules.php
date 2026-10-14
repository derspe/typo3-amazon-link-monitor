<?php

use Derspe\AmazonLinkMonitor\Controller\Backend\ModuleController;

return [
    'amazon_link_monitor' => [
        'parent' => 'site',
        'position' => ['after' => '*'],
        'access' => 'user',
        'path' => '/module/site/amazon-link-monitor',
        'iconIdentifier' => 'module-amazon-link-monitor',
        'labels' => 'derspe_amazon_link_monitor.module',
        'routes' => [
            '_default' => [
                'target' => ModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
