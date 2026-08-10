<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * The PSR-15 stacks are assembled from every package's
 * Configuration/RequestMiddlewares.php and then ordered by before/after
 * constraints, so the effective order is not readable from any single file, and
 * core exposes no CLI for it.
 *
 * The resolved stack and the raw declarations are combined here: the resolver
 * knows the final order but discards before/after and the declaring package.
 */
final class MiddlewareStackTool implements ToolInterface
{
    private const STACKS = ['frontend', 'backend', 'core'];

    public function __construct(
        private readonly MiddlewareStackResolver $middlewareStackResolver,
        private readonly PackageManager $packageManager,
    ) {
    }

    public function getName(): string
    {
        return 'middleware_stack';
    }

    public function getDescription(): string
    {
        return 'Get the resolved PSR-15 middleware stack ("frontend", "backend" or "core") in the order it '
            . 'actually executes, with each middleware\'s identifier, class, declaring package and its '
            . 'before/after constraints. Use this before adding a middleware, to find where a request is '
            . 'modified, or to check whether a middleware is disabled.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'stack' => [
                    'type' => 'string',
                    'enum' => self::STACKS,
                    'description' => 'Which stack to resolve (default "frontend")',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Only return middlewares whose identifier or class contains this string',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        $stack = (string)($arguments['stack'] ?? 'frontend');
        if (!\in_array($stack, self::STACKS, true)) {
            throw new \RuntimeException(
                'Unknown stack "' . $stack . '". Valid stacks: ' . implode(', ', self::STACKS) . '.',
            );
        }

        $search = strtolower(trim((string)($arguments['search'] ?? '')));
        $declarations = $this->collectDeclarations($stack);
        $resolved = $this->resolveStack($stack);

        $middlewares = [];
        $position = 0;
        foreach ($resolved as $identifier => $target) {
            $identifier = (string)$identifier;
            $target = (string)$target;
            ++$position;

            if ($search !== ''
                && !str_contains(strtolower($identifier), $search)
                && !str_contains(strtolower($target), $search)
            ) {
                continue;
            }

            $declaration = $declarations[$identifier] ?? [];
            $middlewares[] = array_filter([
                'position' => $position,
                'identifier' => $identifier,
                'class' => $target,
                'package' => $declaration['package'] ?? null,
                'before' => $declaration['before'] ?? null,
                'after' => $declaration['after'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $disabled = [];
        foreach ($declarations as $identifier => $declaration) {
            if (($declaration['disabled'] ?? false) === true) {
                $disabled[] = $identifier;
            }
        }

        return array_filter([
            'stack' => $stack,
            'middlewareCount' => \count($middlewares),
            'middlewares' => $middlewares,
            'disabled' => $disabled !== [] ? $disabled : null,
            'hint' => 'Listed in execution order: position 1 sees the request first and the response last. '
                . 'Disabled middlewares are configured but not part of the stack.',
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, string> identifier => target class, in execution order
     */
    private function resolveStack(string $stack): array
    {
        try {
            $resolved = $this->middlewareStackResolver->resolve($stack);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Middleware stack "' . $stack . '" could not be resolved: ' . $e->getMessage());
        }

        // v13 returns a plain array here, v14 an ArrayObject — iterator_to_array
        // takes both since PHP 8.2
        $resolved = iterator_to_array($resolved);

        // the resolver hands back the dispatcher's last-in-first-out order
        return array_reverse($resolved, true);
    }

    /**
     * Raw declarations straight from the packages, for the before/after
     * constraints and the disabled flag that the resolver drops.
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectDeclarations(string $stack): array
    {
        $declarations = [];

        foreach ($this->packageManager->getActivePackages() as $package) {
            $file = $package->getPackagePath() . 'Configuration/RequestMiddlewares.php';
            if (!is_file($file)) {
                continue;
            }

            $configuration = @include $file;
            if (!\is_array($configuration) || !\is_array($configuration[$stack] ?? null)) {
                continue;
            }

            foreach ($configuration[$stack] as $identifier => $middleware) {
                if (!\is_array($middleware)) {
                    continue;
                }

                $declarations[(string)$identifier] = array_filter([
                    'package' => $package->getPackageKey(),
                    'before' => ($middleware['before'] ?? []) !== [] ? $middleware['before'] : null,
                    'after' => ($middleware['after'] ?? []) !== [] ? $middleware['after'] : null,
                    'disabled' => ($middleware['disabled'] ?? false) === true ? true : null,
                ], static fn (mixed $value): bool => $value !== null);
            }
        }

        return $declarations;
    }
}
