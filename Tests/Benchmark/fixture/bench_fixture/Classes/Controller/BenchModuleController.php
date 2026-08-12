<?php

declare(strict_types=1);

namespace BalatD\BenchFixture\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;

/**
 * Backing controller for the fixture backend module. The module only has to
 * exist and be routable — `backend_modules` reports registration, not output.
 */
final class BenchModuleController
{
    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse('<h1>Benchmark fixture</h1>');
    }

    public function detailAction(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse('<h1>Benchmark fixture — detail</h1>');
    }
}
