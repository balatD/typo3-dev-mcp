# TYPO3 v14 specifics

- PHP 8.3+, Doctrine DBAL 4, Fluid 5 in strict mode: undefined variables and invalid ViewHelper usage now fail loudly instead of rendering empty — fix the template, don't suppress.
- **`list_type` is removed.** All plugins are content element types (CTypes). If code or TCA still references `list_type`, migrate it (see `search_changelog` for the migration path).
- Classic-mode (non-Composer) extensions require a `composer.json`; `ext_emconf.php` alone is no longer sufficient.
- The default frontend theme shipped with the introduction/demo setups is Camino (site-set opt-in); bootstrap_package remains available as a site set.
- Extbase actions support `#[Authorize]` and `#[RateLimit]` attributes for access control at the controller level — prefer them over hand-rolled checks.
- Backend UI was redesigned (Context Panel, new DocHeader); backend module code using deprecated DocHeader APIs needs review.
- CKEditor 47 — RTE plugin/config code from CKEditor 41/42 eras may need updates.
- Everything v13 (site sets, PSR-14 events, attribute-based registration) applies unchanged. When an API errors after the upgrade, `search_changelog` with `{"version": "14"}` almost always has the answer.
