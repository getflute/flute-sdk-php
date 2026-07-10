# Contributing

This guide covers local development of the Flute PHP SDK itself — environment
setup and the test suites. For using the SDK in an application, see the
[README](README.md).

## Requirements

- PHP 8.1+
- Composer 2
- [DDEV](https://ddev.readthedocs.io/) (recommended) for a containerized dev
  environment and live-sandbox credentials
- Docker (required by DDEV, and by `composer test:matrix`)

## Local Setup

Install dependencies:

```bash
composer install
```

### DDEV (Recommended)

The repo ships a DDEV config (`.ddev/config.yaml`). Sandbox credentials are
**never committed** — provide your own in a gitignored local override that DDEV
merges automatically on `ddev start`.

1. [Install DDEV](https://ddev.readthedocs.io/en/stable/users/install/).
2. Create `.ddev/config.local.yaml` (gitignored) with your sandbox credentials:

   ```yaml
   web_environment:
       - FLUTE_CLIENT_ID=your-sandbox-client-id
       - FLUTE_CLIENT_SECRET=your-sandbox-client-secret
       - FLUTE_PARTNER_CLIENT_ID=your-partner-client-id
       - FLUTE_PARTNER_CLIENT_SECRET=your-partner-client-secret
       - FLUTE_WEBHOOK_SECRET=your-webhook-signing-secret
   ```

3. Start the environment and run commands inside it:

   ```bash
   ddev start
   ddev composer test:unit
   ```

The SDK never reads environment variables itself; these are consumed only by the
live test suites and the runnable examples, which read them from the process
environment.

### Not Using DDEV?

There is no `.env` loader — the test suites and examples read credentials from the
process environment via `getenv()`. Without DDEV, put the `FLUTE_*` variables into
the environment yourself, for example:

```bash
# Inline for a single example run:
FLUTE_CLIENT_ID=... FLUTE_CLIENT_SECRET=... php examples/01-sale.php

# Or export them for the session, then run the suites:
export FLUTE_CLIENT_ID=... FLUTE_CLIENT_SECRET=...
composer test:integration
```

Under Apache/WAMP, the equivalent is `SetEnv FLUTE_CLIENT_ID ...` in your vhost (or
a php-fpm pool `env[...]` entry). The full variable list is in
[Sandbox Credentials](#sandbox-credentials) below.

## Test Suites

| Command | What it runs | Credentials |
| --- | --- | --- |
| `composer test:unit` | Unit suite against mocked HTTP | none |
| `composer test:matrix` | Lint, static analysis, and unit suite against every PHP version in the CI matrix, via Docker (`php:<ver>-cli` images) | none |
| `composer test:integration` | Integration tests plus every `examples/*.php` and `examples/partner/*.php` | live sandbox |
| `composer test:regression` | The 25-scenario harness (H-1..H-25), testdox output | live sandbox |

`composer test:unit` needs no credentials. `composer test:matrix` verifies
cross-version support locally without a remote; narrow it by passing versions:
`composer test:matrix -- 8.3 8.5`.

The two live suites hit the real sandbox. Missing prerequisites skip with explicit
reasons rather than failing.

### Sandbox Credentials

| Variable | Purpose |
| --- | --- |
| `FLUTE_CLIENT_ID` / `FLUTE_CLIENT_SECRET` | Sandbox credential — must be merchant-scoped; an ISV/partner credential can mint one via `POST /pay-api/v1/merchants/tokens` |
| `FLUTE_WEBHOOK_SECRET` | Mint via `tests/Regression/bin/create-webhook-endpoint.php` |
| `FLUTE_PARTNER_CLIENT_ID` / `FLUTE_PARTNER_CLIENT_SECRET` | Partner (ISV) credential — drives the partner merchants/key tests, the partner examples, and the instance-isolation scenario |
| `FLUTE_MERCHANT_ID` | Optional — pins the merchant the partner tests/examples mint keys against (default: first listed merchant) |
| `FLUTE_API_BASE_URL` / `FLUTE_OAUTH_BASE_URL` | Optional host overrides |

Zero-cost scenarios (H-22..H-24) skip unless the merchant account's
`zeroCostProcessingOption` matches. To see skip reasons, run
`vendor/bin/phpunit --testsuite regression --display-skipped` (the testdox
printer hides reason text).

## Code Quality

| Command | Purpose |
| --- | --- |
| `composer lint` | PHP_CodeSniffer (PSR-12) |
| `composer lint:fix` | Auto-fix lint violations |
| `composer stan` | PHPStan static analysis |
| `composer check` | Validate composer, lint, stan, dependency audit, and the unit suite — run this before opening a PR |
