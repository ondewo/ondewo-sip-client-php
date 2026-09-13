# Development tooling

`tools/composer.json` holds the dev-only tool chain - PHPUnit and the coverage threshold gate -
in a **separate** composer project.

## Why not `require-dev` in the root `composer.json`?

`make generate_ondewo_protos` runs the `ondewo-php-proto-compiler` image, which merges this
repository's `composer.json` with its own defaults and then resolves the result **offline**
(`COMPOSER_DISABLE_NETWORK=1`) against the dependency cache the image pre-warmed at build time.

`composer update --no-dev` still **resolves** `require-dev` in order to write a complete lock
file, so a single `require-dev` entry in the root manifest makes that offline resolution fail with

```text
Network disabled, request canceled: https://packagist.org/providers/phpunit/phpunit.json
```

and takes the whole stub generation down with it. Keeping the dev tools in their own manifest
leaves the published package's manifest exactly what the compiler emits, and lets CI install the
tools with a plain network-enabled composer run.

## Usage

```bash
make install_dev_tools     # composer update --working-dir=tools
make phpunit               # tools/vendor/bin/phpunit
make coverage              # phpunit --coverage-clover + coverage-check gate
```

`tools/vendor/` is git-ignored.
