<?php

namespace JustBetter\Sentry\Model;

use JustBetter\Sentry\Helper\Data;
use Magento\Cron\Model\ConfigInterface as CronConfigInterface;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;

class SentryCron
{
    /**
     * @var array<int|string,array{started_at:float,check_in_id:?string}>
     */
    protected array $runningCheckins = [];

    /**
     * Cron expression resolved from cron config, keyed by job code.
     *
     * @var array<string,?string>
     */
    private array $resolvedExpr = [];

    /**
     * SentryCron constructor.
     *
     * @param Data                 $data
     * @param CronConfigInterface  $cronConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param TimezoneInterface    $timezone
     * @param LoggerInterface      $logger
     */
    public function __construct(
        protected Data $data,
        private readonly CronConfigInterface $cronConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send the status of a cron schedule to Sentry.
     *
     * @param Schedule $schedule
     */
    public function sendScheduleStatus(Schedule $schedule): void
    {
        if (!$this->data->isActive() ||
            !$this->data->isCronMonitoringEnabled() ||
            !array_reduce(
                $this->data->getTrackCrons(),
                fn ($trackCron, $expression): bool => $trackCron || (
                    preg_match('/^\/.*\/[imsu]*$/', $expression) ?
                        preg_match($expression, $schedule->getJobCode()) :
                        $schedule->getJobCode() === $expression
                ),
                false
            )
        ) {
            return;
        }

        $status = $schedule->getStatus();
        if (!in_array($status, [
            Schedule::STATUS_RUNNING,
            Schedule::STATUS_SUCCESS,
            Schedule::STATUS_ERROR,
        ])) {
            return;
        }

        /** @var array|null $cronExpressionArr */
        $cronExpressionArr = $schedule->getCronExprArr(); // @phpstan-ignore missingType.iterableValue
        $cronExpression = !empty($cronExpressionArr)
            ? implode(' ', $cronExpressionArr)
            : $this->resolveCronExpression($schedule->getJobCode());

        if (!$cronExpression) {
            // No schedule known statically or at runtime -> skip rather than send an empty config Sentry can't upsert.
            $this->logger->warning(
                'Sentry cron check-in skipped: no resolvable schedule',
                ['job_code' => $schedule->getJobCode()]
            );

            return;
        }

        $monitorConfig = new MonitorConfig(
            MonitorSchedule::crontab($cronExpression),
            checkinMargin: null,
            maxRuntime: null,
            timezone: $this->timezone->getConfigTimezone(),
        );

        if ($status === Schedule::STATUS_RUNNING) {
            if (!isset($this->runningCheckins[$schedule->getId()])) {
                $this->startCheckin($schedule, $monitorConfig);
            }

            return;
        }
        $this->finishCheckin($schedule, $monitorConfig);
    }

    /**
     * Start the check-in for a given schedule.
     *
     * @param Schedule           $schedule
     * @param MonitorConfig|null $monitorConfig
     */
    public function startCheckin(Schedule $schedule, ?MonitorConfig $monitorConfig = null): void
    {
        $this->runningCheckins[$schedule->getId()] = [
            'started_at'  => microtime(true),
            'check_in_id' => \Sentry\captureCheckIn(
                slug: $schedule->getJobCode(),
                status: CheckInStatus::inProgress(),
                monitorConfig: $monitorConfig,
            ),
        ];
    }

    /**
     * Finish the check-in for a given schedule.
     *
     * @param Schedule           $schedule
     * @param MonitorConfig|null $monitorConfig
     */
    public function finishCheckin(Schedule $schedule, ?MonitorConfig $monitorConfig = null): void
    {
        if (!isset($this->runningCheckins[$schedule->getId()])) {
            return;
        }

        \Sentry\captureCheckIn(
            slug: $schedule->getJobCode(),
            status: $schedule->getStatus() === Schedule::STATUS_SUCCESS ? CheckInStatus::ok() : CheckInStatus::error(),
            duration: microtime(true) - $this->runningCheckins[$schedule->getId()]['started_at'],
            monitorConfig: $monitorConfig,
            checkInId: $this->runningCheckins[$schedule->getId()]['check_in_id'],
        );

        unset($this->runningCheckins[$schedule->getId()]);
    }

    /**
     * Resolve a job's cron expression from static cron config (crontab.xml `schedule`/`config_path`).
     *
     * Used when the runtime schedule carries none, since `cron_expr` isn't a `cron_schedule` DB column.
     * Precedence and scope mirror Magento\Cron\Observer\ProcessCronQueueObserver::getCronExpression():
     * `config_path` (store-scoped) wins when set, `schedule` is only a fallback.
     *
     * @param string $jobCode
     *
     * @return string|null
     */
    private function resolveCronExpression(string $jobCode): ?string
    {
        if (array_key_exists($jobCode, $this->resolvedExpr)) {
            return $this->resolvedExpr[$jobCode];
        }

        $expr = null;
        foreach ($this->cronConfig->getJobs() as $group) {
            if (!isset($group[$jobCode])) {
                continue;
            }

            $job = $group[$jobCode];
            if (!empty($job['config_path'])) {
                $val  = $this->scopeConfig->getValue($job['config_path'], ScopeInterface::SCOPE_STORE);
                $expr = !$val ? null : (string) $val;
            }

            if (!$expr && !empty($job['schedule'])) {
                $expr = (string) $job['schedule'];
            }

            break;
        }

        return $this->resolvedExpr[$jobCode] = $expr;
    }
}
