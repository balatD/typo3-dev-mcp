# Testing

- Tests use `typo3/testing-framework`: `Tests/Unit/` extends `TYPO3\TestingFramework\Core\Unit\UnitTestCase` (no DB, no TYPO3 bootstrap), `Tests/Functional/` extends `FunctionalTestCase` (real DB, extensions loaded via `$testExtensionsToLoad`).
- Unit-test pure logic; functional-test anything touching the database, TCA, DataHandler or the container. Don't mock what a functional test can exercise for real.
- Functional fixtures are CSV data sets (`->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv')`); assert with `assertCSVDataSet()`.
- PHPUnit 10/11 style: attributes (`#[Test]`, `#[DataProvider('...')]`) instead of doc-comment annotations.
- Run tests through the project's runner (Docker-based `Build/Scripts/runTests.sh` or the composer scripts if defined) so the environment matches CI — not a bare host `phpunit`.
