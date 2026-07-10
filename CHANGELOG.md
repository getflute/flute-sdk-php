# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
