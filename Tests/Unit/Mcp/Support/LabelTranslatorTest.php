<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Support;

use BalatD\DevMcp\Mcp\Support\LabelTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class LabelTranslatorTest extends TestCase
{
    /**
     * @return \Generator<string, array{string}>
     */
    public static function plainLabelProvider(): \Generator
    {
        yield 'human readable' => ['Header'];
        yield 'sentence with colon' => ['Page: Overview'];
        yield 'empty' => [''];
        yield 'path' => ['EXT:core/Resources/Private/Language/locallang.xlf'];
    }

    #[Test]
    #[DataProvider('plainLabelProvider')]
    public function plainLabelsNeverReachTheLanguageService(string $label): void
    {
        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->expects(self::never())->method(self::anything());

        self::assertSame($label, (new LabelTranslator($factory))->translate($label));
    }

    #[Test]
    public function nullStaysNull(): void
    {
        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->expects(self::never())->method(self::anything());

        self::assertNull((new LabelTranslator($factory))->translate(null));
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function referenceProvider(): \Generator
    {
        yield 'LLL reference' => ['LLL:EXT:core/Resources/Private/Language/locallang.xlf:header'];
        // v14 labels may be Symfony translation domain references
        yield 'domain reference' => ['backend.modules.layout:title'];
        yield 'domain reference with dashes' => ['content-blocks.smoke:title'];
    }

    #[Test]
    #[DataProvider('referenceProvider')]
    public function labelReferencesAreResolved(string $label): void
    {
        self::assertSame('Layout', (new LabelTranslator($this->createFactory('Layout')))->translate($label));
    }

    #[Test]
    public function anUnresolvableReferenceFallsBackToTheRawValue(): void
    {
        // v13 hands domain references straight back, and sL() returns '' when it cannot resolve
        $translator = new LabelTranslator($this->createFactory(''));

        self::assertSame('backend.modules.layout:title', $translator->translate('backend.modules.layout:title'));
    }

    #[Test]
    public function aFailingLanguageServiceDoesNotBreakTheTool(): void
    {
        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('no language pack'));

        self::assertSame('LLL:EXT:core/x.xlf:y', (new LabelTranslator($factory))->translate('LLL:EXT:core/x.xlf:y'));
    }

    private function createFactory(string $translation): LanguageServiceFactory
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturn($translation);

        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->method('create')->willReturn($languageService);

        return $factory;
    }
}
