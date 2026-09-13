<div align="center">
  <table>
    <tr>
      <td>
        <a href="https://www.ondewo.com">
            <img width="400px" src="https://raw.githubusercontent.com/ondewo/ondewo-logos/master/ondewo_we_automate_your_phone_calls.png"/>
        </a>
      </td>
    </tr>
    <tr>
        <td align="center">
          <a href="https://www.linkedin.com/company/ondewo"><img width="40px" src="https://cdn-icons-png.flaticon.com/512/3536/3536505.png"></a>
          <a href="https://www.facebook.com/ondewo"><img width="40px" src="https://cdn-icons-png.flaticon.com/512/733/733547.png"></a>
          <a href="https://twitter.com/ondewo"><img width="40px" src="https://cdn-icons-png.flaticon.com/512/733/733579.png"></a>
          <a href="https://www.instagram.com/ondewo.ai/"><img width="40px" src="https://cdn-icons-png.flaticon.com/512/174/174855.png"></a>
        </td>
    </tr>
  </table>
  <h1>
  ONDEWO SIP Client PHP Library
  </h1>
</div>

This library is the PHP gRPC client for the **ONDEWO SIP** (SIP Telephony) server.

There is no hand-written transport layer in this repository. The entire client surface — messages, enums and
one `<Service>Client` stub per gRPC service — is generated from the protocol buffer definitions of the
[ondewo-sip-api](https://github.com/ondewo/ondewo-sip-api) repository by the
[ONDEWO proto compiler](https://github.com/ondewo/ondewo-proto-compiler), which is vendored here as a git
submodule and pinned to a release tag. The repository root **is** the composer package: what is committed
here is exactly what a consumer receives.

## Requirements

* PHP >= 8.1
* The **grpc** PHP extension (`ext-grpc`) — every generated `<Service>Client` extends `\Grpc\BaseStub`
* [Composer](https://getcomposer.org/) 2.x
* Docker — only to *regenerate* the stubs, never to *use* the client

Installing the extension:

```bash
# Debian / Ubuntu
sudo apt-get install -y php-grpc
# or, from source
sudo pecl install grpc
```

## Installation

```bash
composer require ondewo/sip-client-php
```

To work on the client itself:

```bash
git clone --recurse-submodules git@github.com:ondewo/ondewo-sip-client-php.git
cd ondewo-sip-client-php
make setup_developer_environment_locally
```

`make help` lists every documented target; `make makefile_chapters` lists the Makefile's sections.

## Repository structure

```
.
├── ondewo-sip-api              <----- submodule: the .proto definitions (ondewo/ = the services, google/ = imports)
├── ondewo-proto-compiler   <----- submodule: the compiler images, pinned to tags/5.15.0
├── auth                    <----- HAND-WRITTEN sources (bearer credentials, token provider)
├── src                     <----- GENERATED stubs - compiler-owned, wiped on every generation run
│   ├── GPBMetadata         <----- descriptor bootstrap, one class per .proto
│   └── Ondewo              <----- messages, enums and the <Service>Client stubs
├── tests                   <----- PHPUnit suite (not part of the published classmap)
├── vendor                  <----- composer dependencies (gitignored)
├── composer.json           <----- the package manifest; MERGED with the compiler defaults on every run
└── Makefile                <----- build, test and release automation
```

Two rules follow from that layout and matter more than anything else in this file:

1. **Never put hand-written PHP in `src/`.** It is deleted and rewritten on every generation run. Hand-written
   code belongs in `auth/` at the repository root — the compiler image detects that directory and adds it to
   the shipped autoloader's classmap itself.
2. **Never edit `composer.json`'s `require` to pin `google/protobuf` or `grpc/grpc`.** The compiler image
   resolves the library offline from a cache it pre-warmed at image-build time; a pin outside that cache fails
   the generation run.

## Regenerating the stubs

```bash
make build
```

That is the whole flow, and it is: check out the pinned submodules → build the `ondewo-php-proto-compiler:latest`
image from the submodule → run it over the protos → hand the generated files back to your user → write the
client version into `composer.json`.

The generation step on its own is a single container run:

```bash
docker run --rm \
  -v $(pwd):/input-volume \
  -v $(pwd):/output-volume \
  ondewo-php-proto-compiler:latest ondewo-sip-api ondewo
```

* The two positional arguments are `<relative_protos_dir> <target_subdir>`: the proto root inside the input
  volume (which becomes protoc's `-I` root), and the sub-directory to scope generation to. `ondewo` keeps the
  vendored `google/` tree out of the entry set while the image's dependency resolver still pulls in the google
  protos that are actually imported.
* There is **no `-it`**. It breaks every non-interactive caller with
  `cannot attach stdin to a TTY-enabled container because stdin is not a terminal`.
* Input and output volume are both the repository root. The image copies the input volume into an internal
  temporary directory and compiles there, so the mounted input is never mutated; it then writes `composer.json`,
  `composer.lock`, `src/` and `vendor/` back here, wiping its own `src/` and `vendor/` first so a renamed or
  deleted proto leaves no orphaned stub behind.
* The container runs as root, so the files it writes are root-owned. `make build` chases that with
  `make fix_generated_ownership`; run it by hand if you invoke docker directly.

To poke around inside the image, and only there, `-it` is correct:

```bash
docker run -it --entrypoint /bin/bash \
  -v $(pwd):/input-volume \
  -v $(pwd):/output-volume \
  ondewo-php-proto-compiler:latest
```

## Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Grpc\ChannelCredentials;

// The PHP namespace is protoc's UpperCamel form of the proto package:
// `package ondewo.sip;` becomes `Ondewo\Sip`, and a service `Foo` becomes
// `FooClient` (grpc_php_plugin's default class suffix). Browse src/Ondewo/Sip for
// the services and messages your pinned API version actually declares.
$client = new \Ondewo\Sip\ExampleServiceClient(
    getenv('ONDEWO_SIP_HOST') ?: 'localhost:50055',
    ['credentials' => ChannelCredentials::createSsl()]
);

// ONDEWO servers authenticate with a bearer token passed as call metadata.
$metadata = ['authorization' => ['Bearer ' . getenv('ONDEWO_TOKEN')]];

$request = new \Ondewo\Sip\ExampleRequest();

[$response, $status] = $client->ExampleMethod($request, $metadata)->wait();

if ($status->code !== \Grpc\STATUS_OK) {
    throw new RuntimeException("gRPC call failed ({$status->code}): {$status->details}");
}

echo $response->serializeToJsonString(), PHP_EOL;
```

For an insecure channel against a local server, swap the credentials for
`ChannelCredentials::createInsecure()`.

## Testing

```bash
make test
```

Which is `composer validate` → `php -l` over the hand-written sources → `make check_build` (every `.proto` of
the API submodule has generated PHP code) → PHPUnit, when a suite and `vendor/bin/phpunit` are present.

The same steps run in GitHub Actions on PHP 8.1 through 8.4, plus a check that every generated descriptor
loads: `initOnce()` on each `GPBMetadata` class walks the whole descriptor dependency chain, so a missing
transitive import fails CI instead of a consumer's first RPC.

## Versioning and releasing

`ONDEWO_SIP_VERSION` at the top of the `Makefile` is the single source of truth and **must match
the ONDEWO SIP API in major and minor version**. `make update_composer_version` propagates it into
`composer.json`; never edit that field by hand.

```bash
# bump ONDEWO_SIP_VERSION and the submodule pins, add a RELEASE.md entry, then:
make ondewo_release
```

`ondewo_release` checks that the release branch and tag are still free, fetches the GitHub credentials from the
`ondewo-devops-accounts` repository and runs the release: build, commit, release branch, release tag and the
GitHub release, whose notes are sliced out of `RELEASE.md` by the version heading. There is no upload step for
PHP — [Packagist](https://packagist.org/) picks up the new tag from the GitHub webhook.

See [RELEASE.md](RELEASE.md) for the release history and [CONTRIBUTING.md](CONTRIBUTING.md) for how to
contribute.

## License

Apache License 2.0 — see [LICENSE](LICENSE).
