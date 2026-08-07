# Fluid templates

- Fluid escapes all output by default. `f:format.raw` / `{var -> f:format.raw()}` only on trusted, already-sanitized HTML — never on user input.
- Templates live in `Resources/Private/{Templates,Partials,Layouts}`; reuse markup via partials (`<f:render partial="..." arguments="{...}"/>`), not copy-paste.
- Use `f:image` / `f:media` for FAL resources (they handle processing/cropping); never hand-build `/fileadmin/` paths.
- Translations via `f:translate key="LLL:EXT:my_ext/Resources/Private/Language/locallang.xlf:key"` — no hardcoded strings in templates shipped by extensions.
- Keep logic out of templates: no business decisions via chained inline ViewHelpers — prepare data in the controller/DataProcessor and pass it in.
- Custom ViewHelpers extend `AbstractViewHelper`, declare arguments in `initializeArguments()`, and are namespaced in the template (`xmlns:my="http://typo3.org/ns/Vendor/MyExt/ViewHelpers"`).
- Condition syntax: `<f:if condition="{thing}">...<f:else>...</f:else></f:if>`; for multiple branches prefer `f:switch`.
