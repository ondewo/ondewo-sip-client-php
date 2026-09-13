# Release History

*****************

## Release ONDEWO SIP PHP Client 1.0.0

### New Features

* Initial release of the ONDEWO SIP (SIP Telephony) gRPC client for PHP. The whole client surface
  is generated from the [ondewo-sip-api](https://github.com/ondewo/ondewo-sip-api) protocol buffer definitions by the
  `ondewo-php-proto-compiler` image of
  [ondewo-proto-compiler 5.15.0](https://github.com/ondewo/ondewo-proto-compiler/releases/tag/5.15.0),
  which is vendored as a git submodule and pinned to that tag: protoc's built-in `--php_out` for the messages
  and enums, `grpc_php_plugin` for the `<Service>Client` stubs, and a composer package whose optimized
  classmap autoloader is built and verified inside the image.
* Ships as the composer package `ondewo/sip-client-php`, installable with
  `composer require ondewo/sip-client-php`. Requires PHP >= 8.1 and the `grpc` PHP extension, which every
  generated `<Service>Client` needs because it extends `\Grpc\BaseStub`.
* `make build` reproduces the stubs end to end — pinned submodules, compiler image, generation, ownership
  hand-back and version propagation into `composer.json` — and `make test` gates a change with
  `composer validate`, `php -l` over the hand-written sources, a `check_build` that asserts every `.proto` of
  the API submodule has generated PHP code, and the PHPUnit suite. The same steps run in GitHub Actions across
  PHP 8.1 to 8.4, together with a descriptor load check that calls `initOnce()` on every generated
  `GPBMetadata` class, so a missing transitive import fails CI rather than a consumer's first RPC.
* Hand-written sources live in `auth/` at the repository root, never in the compiler-owned `src/`; the image
  adds that directory to the shipped autoloader's classmap on its own, so the bearer-credential and token
  provider helpers are reachable from the published package.

*****************
