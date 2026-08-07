<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ConditionalToolInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Boost analog: tinker. Executes arbitrary PHP inside the booted TYPO3
 * application.
 *
 * Doubly guarded: requires Development application context AND an explicit
 * opt-in (env DEV_MCP_ALLOW_TINKER=1 or extension configuration allowTinker).
 * When not enabled the tool is not announced at all.
 */
final class TinkerTool implements ConditionalToolInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function getName(): string
    {
        return 'tinker';
    }

    public function getDescription(): string
    {
        return 'Execute PHP code inside the booted TYPO3 application (like Laravel tinker). Full DI '
            . 'container and TYPO3 APIs are available, e.g. via '
            . 'TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(...). End the code with a return '
            . 'statement to get a value back; echoed output is captured separately. State changes are real '
            . '— prefer the read-only tools unless you actually need to execute something.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'PHP code without opening tag, e.g. "return \\TYPO3\\CMS\\Core\\Utility\\GeneralUtility::makeInstance(...)->...;"',
                ],
            ],
            'required' => ['code'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        if (!Environment::getContext()->isDevelopment()) {
            return false;
        }

        if (getenv('DEV_MCP_ALLOW_TINKER') === '1') {
            return true;
        }

        try {
            return (bool)$this->extensionConfiguration->get('dev_mcp', 'allowTinker');
        } catch (\Throwable) {
            return false;
        }
    }

    public function execute(array $arguments): mixed
    {
        $code = (string)($arguments['code'] ?? '');
        if (trim($code) === '') {
            throw new \RuntimeException('Argument "code" must not be empty.');
        }

        ob_start();
        try {
            $result = eval($code);
        } catch (\Throwable $e) {
            ob_end_clean();

            throw new \RuntimeException(
                $e::class . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
            );
        }
        $output = ob_get_clean();

        return [
            'result' => $this->normalizeResult($result),
            'output' => $output !== '' ? $output : null,
        ];
    }

    private function normalizeResult(mixed $result): mixed
    {
        if ($result === null || \is_scalar($result)) {
            return $result;
        }

        // Arrays/objects: prefer JSON-shaped data, fall back to var_export
        $encoded = json_encode($result);
        if ($encoded !== false) {
            return json_decode($encoded, true);
        }

        return var_export($result, true);
    }
}
