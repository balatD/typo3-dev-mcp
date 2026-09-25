<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

final class TcaSchemaToolTest extends AbstractToolTestCase
{
    #[Test]
    public function aRecordTypeListsItsFormFieldsInOrder(): void
    {
        $result = $this->getTool('tca_schema')->execute(['table' => 'tt_content', 'type' => 'textmedia']);

        self::assertSame('textmedia', $result['recordType']);
        self::assertSame('Text & Media', $result['recordTypeLabel']);

        $fields = array_keys($result['fields']);
        self::assertSame('CType', $fields[0], 'The general palette comes first in the textmedia form.');
        self::assertContains('assets', $fields);
        self::assertLessThan(array_search('bodytext', $fields, true), array_search('header', $fields, true));
    }

    #[Test]
    public function aFieldWithinARecordTypeCarriesThatTypesOverrides(): void
    {
        $result = $this->getTool('tca_schema')->execute(['table' => 'tt_content', 'type' => 'textmedia', 'field' => 'bodytext']);

        self::assertTrue($result['configuration']['enableRichtext'] ?? false, 'textmedia enables the RTE on bodytext via columnsOverrides.');
    }

    #[Test]
    public function anUnknownRecordTypeListsTheValidOnes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Record type "nope".*textmedia/s');

        $this->getTool('tca_schema')->execute(['table' => 'tt_content', 'type' => 'nope']);
    }
}
