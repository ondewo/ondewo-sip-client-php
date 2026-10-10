# Release History

*****************

## Release ONDEWO SIP PHP Client 5.4.1

### Improvements

* Tracking API Version [5.4.0](https://github.com/ondewo/ondewo-sip-api/releases/tag/5.4.0) ( [Documentation](https://ondewo.github.io/ondewo-sip-api/) )
* New `Ondewo\Sip\Auth\ClientConfig` (hand-written, in `auth/`) builds the target and the `$opts` of every
  generated `<Service>Client` for plaintext, TLS and mutual TLS, with the same rules as the other ONDEWO SDKs:
  * `grpcCert`, `grpcClientCert` and `grpcClientKey` take PEM **content**, never a file path; CRLF PEMs work. An
    empty `grpcCert` means the platform's default trust store.
  * `grpcClientCert` / `grpcClientKey` are both-or-neither: half a pair throws `InvalidArgumentException` when the
    config is built and again in `channelCredentials()`, before anything reaches ext-grpc (grpc-core aborts the
    process on a key without its certificate).
  * `useSecureChannel: false` with a client identity throws instead of silently dropping it; a plaintext channel
    logs a warning naming `host:port` on a PSR-3 logger if given, otherwise through `error_log()`.
  * `channelOptions()` refuses a `credentials` option of its own; every other option is passed through.
  * Exception messages name the field and `host:port`, never a PEM or the config. `(string)`, `var_dump()` /
    `print_r()` and `json_encode()` show a non-empty `grpcClientKey` as `***REDACTED***`; `serialize()`,
    `var_export()` and `(array)` are not redacted (documented). The key parameter is `#[\SensitiveParameter]`.
  * `target()` brackets a bare IPv6 literal (`[::1]:50051`) and leaves `[...]` / `scheme:` hosts alone.
  * `ClientConfig::DEFAULT_CHANNEL_OPTIONS` matches ondewo-client-utils-python: message size 2³¹-1 both ways,
    keepalive 30 s with `max_pings_without_data` 2 and no pings without calls, keepalive and HTTP/2 ping timeout
    20 s, reconnect backoff capped at 5 s. Documented gap: no per-method retry policy, only gRPC's transparent
    retries.
* `composer.json` suggests `psr/log` for the insecure-channel warning.
* README section "TLS, mutual TLS and certificates": modes, an openssl test PKI, security notes and troubleshooting.
* `make publish` / the release workflow: Packagist's `202 Accepted` counts as success, and the HTTP-code check no
  longer runs in a separate shell with an empty code (a comment inside the continued shell block split it), which
  failed every publish.
* Proto compiler pinned to [5.15.2](https://github.com/ondewo/ondewo-proto-compiler/releases/tag/5.15.2).

### Tests

* `tests/Auth/ClientConfigTest.php` covers `ClientConfig` (100% coverage of `auth/`).
* `tests/Tls/MutualTlsHandshakeTest.php`: real handshakes with a PKI generated at test time (ext-openssl) against a
  python grpcio server: TLS, mutual TLS, CRLF PEMs and `[::1]` connect; a missing or foreign client certificate, a
  wrong CA and the system roots against the test CA fail as `UNAVAILABLE`. CI installs grpcio 1.82.1.
* `tests/ReleaseNotesTest.php` pins the `RELEASE.md` heading and `*****` separator the GitHub release-notes slice
  relies on.

*****************

## Release ONDEWO SIP PHP Client 5.4.0

### New Features

* Initial release of the ONDEWO SIP (SIP Telephony) gRPC client for PHP. The whole client surface
  is generated from the [ondewo-sip-api](https://github.com/ondewo/ondewo-sip-api) protocol buffer definitions by the
  `ondewo-php-proto-compiler` image of
  [ondewo-proto-compiler 5.15.1](https://github.com/ondewo/ondewo-proto-compiler/releases/tag/5.15.1),
  which is vendored as a git submodule and pinned to that tag: protoc's built-in `--php_out` for the messages
  and enums, `grpc_php_plugin` for the `<Service>Client` stubs, and a composer package whose optimized
  classmap autoloader is built and verified inside the image.
* The generated stubs are **committed** under `src/` — 11 files covering the `ondewo.sip.Sip` service, its
  messages and enums, plus the one `GPBMetadata` descriptor class that bootstraps them. The `google.protobuf.*`
  types the protos import are well-known and come from the `google/protobuf` composer package, so nothing under
  `google/` is generated here. Packagist serves the tree of a git tag verbatim and composer has no build step,
  so stubs that are not committed do not exist for anybody who installs the package.
* Ships as the composer package `ondewo/sip-client-php`, installable with
  `composer require ondewo/sip-client-php`. Requires PHP >= 8.1 and the `grpc` PHP extension, which every
  generated `<Service>Client` needs because it extends `\Grpc\BaseStub`, and — for the JSON wire format only —
  `ext-bcmath`.
* Hand-written sources live in `auth/` at the repository root, never in the compiler-owned `src/`; the image
  adds that directory to the shipped autoloader on its own. `Ondewo\Sip\Auth\BearerTokenAuthenticator`
  turns a token into the `$opts` array a generated stub is constructed with and stamps
  `authorization: Bearer <token>` onto the metadata of every call. The namespace is this product's own, not a
  shared `Ondewo\Nlu\Auth`, so installing several ONDEWO PHP clients side by side cannot collide.
* `make build` reproduces the stubs end to end — pinned submodules, compiler image, generation, ownership
  hand-back and version propagation into `composer.json`.

### Testing

* A real PHPUnit suite under `tests/` exercises the generated code rather than asserting around it: every
  committed class is loaded through the autoloader, `initOnce()` is called on every `GPBMetadata` descriptor
  (so a missing transitive import fails CI rather than a consumer's first RPC), messages are round-tripped
  through the binary and JSON wire formats, enum zero constants are pinned, and SipClient is
  constructed against a dummy channel and checked for the 11 RPCs and the arities `ondewo.sip.Sip` declares.
* ondewo-sip-api declares no streaming RPC and no `proto3 optional` field anywhere, so the
  corresponding cases of the reference suite (ondewo-nlu-client-php) were dropped rather than
  faked; a guard asserts instead that every generated RPC is still unary.
* `make coverage` measures the hand-written sources (`phpunit.xml.dist`'s `<source>` is `auth/`) and fails the
  build below 100% line coverage. Generated code is excluded from that metric and covered by the tests above.
* GitHub Actions runs `composer validate`, `php -l`, the suite and the coverage gate on PHP 8.1 and 8.4
  against the committed stubs — no docker image is built and no submodule is checked out there. The previous
  revision of that workflow wrapped every step in an `if [ ! -d src ]` guard, so a repository holding no PHP
  at all reported success; the guards are gone and CI now compiles and tests the committed tree or goes red.
* The job installs `ext-bcmath` alongside `ext-grpc`: `google/protobuf` only *suggests* bcmath, but its
  pure-PHP JSON parser range-checks every integer with `bccomp()`, so `mergeFromJsonString()` on a message
  with an int field dies without it. The suite covers that path explicitly.
* The dev tool chain (PHPUnit, the coverage gate) lives in its own composer project under `tools/`. It is
  deliberately **not** `require-dev` in the root manifest: `composer update --no-dev` still resolves dev
  requirements, and the compiler image resolves the merged manifest with the network disabled, so one
  `require-dev` entry would break `make generate_ondewo_protos`.

### Publishing

* Published to [Packagist](https://packagist.org/packages/ondewo/sip-client-php) as `ondewo/sip-client-php`.
  Packagist accepts no upload — it serves the tree of a git tag — so `make publish` validates the package and
  then pings `https://packagist.org/api/update-package` with `PACKAGIST_USERNAME` + `PACKAGIST_API_TOKEN` to
  have the new tag crawled. It is wired into `make release` after `push_to_gh`, and
  `make run_release_with_devops` reads both credentials from `account_packagist.env` in the
  `ondewo-devops-accounts` repository. The package still has to be **submitted once by hand**; see README
  "Publishing to Packagist".
* `make packagist_dry_run` is the credential-free half of that path and runs in CI on every push:
  `composer validate`, `composer validate --strict` with the three deliberate warnings enumerated (the
  `version` field and the two exact `google/protobuf` / `grpc/grpc` pins — anything new fails the build), the
  agreement between `ONDEWO_SIP_VERSION`, `composer.json`, `RELEASE.md` and the git tag, and the exact update
  payload the real publish POSTs.
* `.github/workflows/release.yml` runs on a bare `X.Y.Z` tag push, re-runs the dry run and the full test
  suite against the tagged tree and only then publishes, using GitHub secrets. A missing secret fails its
  first step with an explicit `::error::` instead of posting an unauthenticated request.

*****************
