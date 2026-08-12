<?php

declare(strict_types=1);

use BalatD\BenchFixture\Controller\BenchModuleController;

return [
    'web_benchfixture' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'path' => '/module/web/benchfixture',
        'iconIdentifier' => 'content-widget-list',
        'labels' => [
            'title' => 'Benchmark Fixture',
            'shortDescription' => 'Fixture module for the typo3-dev-mcp benchmark',
        ],
        'routes' => [
            '_default' => [
                'target' => BenchModuleController::class . '::overviewAction',
            ],
            'detail' => [
                'path' => '/detail',
                'target' => BenchModuleController::class . '::detailAction',
            ],
        ],
    ],
];
