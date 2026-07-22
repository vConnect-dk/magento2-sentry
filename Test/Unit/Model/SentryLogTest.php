<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Test\Unit\Model;

use JustBetter\Sentry\Helper\Data;
use JustBetter\Sentry\Model\SentryInteraction;
use JustBetter\Sentry\Model\SentryLog;
use Magento\Customer\Model\Session;
use Magento\Framework\App\State;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class SentryLogTest extends TestCase
{
    /**
     * @var Data&Stub
     */
    private $data;

    /**
     * @var Session&MockObject
     */
    private $customerSession;

    /**
     * @var SentryInteraction&MockObject
     */
    private $sentryInteraction;

    private SentryLog $sentryLog;

    protected function setUp(): void
    {
        $this->data = $this->createStub(Data::class);
        $this->data->method('collectModuleConfig')->willReturn([
            'enable_logs' => false,
            'log_level'   => 500,
        ]);

        // setSentryEventId is a dynamically added Session method (extension attribute
        // style), invisible to reflection-based createMock() — declare it explicitly.
        $this->customerSession = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->addMethods(['setSentryEventId'])
            ->getMock();
        $this->sentryInteraction = $this->createMock(SentryInteraction::class);

        $this->sentryLog = new SentryLog(
            $this->data,
            $this->customerSession,
            $this->createStub(State::class),
            $this->sentryInteraction
        );
    }

    /**
     * Pre-existing 3-arg call shape must keep compiling and behaving identically
     * after channel/extra were added as optional trailing params.
     */
    public function testLegacyThreeArgCallStillHonoursLogLevelEarlyReturn(): void
    {
        $this->sentryInteraction->expects(self::never())->method('addUserContext');
        $this->customerSession->expects(self::never())->method('setSentryEventId');

        $this->sentryLog->send('below threshold', 100, []);
    }

    /**
     * New channel/extra params must not disturb the log-level early return —
     * the feature is error-path only, no capture should be attempted below log_level.
     */
    public function testNewChannelAndExtraParamsDoNotBypassLogLevelEarlyReturn(): void
    {
        $this->sentryInteraction->expects(self::never())->method('addUserContext');
        $this->customerSession->expects(self::never())->method('setSentryEventId');

        $this->sentryLog->send('below threshold', 100, [], 'business_central', ['area' => 'frontend']);
    }

    /**
     * Binds a fresh Hub with a spy transport so a real capture can be observed without
     * a network call — proves send() actually reaches Sentry\withScope()/captureException()
     * and that the resulting Event carries channel/extra, not just that params are accepted.
     */
    private function bindSpyTransport(): TransportInterface
    {
        $transport = new class implements TransportInterface {
            public ?Event $capturedEvent = null;

            public function send(Event $event): Result
            {
                $this->capturedEvent = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $client = (new ClientBuilder(new Options()))->setTransport($transport)->getClient();
        SentrySdk::setCurrentHub(new Hub($client));

        return $transport;
    }

    public function testChannelAndExtraAreForwardedToTheCapturedEvent(): void
    {
        $transport = $this->bindSpyTransport();

        $this->sentryLog->send('above threshold', 600, [], 'business_central', ['area' => 'frontend']);

        $event = $transport->capturedEvent;
        self::assertNotNull($event, 'Expected send() to reach captureException/captureMessage.');
        self::assertSame('business_central', $event->getExtra()['__log_channel']);
        self::assertSame(['area' => 'frontend'], $event->getContexts()['monolog.extra']);
    }

    /**
     * With no channel/extra, the transient carrier key and the extra context block
     * must not appear at all — confirms the guard in send(), not just an empty value.
     */
    public function testNoChannelOrExtraLeavesEventUntouched(): void
    {
        $transport = $this->bindSpyTransport();

        $this->sentryLog->send('above threshold', 600);

        $event = $transport->capturedEvent;
        self::assertNotNull($event, 'Expected send() to reach captureException/captureMessage.');
        self::assertArrayNotHasKey('__log_channel', $event->getExtra());
        self::assertArrayNotHasKey('monolog.extra', $event->getContexts());
    }

    /**
     * The bug this guards against: \Sentry\configureScope() mutates the Hub's persistent
     * scope with no pop, so a channel set on one call used to leak onto the next capture.
     * send() must use \Sentry\withScope() instead, scoping each capture to itself.
     */
    public function testChannelFromOneCallDoesNotLeakIntoTheNextCapture(): void
    {
        $transport = $this->bindSpyTransport();

        $this->sentryLog->send('first call', 600, [], 'business_central');
        $this->sentryLog->send('second call', 600);

        $secondEvent = $transport->capturedEvent;
        self::assertNotNull($secondEvent);
        self::assertArrayNotHasKey(
            '__log_channel',
            $secondEvent->getExtra(),
            'Channel from the first call leaked into the second capture — configureScope() was not scoped per-call.'
        );
    }
}
