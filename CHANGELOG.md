# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-27

Additive release for Flute Checkout and Elements integrations. No existing
method, property, or exception changes; every new property is nullable and every
new request field is optional.

### Added

- `CreatePaymentSessionRequest` accepts eight more optional fields: `returnUrl`,
  `paymentMethodTypes`, `metadata`, `expiresAt`, `pageName`, `paymentNotes`,
  `afterCompletionMessage`, and `isMultiUse`. `returnUrl` is the address the
  shopper is sent back to after a hosted Checkout payment. Unset fields are
  omitted from the request body as before.
- `PaymentSessionResponse` exposes nine more typed properties read back from
  the API: `returnUrl`, `metadata`, `expiresAt`, `pageName`,
  `paymentMethodTypes`, `checkoutUrl`, `surchargeAmount`, `achAccountLast2`,
  and `achRoutingLast2`. `toArray()` still returns the complete raw payload.
- `Flute\Sdk\Enums\PaymentMethodType`, a backed string enum (`Card` = `card`,
  `Ach` = `ach`) for `paymentMethodTypes`. Plain strings are still accepted
  alongside enum cases, so a method type Flute adds later needs no SDK release.
- Debug output (`var_dump()`, `print_r()`, VarDumper) masks any ACH account or
  routing echo longer than the two-digit display fragments, matching the
  existing card-number scrub.

### Changed

- Source comments no longer reference documents outside the package.

## [1.0.0] - 2026-07-10

Initial release.

- OAuth2 client-credentials authentication with an automatic token lifecycle
  (lazy acquisition, proactive refresh, and a single retry on a 401).
- Transactions: list, get, authorize, sale, void, capture, refund, and calculate
  amount.
- Payment sessions: create, get, and cancel (Flute Checkout / Elements).
- Payment settings retrieval.
- Webhook signature verification (HMAC-SHA256) with timestamp-freshness replay
  protection.
- Partner operations via `$flute->merchants` (partner credential required): list
  merchants and list, create, and revoke merchant API keys.
