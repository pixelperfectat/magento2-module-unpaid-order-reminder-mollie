# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.3] - 2026-09-02

### Changed

- Widened the core requirement to `>=0.2.1 <0.5.0`, so core 0.4.0 can be installed. Core 0.4.0 adds
  `deleteByOrderId()` to the reminder log repository contract; this package neither implements nor
  calls that contract, so nothing here changes.

## [0.1.2] - 2026-09-01

### Changed

- Widened the core requirement to `>=0.2.1 <0.4.0`, so core 0.3.0 can be installed. A caret constraint
  pins the minor on a 0.x package, which meant every core minor forced a release here purely to move a
  number. Nothing in this package's contract changed between those two minors, so the range states what
  is actually true rather than re-asserting it each time.

## [0.1.1] - 2026-09-01

### Fixed

- No reminder was ever sent for an order whose Mollie payment id was held only in the
  `sales_order.mollie_transaction_id` column. Mollie writes that column when it creates the payment, at
  order placement, but writes `additional_information['mollie_id']` only when the payment is processed —
  on the webhook, or on the shopper's return from the hosted page. An order that nobody paid and nobody
  returned from therefore carried the column but not the key, and that is exactly the order this reminder
  exists for. The provider read the key alone and reported "no instructions". The column is now read
  first, and the key remains a fallback.

## [0.1.0] - 2026-09-01

### Added

- Mollie bank transfer support for the unpaid order reminder. The transfer details are fetched from the
  Mollie API when the reminder is composed, because Mollie writes them onto the order only once the
  payment has been processed, and an order that is still unpaid carries none of them.
- No reminder is sent unless the Mollie transfer reference, the collection account and the bank name are
  all present. A transfer quoting the wrong reference arrives unmatchable, and a partial response would
  render an email with nothing the shopper could act on while still spending the order's one reminder.
- An API failure is reported as "no instructions", so the order keeps its reminder and is retried on the
  next run rather than losing it to a transient outage.
