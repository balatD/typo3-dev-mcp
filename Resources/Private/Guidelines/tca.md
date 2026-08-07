# TCA

- Read existing TCA with the `tca_schema` tool before adding or changing fields — reuse existing columns and follow the table's conventions.
- Use the modern dedicated column types (v12+): `email`, `link`, `datetime`, `number`, `color`, `file`, `category`, `password`, `uuid`, `json`, `checkbox`, `radio` — not `input` with `eval` hacks.
- File references: `'type' => 'file'` with `allowed` file extensions — the legacy `internal_type`/`ExtensionManagementUtility::getFileFieldTCAConfig` pattern is gone.
- New tables: own extension `Configuration/TCA/<table>.php`; changes to foreign tables (pages, tt_content, ...): `Configuration/TCA/Overrides/<table>.php`. Never mutate `$GLOBALS['TCA']` at runtime.
- Every table needs sensible `ctrl` values: `label`, `tstamp`, `crdate`, `delete`, `enablecolumns` (disabled/starttime/endtime), `sortby` or `default_sortby`, `searchFields`, and language fields when translatable.
- Labels are `LLL:` references into `Resources/Private/Language/locallang_*.xlf` — no hardcoded strings.
- Select/relation fields: `foreign_table` (+ `MM` for many-to-many) with `foreign_table_where` ordering; items arrays use the associative `['label' => ..., 'value' => ...]` format.
- After TCA changes: `flush_cache` group `system`, and for new columns also update `ext_tables.sql` and run `typo3 extension:setup`.
