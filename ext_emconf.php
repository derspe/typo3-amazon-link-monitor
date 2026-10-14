<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Amazon Link Monitor',
    'description' => 'Monitors Amazon (affiliate) links on your TYPO3 site: crawls the sitemap, checks every link via the Amazon Creators API or HTTP, keeps a status history and reports broken links in a backend module and dashboard widgets.',
    'category' => 'module',
    'author' => 'Frank Specht',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.4.99',
            'backend' => '14.3.0-14.4.99',
            'dashboard' => '14.3.0-14.4.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'scheduler' => '14.3.0-14.4.99',
        ],
    ],
];
