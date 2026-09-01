# Mollie Bank Transfer support for the Magento 2 Unpaid Order Reminder

Adds Mollie bank transfer to
[`pixelperfectat/magento2-module-unpaid-order-reminder`](https://github.com/pixelperfectat/magento2-module-unpaid-order-reminder).

## Why a live lookup

Mollie writes the transfer details onto the order only once the payment has been processed. An order
that is still unpaid — the only kind this reminder is for — carries none of them. So the details are
fetched from the Mollie API when the reminder is composed.

The shopper pays into **Mollie's** collection account, quoting a Mollie-generated reference. That
reference is what lets Mollie match the transfer to the order, so the mail is never sent without it.

This package ships no email template of its own. The core package's single template renders both
shapes of the instructions value object — free-text, as an offline method configures, and structured
bank details, as this package supplies — branching on whether structured bank details are present.

## Install

```bash
composer require pixelperfectat/magento2-module-unpaid-order-reminder-mollie
bin/magento module:enable PixelPerfect_UnpaidOrderReminderMollie
bin/magento setup:upgrade
```

Then add a rule for **Banktransfer** under
`Stores → Configuration → Sales → Unpaid Order Reminder`.

## Suggested delay

Mollie's bank transfer window defaults to 14 days. A delay of 5 days sits past the point most payers
have already transferred, and still leaves the shopper more than a week to act.

## Licence

MIT.
