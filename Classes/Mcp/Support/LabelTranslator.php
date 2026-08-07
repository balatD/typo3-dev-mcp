<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Support;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Best-effort resolution of LLL: references in CLI context. TCA labels are
 * mostly LLL keys; showing "Header" instead of a 100-char LLL path keeps
 * tool output readable for the AI. Falls back to the raw value on failure.
 */
final class LabelTranslator
{
    private ?LanguageService $languageService = null;

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {
    }

    public function translate(?string $label): ?string
    {
        if ($label === null || !str_starts_with($label, 'LLL:')) {
            return $label;
        }

        try {
            $translated = $this->getLanguageService()->sL($label);
        } catch (\Throwable) {
            return $label;
        }

        return $translated !== '' ? $translated : $label;
    }

    private function getLanguageService(): LanguageService
    {
        return $this->languageService ??= $this->languageServiceFactory->create('default');
    }
}
