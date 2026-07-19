# Contributing to the Brevity PHP SDK

Thanks for your interest! The SDK is a thin, fully typed client for the
[Brevity](https://github.com/vaslv/brevity) short-link engine; the API
contract in [API.md](./API.md) is the source of truth.

## Development environment

No local PHP toolchain is needed — everything runs in Docker:

```bash
docker compose run --rm tests                        # composer install + phpunit
docker compose run --rm tests vendor/bin/pint --test # code style
docker compose run --rm tests phpstan analyse        # static analysis (level 8)
```

## Before you open a PR

CI enforces all three checks above across PHP 7.1–8.4, so:

- **Code must stay PHP 7.1-compatible**: nullable types and `void` are
  fine; typed properties, arrow functions and promoted constructors are
  not. The CI matrix (including a `--prefer-lowest` run on 7.1) is the
  final referee.
- **Every behaviour change ships with a test** (new or updated).
- **Documentation lives with the change**: update README.md and its
  Russian mirror README.ru.md in the same commit. API.md is a copy of
  the canonical contract from the main repository — change it there
  first.

## Commits

Conventional-commit style, imperative mood, English:

```
feat: add domain warm-up strategy
fix: reject empty link codes before the request
docs, chore, build, ci, refactor, test — as appropriate
```

## Releases

Maintainers cut releases as annotated semver tags without a `v` prefix
(e.g. `1.3.0`); Packagist picks them up automatically.

## Security issues

Please do not open public issues for vulnerabilities — see
[SECURITY.md](./SECURITY.md).
