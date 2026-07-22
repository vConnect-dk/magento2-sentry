<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Test\Unit\Plugin;

use JustBetter\Sentry\Helper\Data as SentryHelper;
use JustBetter\Sentry\Model\ReleaseIdentifier;
use JustBetter\Sentry\Model\SentryInteraction;
use JustBetter\Sentry\Model\SentryPerformance;
use JustBetter\Sentry\Plugin\GlobalExceptionCatcher;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

class GlobalExceptionCatcherTest extends TestCase
{
    /**
     * @var DataObjectFactory&Stub
     */
    private $dataObjectFactory;

    private GlobalExceptionCatcher $catcher;

    protected function setUp(): void
    {
        $sentryHelper = $this->createStub(SentryHelper::class);
        $sentryHelper->method('collectModuleConfig')->willReturn([]);
        $sentryHelper->method('getDisabledDefaultIntegrations')->willReturn([]);
        $sentryHelper->method('getErrorTypes')->willReturn(E_ALL);
        $sentryHelper->method('isPerformanceTrackingEnabled')->willReturn(false);

        $this->dataObjectFactory = $this->createStub(DataObjectFactory::class);
        $this->dataObjectFactory->method('create')->willReturnCallback(
            fn (): DataObject => new DataObject()
        );

        $this->catcher = new GlobalExceptionCatcher(
            $sentryHelper,
            $this->createStub(ReleaseIdentifier::class),
            $this->createStub(SentryInteraction::class),
            $this->createStub(EventManagerInterface::class),
            $this->dataObjectFactory,
            $this->createStub(SentryPerformance::class)
        );
    }

    /**
     * Drive the real `before_send` closure built by prepareConfig(), exercising
     * the promotion logic exactly as GlobalExceptionCatcher wires it.
     */
    private function callBeforeSend(Event $event): ?Event
    {
        $beforeSend = $this->catcher->prepareConfig()->getBeforeSend();

        return $beforeSend($event, null);
    }

    public function testNonEmptyChannelIsPromotedToLoggerAndCarrierKeyStripped(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['__log_channel' => 'business_central', 'other' => 'value']);

        $result = $this->callBeforeSend($event);

        self::assertSame('business_central', $result->getLogger());
        self::assertArrayNotHasKey('__log_channel', $result->getExtra());
        self::assertSame('value', $result->getExtra()['other']);
    }

    public function testEmptyStringChannelDoesNotSetLoggerButStripsCarrierKey(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['__log_channel' => '']);

        $result = $this->callBeforeSend($event);

        self::assertNull($result->getLogger());
        self::assertArrayNotHasKey('__log_channel', $result->getExtra());
    }

    public function testNullChannelDoesNotSetLoggerButStripsCarrierKey(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['__log_channel' => null]);

        $result = $this->callBeforeSend($event);

        self::assertNull($result->getLogger());
        self::assertArrayNotHasKey('__log_channel', $result->getExtra());
    }

    public function testAbsentCarrierKeyLeavesEventExtraUntouched(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['other' => 'value']);

        $result = $this->callBeforeSend($event);

        self::assertNull($result->getLogger());
        self::assertSame(['other' => 'value'], $result->getExtra());
    }
}
