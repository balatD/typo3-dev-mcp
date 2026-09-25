<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

/**
 * Runs against a site with the fluid_styled_content set, so there are real
 * constants and setup to address by path.
 */
final class TypoScriptToolTest extends AbstractToolTestCase
{
    protected function siteConfiguration(): string
    {
        return "dependencies:\n  - typo3/fluid-styled-content\n" . parent::siteConfiguration();
    }

    #[Test]
    public function aConstantIsAddressedByItsFullDottedKey(): void
    {
        $result = $this->getTool('typoscript')->execute(['section' => 'constants', 'path' => 'styles.content.defaultHeaderType']);

        self::assertSame('2', (string)$result['value']);
    }

    #[Test]
    public function aConstantPrefixReturnsTheMatchingSubset(): void
    {
        $result = $this->getTool('typoscript')->execute(['section' => 'constants', 'path' => 'styles.content.textmedia']);

        self::assertArrayHasKey('styles.content.textmedia.maxW', $result['value']);
        foreach (array_keys($result['value']) as $key) {
            self::assertStringStartsWith('styles.content.textmedia.', $key);
        }
    }

    #[Test]
    public function anUnknownConstantNamesTheTopLevelPrefixes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No constant matches "nope\.x".*styles/s');

        $this->getTool('typoscript')->execute(['section' => 'constants', 'path' => 'nope.x']);
    }

    #[Test]
    public function aMissingSetupPathListsWhatExistsAndWhereItCameFrom(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/segment "nothing".*contentElement.*site sets: typo3\/fluid-styled-content/s');

        $this->getTool('typoscript')->execute(['path' => 'lib.nothing']);
    }
}
