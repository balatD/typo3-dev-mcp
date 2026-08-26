<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Support;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Best-effort resolution of label references in CLI context. TCA labels are
 * mostly LLL keys; showing "Header" instead of a 100-char LLL path keeps
 * tool output readable for the AI. Falls back to the raw value on failure.
 */
final class LabelTranslator
{
    /**
     * Since v14 labels may also be Symfony translation domain references
     * ("backend.modules.layout:title"). v13 hands those back unchanged, so the
     * same call works on both.
     */
    private const DOMAIN_REFERENCE_PATTERN = '/^[a-z0-9_.\-]+:[a-z0-9_.\-]+$/i';

    private ?LanguageService $languageService = null;

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function translate(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        if (!str_starts_with($label, 'LLL:') && preg_match(self::DOMAIN_REFERENCE_PATTERN, $label) !== 1) {
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
