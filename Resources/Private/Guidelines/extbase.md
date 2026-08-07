# Extbase

- Controllers get dependencies via constructor injection (registered automatically through `Configuration/Services.yaml` autowiring). Actions return `ResponseInterface` — typically `$this->htmlResponse()`.
- Models are plain typed entities extending `AbstractEntity`; repositories extend `Repository`. For queries beyond simple `findBy*`, drop to the Doctrine `QueryBuilder` inside the repository rather than fighting Extbase's query object.
- Validation via `#[Validate]` attributes on properties/action parameters; don't hand-validate in actions.
- Register plugins as content element types (see the version-specific section) with `configurePlugin()` in `ext_localconf.php` and `registerPlugin()` in `Configuration/TCA/Overrides/tt_content.php`.
- FlexForm settings reach the controller via `$this->settings`; keep FlexForms small — real configuration belongs in site sets / TypoScript.
- Never instantiate repositories or services with `GeneralUtility::makeInstance()` inside Extbase classes — constructor injection covers it.
- Uncached ("USER_INT") behavior is declared per action in `configurePlugin()`'s `$nonCacheableControllerActions` — remember everything else is cached.
