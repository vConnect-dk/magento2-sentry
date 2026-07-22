<?php

namespace JustBetter\Sentry\Model;

use JustBetter\Sentry\Helper\Data;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\SessionException;
use Magento\Framework\Logger\Monolog;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\ExceptionMechanism;
use Sentry\Stacktrace;
use Sentry\State\Scope as SentryScope;

class SentryLog
{
    /**
     * @var array<string,mixed>
     */
    protected $config = [];

    /**
     * SentryLog constructor.
     *
     * @param Data              $data
     * @param Session           $customerSession
     * @param State             $appState
     * @param SentryInteraction $sentryInteraction
     */
    public function __construct(
        protected Data $data,
        protected Session $customerSession,
        private State $appState,
        private SentryInteraction $sentryInteraction,
    ) {
    }

    /**
     * Check and send log information to Sentry.
     *
     * @param \Throwable|string $message
     * @param int               $logLevel
     * @param array             $context
     * @param string|null       $channel
     * @param array             $extra
     */
    // @phpstan-ignore-next-line missingType.iterableValue, missingType.iterableValue
    public function send($message, $logLevel, array $context = [], ?string $channel = null, array $extra = []): void
    {
        $config = $this->data->collectModuleConfig();
        $customTags = [];

        if (($config['enable_logs'] ?? false) && $logLevel >= ($config['logger_log_level'] ?? 300)) {
            match ($logLevel) {
                Monolog::DEBUG   => \Sentry\Logger()->debug($message, $context),
                Monolog::INFO    => \Sentry\Logger()->info($message, $context),
                Monolog::WARNING => \Sentry\Logger()->warn($message, $context),
                Monolog::ERROR, MONOLOG::CRITICAL => \Sentry\Logger()->error($message, $context),
                Monolog::ALERT, MONOLOG::EMERGENCY => \Sentry\Logger()->fatal($message, $context),
                default => \Sentry\Logger()->info($message, $context)
            };
        }

        if ($logLevel < (int) ($config['log_level'] ?? 0)) {
            return;
        }

        if (isset($context['custom_tags']) && false === empty($context['custom_tags'])) {
            $customTags = $context['custom_tags'];
            unset($context['custom_tags']);
        }

        // withScope (not configureScope) so tags/context/channel/user-data never outlive this single
        // capture — configureScope mutates the Hub's persistent scope, leaking into later events.
        $lastEventId = \Sentry\withScope(
            function (SentryScope $scope) use ($message, $logLevel, $context, $customTags, $channel, $extra): ?EventId {
                $this->setTags($scope, $customTags);
                if ($context !== []) {
                    $scope->setContext('Custom context', $context);
                }

                if ($extra !== []) {
                    $scope->setContext('monolog.extra', $extra);
                }

                if ($channel !== null) {
                    // Transient carrier: before_send (GlobalExceptionCatcher) promotes this to Event::logger, then strips it.
                    $scope->setExtra('__log_channel', $channel);
                }

                $this->sentryInteraction->addUserContext();

                if ($message instanceof \Throwable) {
                    return \Sentry\captureException($message);
                }

                return \Sentry\captureMessage(
                    $message,
                    \Sentry\Severity::fromError($logLevel),
                    $this->monologContextToSentryHint($context)
                );
            }
        );

        /// when using JS SDK you can use this for custom error page printing
        try {
            if ($this->canGetCustomerData()) {
                $this->customerSession->setSentryEventId($lastEventId);
            }
        } catch (SessionException) {
            return;
        }
    }

    /**
     * Turn the monolog context into a format Sentrys EventHint can deal with.
     *
     * @param array $context
     *
     * @return EventHint|null
     */
    public function monologContextToSentryHint(array $context): ?EventHint // @phpstan-ignore missingType.iterableValue
    {
        return EventHint::fromArray(
            [
                'exception'  => ($context['exception'] ?? null) instanceof \Throwable ? $context['exception'] : null,
                'mechanism'  => ($context['mechanism'] ?? null) instanceof ExceptionMechanism ? $context['mechanism'] : null,
                'stacktrace' => ($context['stacktrace'] ?? null) instanceof Stacktrace ? $context['stacktrace'] : null,
                'extra'      => array_filter(
                    $context,
                    fn ($key): bool => !in_array($key, ['exception', 'mechanism', 'stacktrace']),
                    ARRAY_FILTER_USE_KEY
                ) ?: [],
            ]
        );
    }

    /**
     * Check if we can retrieve customer data.
     *
     * @return bool
     */
    private function canGetCustomerData(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (LocalizedException) {
            return false;
        }
    }

    /**
     * Add additional tags to the scope.
     *
     * @param SentryScope $scope
     * @param array       $customTags
     */
    private function setTags(SentryScope $scope, $customTags): void // @phpstan-ignore missingType.iterableValue
    {
        $store = $this->data->getStore();

        $scope->setTag('mage_mode', $this->data->getAppState());
        $scope->setTag('version', $this->data->getMagentoVersion());
        $scope->setTag('website_id', (string) $store?->getWebsiteId());
        $scope->setTag('store_id', (string) $store?->getId());
        $scope->setTag('store_code', (string) $store?->getCode());

        if (false === empty($customTags)) {
            foreach ($customTags as $tag => $value) {
                $scope->setTag($tag, $value);
            }
        }
    }

    /**
     * Send the logs to Sentry if there are any.
     */
    public function __destruct()
    {
        \Sentry\Logger()->flush();
    }
}
