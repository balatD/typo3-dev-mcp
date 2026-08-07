# TYPO3 v13 specifics

- PHP 8.2–8.4, Symfony 7 components, Doctrine DBAL 4.
- **Site sets** (`Configuration/Sets/<Name>/config.yaml`) are the way to ship TypoScript, settings and dependencies. Sites declare `dependencies:` in their `config.yaml`; avoid legacy sys_template stacking and `constants.typoscript` for new work — use `settings.definitions.yaml`.
- Extbase plugins: `list_type` is deprecated — register plugins as content element types (`ExtensionUtility::configurePlugin(..., ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT)`), giving them a real CType.
- Backend modules are registered in `Configuration/Backend/Modules.php`, icons in `Configuration/Icons.php`, console commands and event listeners preferably via `#[AsCommand]` / `#[AsEventListener]` attributes.
- TCA of extensions belongs in `Configuration/TCA/` (own tables) and `Configuration/TCA/Overrides/` (changes to other tables) — never modify `$GLOBALS['TCA']` at runtime elsewhere.
- The frontend is fully PSR-7/PSR-15; access request data via the request object (`$request->getQueryParams()`), not superglobals or `GeneralUtility::_GP()` (removed).
- Use `search_changelog` with `{"version": "13"}` before touching APIs you last used in v11/v12 — v13 removed most long-deprecated interfaces.
