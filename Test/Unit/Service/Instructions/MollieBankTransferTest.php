<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderMollie\Test\Unit\Service\Instructions;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\MollieApiClient as ApiClient;
use Mollie\Api\Resources\Payment;
use Mollie\Payment\Service\Mollie\MollieApiClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructions;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructionsFactory;
use PixelPerfect\UnpaidOrderReminderMollie\Service\Instructions\MollieBankTransfer;

class MollieBankTransferTest extends TestCase
{
    /** @var MollieApiClient|MockObject */
    private $clientLoader;
    /** @var PaymentEndpoint|MockObject */
    private $payments;

    protected function setUp(): void
    {
        $this->payments = $this->createMock(PaymentEndpoint::class);

        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->payments = $this->payments;

        $this->clientLoader = $this->createMock(MollieApiClient::class);
        $this->clientLoader->method('loadByStore')->willReturn($apiClient);
    }

    public function testReturnsTheLiveTransferDetails(): void
    {
        $this->payments->method('get')->willReturn($this->payment((object)[
            'bankName' => 'Example Bank',
            'bankAccount' => 'NL00INGB0000000000',
            'bankBic' => 'INGBNL2A',
            'transferReference' => 'ABC-1234-DEF',
        ]));

        $instructions = $this->provider()->forOrder($this->order());

        $this->assertNotNull($instructions);
        $this->assertSame('Example Bank', $instructions->getBankName());
        $this->assertSame('NL00INGB0000000000', $instructions->getBankAccount());
        $this->assertSame('INGBNL2A', $instructions->getBankBic());
        $this->assertSame('ABC-1234-DEF', $instructions->getReference());
        $this->assertTrue($instructions->hasStructuredBankDetails());
    }

    /**
     * The order carries the deadline already, and it is the same value the shopper sees on Mollie's
     * page. No second call is needed for it.
     */
    public function testTakesTheDeadlineAndHostedPageFromTheOrderPayment(): void
    {
        $this->payments->method('get')->willReturn($this->payment((object)[
            'bankName' => 'Example Bank',
            'bankAccount' => 'NL00INGB0000000000',
            'transferReference' => 'ABC-1234-DEF',
        ]));

        $instructions = $this->provider()->forOrder($this->order());

        $this->assertNotNull($instructions);
        $this->assertSame('2026-09-09 04:00:00', $instructions->getExpiresAt());
        $this->assertSame('https://example.com/checkout/bank-transfer/reference/x', $instructions->getPaymentUrl());
    }

    /**
     * A transfer without the Mollie reference cannot be matched to the order. Sending it would
     * produce an untraceable payment, which is worse than sending nothing.
     */
    public function testReturnsNullWhenTheReferenceIsMissing(): void
    {
        $this->payments->method('get')->willReturn($this->payment((object)[
            'bankName' => 'Example Bank',
            'bankAccount' => 'NL00INGB0000000000',
        ]));

        $this->assertNull($this->provider()->forOrder($this->order()));
    }

    public function testReturnsNullWhenTheAccountIsMissing(): void
    {
        $this->payments->method('get')->willReturn($this->payment((object)[
            'transferReference' => 'ABC-1234-DEF',
        ]));

        $this->assertNull($this->provider()->forOrder($this->order()));
    }

    public function testReturnsNullWhenThePaymentCarriesNoDetailsAtAll(): void
    {
        $this->payments->method('get')->willReturn($this->payment(null));

        $this->assertNull($this->provider()->forOrder($this->order()));
    }

    public function testReturnsNullWhenTheOrderHasNoMollieTransactionId(): void
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalInformation')->willReturn([]);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);

        $this->assertNull($this->provider()->forOrder($order));
    }

    /**
     * A gateway outage is a transient condition. It is logged and reported as "no instructions", so
     * the runner retries the order tomorrow rather than burning its one reminder.
     */
    public function testReturnsNullAndLogsWhenTheApiCallFails(): void
    {
        $this->payments->method('get')->willThrowException(new \Exception('gateway timeout'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->assertNull($this->provider($logger)->forOrder($this->order()));
    }

    public function testReturnsNullWhenTheOrderHasNoPayment(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn(null);

        $this->assertNull($this->provider()->forOrder($order));
    }

    /**
     * @param object|null $details
     * @return Payment
     */
    private function payment(?object $details): Payment
    {
        $payment = $this->createMock(Payment::class);
        $payment->details = $details;

        return $payment;
    }

    private function order(): OrderInterface
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalInformation')->willReturn([
            'mollie_id' => 'tr_exampleexampleexample',
            'expires_at' => '2026-09-09T04:00:00+00:00',
            'checkout_url' => 'https://example.com/checkout/bank-transfer/reference/x',
        ]);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn(900);

        return $order;
    }

    private function provider(?LoggerInterface $logger = null): MollieBankTransfer
    {
        $factory = $this->createMock(PaymentInstructionsFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): PaymentInstructions => new PaymentInstructions(...$data)
        );

        return new MollieBankTransfer(
            $this->clientLoader,
            $factory,
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }
}
