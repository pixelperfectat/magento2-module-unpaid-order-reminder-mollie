<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderMollie\Test\Unit\Service\Instructions;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
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

    /**
     * A blank field is not the same as a missing one to a naive check, but stringOrNull() must still
     * turn it into null - a whitespace-only reference is exactly as unmatchable as an absent one.
     */
    public function testReturnsNullWhenTheReferenceIsBlank(): void
    {
        $this->payments->method('get')->willReturn($this->payment((object)[
            'bankName' => 'Example Bank',
            'bankAccount' => 'NL00INGB0000000000',
            'transferReference' => '   ',
        ]));

        $this->assertNull($this->provider()->forOrder($this->order()));
    }

    public function testReturnsNullWhenTheAccountIsBlank(): void
    {
        $this->payments->method('get')->willReturn($this->payment((object)[
            'bankName' => 'Example Bank',
            'bankAccount' => '',
            'transferReference' => 'ABC-1234-DEF',
        ]));

        $this->assertNull($this->provider()->forOrder($this->order()));
    }

    /**
     * hasStructuredBankDetails() on the core value object - and therefore ReminderSender's decision
     * to render the bank-details block at all - requires bankName too. A response missing it must be
     * rejected here, or a "successful" lookup would produce an email with nothing for the shopper to
     * act on while still spending the order's one reminder.
     */
    public function testReturnsNullWhenTheBankNameIsMissing(): void
    {
        $this->payments->method('get')->willReturn($this->payment((object)[
            'bankAccount' => 'NL00INGB0000000000',
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
     * The try/catch in forOrder() wraps both loadByStore() and payments->get() today. This test
     * exists so a later refactor that hoists the loadByStore() call above the try - which reads like
     * a harmless tidy-up - cannot reintroduce an uncaught throw on a bad API key without failing a
     * test. It builds its own clientLoader rather than reusing setUp()'s, since an unconstrained
     * stub added in a test does not override the one already registered in setUp().
     */
    public function testReturnsNullAndLogsWhenLoadByStoreThrows(): void
    {
        $clientLoader = $this->createMock(MollieApiClient::class);
        $clientLoader->method('loadByStore')->willThrowException(new \Exception('invalid api key'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $provider = new MollieBankTransfer(
            $clientLoader,
            $this->createMock(PaymentInstructionsFactory::class),
            $logger
        );

        $this->assertNull($provider->forOrder($this->order()));
    }

    /**
     * expiresAt() catches Throwable around the DateTimeImmutable parse and returns null, which is
     * treated as "never expires" rather than failing the whole lookup - the bank details are still
     * usable even if the deadline could not be read.
     */
    public function testTreatsAnUnparseableExpiresAtAsNoExpiry(): void
    {
        $this->payments->method('get')->willReturn($this->payment((object)[
            'bankName' => 'Example Bank',
            'bankAccount' => 'NL00INGB0000000000',
            'transferReference' => 'ABC-1234-DEF',
        ]));

        $instructions = $this->provider()->forOrder($this->order(['expires_at' => 'not-a-date']));

        $this->assertNotNull($instructions);
        $this->assertNull($instructions->getExpiresAt());
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

    /**
     * @param array<string, mixed> $additionalOverrides merged over the default additional
     *     information, so a single test can vary one key without repeating the rest.
     * @return OrderInterface
     */
    /**
     * The regression this provider was rewritten for. Mollie writes mollie_transaction_id at order
     * placement but additional_information['mollie_id'] only when the payment is processed, so an
     * order nobody paid and nobody returned from has the column alone. Reading only the key made
     * the provider silently skip precisely the orders it exists to chase.
     */
    public function testResolvesThePaymentFromTheOrderColumnWhenTheKeyIsAbsent(): void
    {
        $this->payments->expects($this->once())
            ->method('get')
            ->with('tr_fromcolumnexample')
            ->willReturn($this->payment((object)[
                'bankName' => 'Example Bank',
                'bankAccount' => 'NL00INGB0000000000',
                'transferReference' => 'ABC-1234-DEF',
            ]));

        $order = $this->orderWithColumn('tr_fromcolumnexample', ['mollie_id' => null]);

        $this->assertNotNull($this->provider()->forOrder($order));
    }

    /**
     * Both present: the column is the value Mollie keeps current, so it wins.
     */
    public function testPrefersTheOrderColumnOverTheStoredKey(): void
    {
        $this->payments->expects($this->once())
            ->method('get')
            ->with('tr_fromcolumnexample')
            ->willReturn($this->payment((object)[
                'bankName' => 'Example Bank',
                'bankAccount' => 'NL00INGB0000000000',
                'transferReference' => 'ABC-1234-DEF',
            ]));

        $order = $this->orderWithColumn('tr_fromcolumnexample');

        $this->assertNotNull($this->provider()->forOrder($order));
    }

    /**
     * Neither source carries an id, so there is no payment to read.
     */
    public function testReturnsNullWhenNeitherSourceCarriesAnId(): void
    {
        $this->payments->expects($this->never())->method('get');

        $order = $this->orderWithColumn('   ', ['mollie_id' => '']);

        $this->assertNull($this->provider()->forOrder($order));
    }

    /**
     * Build an order that is a DataObject, so the mollie_transaction_id column can be read off it.
     *
     * @param string $transactionId
     * @param array<string, mixed> $additionalOverrides
     * @return OrderInterface
     */
    private function orderWithColumn(string $transactionId, array $additionalOverrides = []): OrderInterface
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalInformation')->willReturn(array_merge([
            'mollie_id' => 'tr_exampleexampleexample',
            'expires_at' => '2026-09-09T04:00:00+00:00',
            'checkout_url' => 'https://example.com/checkout/bank-transfer/reference/x',
        ], $additionalOverrides));

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn(900);
        $order->method('getData')->with('mollie_transaction_id')->willReturn($transactionId);

        return $order;
    }

    private function order(array $additionalOverrides = []): OrderInterface
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalInformation')->willReturn(array_merge([
            'mollie_id' => 'tr_exampleexampleexample',
            'expires_at' => '2026-09-09T04:00:00+00:00',
            'checkout_url' => 'https://example.com/checkout/bank-transfer/reference/x',
        ], $additionalOverrides));

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
