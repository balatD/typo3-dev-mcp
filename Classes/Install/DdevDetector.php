<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Install;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Detects whether this project runs under DDEV — either from inside the web
 * container (env markers) or from the host (.ddev/ directory). Decides how
 * the MCP server must be launched by the AI client on the host.
 */
final class DdevDetector
{
    public function isDdevProject(): bool
    {
        return $this->isInsideDdevContainer()
            || is_file(Environment::getProjectPath() . '/.ddev/config.yaml');
    }

    public function isInsideDdevContainer(): bool
    {
        return getenv('IS_DDEV_PROJECT') === 'true' || getenv('DDEV_SITENAME') !== false;
    }
}
