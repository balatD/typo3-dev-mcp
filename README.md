# T3Boost

AI development helper for TYPO3 v13/v14 — an MCP server that gives Claude Code
and other MCP clients live insight into your TYPO3 installation: TCA, database
schema, site configuration, TypoScript, logs and the core changelog.
Inspired by [laravel/boost](https://github.com/laravel/boost).

**Development-only tooling. Do not install in production.**

## Installation

```bash
composer require --dev t3boost/t3boost
vendor/bin/typo3 boost:install
```

`boost:install` registers the MCP server with your AI assistant (DDEV is
auto-detected) and installs composed, version-specific AI guidelines.

## Status

Work in progress — full documentation follows with the first release.
