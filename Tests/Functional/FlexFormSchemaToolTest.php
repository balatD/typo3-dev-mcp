<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

final class FlexFormSchemaToolTest extends AbstractToolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tt_content_flexform.csv');
    }

    #[Test]
    public function aRecordsStoredValuesAreComparedWithItsStructure(): void
    {
        $result = $this->getTool('flexform_schema')->execute(['uid' => 10]);

        self::assertSame(10, $result['uid']);
        self::assertSame(['sDEF.xmlTitle' => 'Hello'], $result['values']);
        self::assertSame(['sDEF.settings.limit' => '9'], $result['orphanedValues']);
        self::assertArrayNotHasKey('fieldsWithoutValue', $result);
    }

    #[Test]
    public function fieldsTheRecordHasNoValueForAreListed(): void
    {
        $result = $this->getTool('flexform_schema')->execute(['uid' => 11]);

        self::assertArrayHasKey('sDEF.xmlTitle', $result['fieldsWithoutValue']);
        self::assertArrayNotHasKey('orphanedValues', $result);
    }

    #[Test]
    public function anUnknownRecordIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tt_content record with uid 99');

        $this->getTool('flexform_schema')->execute(['uid' => 99]);
    }
}
