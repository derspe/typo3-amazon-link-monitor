<?php

use Derspe\AmazonLinkMonitor\Controller\Backend\ApiController;

return [
    'derspe_amazon_link_monitor_api' => [
        'path' => '/derspe-amazon-link-monitor/api',
        'target' => ApiController::class . '::handle',
        'methods' => ['GET', 'POST'],
    ],
];
