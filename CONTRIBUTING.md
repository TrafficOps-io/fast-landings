# Contributing

Thank you for helping improve Fast Landings.

## Development setup

Follow the [local development guide](README.md#local-development). Keep changes focused, add regression coverage for behavior changes, and run the complete check set before opening a pull request:

```sh
composer validate --strict
vendor/bin/pint --test
npm run test:js
npm run build
cp .env.example .env
php artisan test
```

Do not commit `.env`, API tokens, generated landing releases, database files, or other installation data. Security reports belong in the private channel described in [SECURITY.md](SECURITY.md), not in a public issue.

## Pull requests

Describe the user-facing impact, migration or deployment steps, and the checks you ran. Update documentation and `.env.example` when configuration changes. By contributing, you agree that your contribution is licensed under the repository's MIT License.
