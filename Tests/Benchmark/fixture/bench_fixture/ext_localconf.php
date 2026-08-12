<?php

declare(strict_types=1);

defined('TYPO3') or die();

// FlexForm registration lives in Configuration/TCA/Overrides/tt_content.php.
// Calling addPiFlexFormValue() here touches $GLOBALS['TCA'] before TCA is
// loaded, which is a fatal "Undefined global variable $TCA" on TYPO3 13.
