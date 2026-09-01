<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderMollie\Service\Instructions;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Payment\Service\Mollie\MollieApiClient;
use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\PaymentInstructionsProviderInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructionsFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Transfer instructions for an open Mollie bank transfer payment.
 *
 * The details are fetched live, not read from the order. Mollie writes bankAccount and
 * transferReference into the order's payment only when the payment is processed, so an order that is
 * still unpaid - the only kind this reminder is for - carries none of them.
 *
 * The shopper pays into Mollie's collection account quoting a Mollie-generated reference. That
 * reference is what lets Mollie match the transfer to the order, so nothing is returned without it.
 */
class MollieBankTransfer implements PaymentInstructionsProviderInterface
{
    private const MYSQL_DATETIME = 'Y-m-d H:i:s';

    /**
     * @param MollieApiClient $clientLoader
     * @param PaymentInstructionsFactory $instructionsFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly MollieApiClient $clientLoader,
        private readonly PaymentInstructionsFactory $instructionsFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Fetch live payment instructions for an order from the Mollie API.
     *
     * @param OrderInterface $order
     * @return PaymentInstructionsInterface|null
     */
    public function forOrder(OrderInterface $order): ?PaymentInstructionsInterface
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }

        $additional = $payment->getAdditionalInformation();
        $additional = is_array($additional) ? $additional : [];

        $transactionId = (string)($additional['mollie_id'] ?? '');
        if ($transactionId === '') {
            return null;
        }

        try {
            $client = $this->clientLoader->loadByStore((int)$order->getStoreId());
            $details = $client->payments->get($transactionId)->details ?? null;
        } catch (Throwable $e) {
            // Transient by nature. Reported as "no instructions" so the runner retries tomorrow
            // rather than consuming this order's one reminder on an outage.
            $this->logger->warning(sprintf(
                'UnpaidOrderReminderMollie: could not read payment %s for order %s: %s',
                $transactionId,
                (string)$order->getEntityId(),
                $e->getMessage()
            ));

            return null;
        }

        if (!is_object($details)) {
            return null;
        }

        $account = $this->stringOrNull($details, 'bankAccount');
        $reference = $this->stringOrNull($details, 'transferReference');
        if ($account === null || $reference === null) {
            return null;
        }

        return $this->instructionsFactory->create([
            'bankName' => $this->stringOrNull($details, 'bankName'),
            'bankAccount' => $account,
            'bankBic' => $this->stringOrNull($details, 'bankBic'),
            'reference' => $reference,
            'expiresAt' => $this->expiresAt($additional),
            'paymentUrl' => $this->stringOrNull((object)$additional, 'checkout_url'),
        ]);
    }

    /**
     * Convert the deadline Mollie wrote onto the order into the value object's UTC format.
     *
     * Mollie stores the deadline on the order as ISO-8601 with an offset; the value object contract
     * is UTC 'Y-m-d H:i:s'.
     *
     * @param array<string, mixed> $additional
     * @return string|null
     */
    private function expiresAt(array $additional): ?string
    {
        $raw = (string)($additional['expires_at'] ?? '');
        if ($raw === '') {
            return null;
        }

        try {
            $moment = new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }

        return $moment->setTimezone(new DateTimeZone('UTC'))->format(self::MYSQL_DATETIME);
    }

    /**
     * Read a property off an untyped API/array object as a trimmed string, or null.
     *
     * @param object $source
     * @param string $property
     * @return string|null
     */
    private function stringOrNull(object $source, string $property): ?string
    {
        $value = $source->{$property} ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
