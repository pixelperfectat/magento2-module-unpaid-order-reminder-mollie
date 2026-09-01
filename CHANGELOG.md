# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
