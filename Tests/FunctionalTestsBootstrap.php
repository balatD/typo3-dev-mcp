<?php

declare(strict_types=1);

/*
 * Adapted from typo3/testing-framework's boilerplate
 * (Resources/Core/Build/FunctionalTestsBootstrap.php), which extensions are
 * explicitly encouraged to copy rather than reference.
 */
(static function (): void {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
