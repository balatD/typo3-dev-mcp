<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Tool;

use BalatD\DevMcp\Mcp\Tool\MiddlewareStackTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Package\PackageManager;

final class MiddlewareStackToolTest extends TestCase
{
    #[Test]
    public function anUnknownStackIsRejectedBeforeItIsResolved(): void
    {
        $resolver = $this->createMock(MiddlewareStackResolver::class);
        $resolver->expects(self::never())->method(self::anything());

        $tool = new MiddlewareStackTool($resolver, $this->createPackageManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown stack "http".*frontend, backend, core/');

        $tool->execute(['stack' => 'http']);
    }

    #[Test]
    public function theStackIsReversedIntoExecutionOrder(): void
    {
        // the resolver hands back the dispatcher's last-in-first-out order
        $tool = $this->createTool([
            'last-to-run' => 'Vendor\\Last',
            'middle' => 'Vendor\\Middle',
            'first-to-run' => 'Vendor\\First',
        ]);

        $result = $tool->execute([]);

        self::assertIsArray($result);
        self::assertSame('frontend', $result['stack']);
        self::assertSame(3, $result['middlewareCount']);
        self::assertSame(
            ['first-to-run', 'middle', 'last-to-run'],
            array_column($result['middlewares'], 'identifier'),
        );
        self::assertSame([1, 2, 3], array_column($result['middlewares'], 'position'));
    }

    #[Test]
    public function searchFiltersButKeepsThePositionWithinTheWholeStack(): void
    {
        $tool = $this->createTool([
            'c' => 'Vendor\\C',
            'b' => 'Vendor\\B',
            'a' => 'Vendor\\A',
        ]);

        $result = $tool->execute(['search' => 'vendor\\b']);

        self::assertIsArray($result);
        self::assertSame(1, $result['middlewareCount']);
        // position stays 2, so the AI can still see where it sits in the stack
        self::assertSame(2, $result['middlewares'][0]['position']);
        self::assertSame('b', $result['middlewares'][0]['identifier']);
    }

    #[Test]
    public function aFailingResolverIsReportedWithTheStackName(): void
    {
        $resolver = $this->createMock(MiddlewareStackResolver::class);
        $resolver->method('resolve')->willThrowException(new \RuntimeException('cache is broken'));

        $tool = new MiddlewareStackTool($resolver, $this->createPackageManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/stack "backend".*cache is broken/');

        $tool->execute(['stack' => 'backend']);
    }

    /**
     * @param array<string, string> $stack
     */
    private function createTool(array $stack): MiddlewareStackTool
    {
        $resolver = $this->createMock(MiddlewareStackResolver::class);
        $resolver->method('resolve')->willReturn($this->asResolverReturnType($stack));

        return new MiddlewareStackTool($resolver, $this->createPackageManager());
    }

    /**
     * getActivePackages() predates return types, so a bare mock would answer
     * null and the tool would iterate over nothing meaningful.
     */
    private function createPackageManager(): PackageManager
    {
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([]);

        return $packageManager;
    }

    /**
     * v13 declares resolve(): array, v14 declares resolve(): \ArrayObject — a
     * mock has to honour whichever signature is installed.
     *
     * @param array<string, string> $stack
     * @return array<string, string>|\ArrayObject<string, string>
     */
    private function asResolverReturnType(array $stack): array|\ArrayObject
    {
        $returnType = (new \ReflectionMethod(MiddlewareStackResolver::class, 'resolve'))->getReturnType();

        return $returnType instanceof \ReflectionNamedType && $returnType->getName() === \ArrayObject::class
            ? new \ArrayObject($stack)
            : $stack;
    }
}
