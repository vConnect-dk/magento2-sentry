<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Test\Unit\Model\Queue\Consumer;

use JustBetter\Sentry\Helper\Data;
use JustBetter\Sentry\Model\CircuitBreaker;
use JustBetter\Sentry\Model\Queue\Consumer\SentryEventConsumer;
use JustBetter\Sentry\Model\Transport\EnvelopeSender;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SentryEventConsumerTest extends TestCase
{
    public function testSkipsWhenModuleInactive(): void
    {
        $helper = $this->createStub(Data::class);
        $helper->method('isActive')->willReturn(false);

        $envelopeSender = $this->createMock(EnvelopeSender::class);
        $envelopeSender->expects($this->never())->method('send');

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->expects($this->never())->method('recordSuccess');
        $circuitBreaker->expects($this->never())->method('recordFailure');

        $consumer = new SentryEventConsumer($envelopeSender, $circuitBreaker, $helper);
        $consumer->process('payload');
    }

    public function testSkipsEmptyPayload(): void
    {
        $helper = $this->createStub(Data::class);
        $helper->method('isActive')->willReturn(true);

        $envelopeSender = $this->createMock(EnvelopeSender::class);
        $envelopeSender->expects($this->never())->method('send');

        $consumer = new SentryEventConsumer(
            $envelopeSender,
            $this->createStub(CircuitBreaker::class),
            $helper
        );
        $consumer->process('');
    }

    public function testSuccessfulDeliveryRecordsSuccess(): void
    {
        $helper = $this->createStub(Data::class);
        $helper->method('isActive')->willReturn(true);

        $envelopeSender = $this->createMock(EnvelopeSender::class);
        $envelopeSender
            ->expects($this->once())
            ->method('send')
            ->with('envelope-bytes');

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->expects($this->once())->method('recordSuccess');
        $circuitBreaker->expects($this->never())->method('recordFailure');
        $circuitBreaker->expects($this->never())->method('allowRequest');

        $consumer = new SentryEventConsumer($envelopeSender, $circuitBreaker, $helper);
        $consumer->process('envelope-bytes');
    }

    public function testFailureRecordsAndRethrows(): void
    {
        $helper = $this->createStub(Data::class);
        $helper->method('isActive')->willReturn(true);

        $exception = new RuntimeException('sentry 503');
        $envelopeSender = $this->createStub(EnvelopeSender::class);
        $envelopeSender->method('send')->willThrowException($exception);

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->expects($this->once())->method('recordFailure');
        $circuitBreaker->expects($this->never())->method('recordSuccess');

        $this->expectExceptionObject($exception);

        $consumer = new SentryEventConsumer($envelopeSender, $circuitBreaker, $helper);
        $consumer->process('envelope-bytes');
    }
}
