<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Test\Unit\Model;

use JustBetter\Sentry\Helper\Data;
use JustBetter\Sentry\Model\SentryCron;
use Magento\Cron\Model\ConfigInterface as CronConfigInterface;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SentryCronTest extends TestCase
{
    /**
     * @var Data&Stub
     */
    private $data;

    /**
     * @var CronConfigInterface&MockObject
     */
    private $cronConfig;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var TimezoneInterface&Stub
     */
    private $timezone;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    private SentryCron $sentryCron;

    protected function setUp(): void
    {
        $this->data = $this->createStub(Data::class);
        $this->data->method('isActive')->willReturn(true);
        $this->data->method('isCronMonitoringEnabled')->willReturn(true);
        $this->data->method('getTrackCrons')->willReturn(['tracked_job']);

        $this->cronConfig  = $this->createMock(CronConfigInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->timezone    = $this->createStub(TimezoneInterface::class);
        $this->timezone->method('getConfigTimezone')->willReturn('Europe/Copenhagen');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sentryCron = new SentryCron(
            $this->data,
            $this->cronConfig,
            $this->scopeConfig,
            $this->timezone,
            $this->logger,
        );
    }

    /**
     * Schedule's accessors are magic getters (AbstractModel::__call over getData()),
     * invisible to reflection-based createStub() — declare them explicitly, as
     * SentryLogTest does for Session::setSentryEventId.
     */
    private function scheduleStub(string $jobCode, string $status, array $cronExprArr = []): MockObject&Schedule
    {
        $schedule = $this->getMockBuilder(Schedule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->addMethods(['getJobCode', 'getStatus', 'getCronExprArr'])
            ->getMock();
        $schedule->method('getJobCode')->willReturn($jobCode);
        $schedule->method('getStatus')->willReturn($status);
        $schedule->method('getCronExprArr')->willReturn($cronExprArr);
        $schedule->method('getId')->willReturn(1);

        return $schedule;
    }

    /**
     * Runtime schedule present (schedule row still carries in-memory cron_expr from
     * generation) must win over cron config — the config resolver is never consulted.
     */
    public function testRuntimeCronExprWinsOverConfigResolver(): void
    {
        $this->cronConfig->expects(self::never())->method('getJobs');

        $schedule = $this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS, ['*', '*', '*', '*', '*']);

        $this->sentryCron->sendScheduleStatus($schedule);

        $this->logger->expects(self::never())->method('warning');
    }

    /**
     * No runtime cron_expr (schedule loaded from DB) + crontab.xml has a literal
     * <schedule> for the job -> resolved from cron config, check-in proceeds.
     */
    public function testResolvesFromLiteralScheduleInCronConfig(): void
    {
        $this->cronConfig->method('getJobs')->willReturn([
            'default' => ['tracked_job' => ['schedule' => '* * * * *']],
        ]);
        $this->scopeConfig->expects(self::never())->method('getValue');
        $this->logger->expects(self::never())->method('warning');

        $schedule = $this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS);

        $this->sentryCron->sendScheduleStatus($schedule);
    }

    /**
     * No runtime cron_expr + crontab.xml resolves the schedule via config_path
     * (e.g. index/consumers-style groups) -> read store-scoped, matching
     * Magento\Cron\Observer\ProcessCronQueueObserver::getConfigSchedule().
     */
    public function testResolvesFromConfigPathInCronConfig(): void
    {
        $this->cronConfig->method('getJobs')->willReturn([
            'default' => ['tracked_job' => ['config_path' => 'system/cron/tracked_job_schedule']],
        ]);
        $this->scopeConfig->method('getValue')
            ->with('system/cron/tracked_job_schedule', ScopeInterface::SCOPE_STORE)
            ->willReturn('*/5 * * * *');
        $this->logger->expects(self::never())->method('warning');

        $schedule = $this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS);

        $this->sentryCron->sendScheduleStatus($schedule);
    }

    /**
     * Job declares both config_path and schedule (crontab.xsd permits both) ->
     * config_path wins, matching core's getCronExpression() precedence.
     */
    public function testConfigPathTakesPrecedenceOverLiteralSchedule(): void
    {
        $this->cronConfig->method('getJobs')->willReturn([
            'default' => ['tracked_job' => [
                'config_path' => 'system/cron/tracked_job_schedule',
                'schedule'    => '0 0 * * *',
            ]],
        ]);
        $this->scopeConfig->method('getValue')
            ->with('system/cron/tracked_job_schedule', ScopeInterface::SCOPE_STORE)
            ->willReturn('*/5 * * * *');
        $this->logger->expects(self::never())->method('warning');

        $schedule = $this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS);

        $this->sentryCron->sendScheduleStatus($schedule);
    }

    /**
     * Job declares both config_path and schedule, but config_path resolves empty
     * (nothing configured) -> falls back to the literal schedule instead of skipping.
     */
    public function testFallsBackToLiteralScheduleWhenConfigPathResolvesEmpty(): void
    {
        $this->cronConfig->method('getJobs')->willReturn([
            'default' => ['tracked_job' => [
                'config_path' => 'system/cron/tracked_job_schedule',
                'schedule'    => '0 0 * * *',
            ]],
        ]);
        $this->scopeConfig->method('getValue')->willReturn('');
        $this->logger->expects(self::never())->method('warning');

        $schedule = $this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS);

        $this->sentryCron->sendScheduleStatus($schedule);
    }

    /**
     * config_path resolves to an empty value and no schedule fallback exists ->
     * treated as unresolvable: check-in is skipped, not sent with an empty config.
     */
    public function testConfigPathResolvingToEmptyValueSkipsCheckin(): void
    {
        $this->cronConfig->method('getJobs')->willReturn([
            'default' => ['tracked_job' => ['config_path' => 'system/cron/tracked_job_schedule']],
        ]);
        $this->scopeConfig->method('getValue')->willReturn('');
        $this->logger->expects(self::once())->method('warning')
            ->with(self::stringContains('skipped'), ['job_code' => 'tracked_job']);

        $schedule = $this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS);

        $this->sentryCron->sendScheduleStatus($schedule);
    }

    /**
     * Job code absent from cron config entirely (schedule set only in admin, or
     * dynamic) -> unresolvable, skip rather than send a config-less check-in.
     */
    public function testJobMissingFromCronConfigSkipsCheckin(): void
    {
        $this->cronConfig->method('getJobs')->willReturn(['default' => []]);
        $this->logger->expects(self::once())->method('warning')
            ->with(self::stringContains('skipped'), ['job_code' => 'tracked_job']);

        $schedule = $this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS);

        $this->sentryCron->sendScheduleStatus($schedule);
    }

    /**
     * Job present in cron config but declares neither `schedule` nor `config_path`
     * (both minOccurs="0" in crontab.xsd, legal) -> unresolvable, skip.
     */
    public function testJobPresentWithNeitherScheduleNorConfigPathSkipsCheckin(): void
    {
        $this->cronConfig->method('getJobs')->willReturn([
            'default' => ['tracked_job' => ['instance' => 'Some\\Job\\Class', 'method' => 'execute']],
        ]);
        $this->scopeConfig->expects(self::never())->method('getValue');
        $this->logger->expects(self::once())->method('warning')
            ->with(self::stringContains('skipped'), ['job_code' => 'tracked_job']);

        $schedule = $this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS);

        $this->sentryCron->sendScheduleStatus($schedule);
    }

    /**
     * Resolver result is memoized per job code — a second status flip for the same
     * job must not re-walk cron config.
     */
    public function testResolvedExpressionIsMemoizedPerJobCode(): void
    {
        $this->cronConfig->expects(self::once())->method('getJobs')->willReturn([
            'default' => ['tracked_job' => ['schedule' => '* * * * *']],
        ]);

        $this->sentryCron->sendScheduleStatus($this->scheduleStub('tracked_job', Schedule::STATUS_RUNNING));
        $this->sentryCron->sendScheduleStatus($this->scheduleStub('tracked_job', Schedule::STATUS_SUCCESS));
    }

    /**
     * Existing gating is unchanged: job not in track_crons -> early return before
     * cron config is ever consulted.
     */
    public function testJobNotInTrackCronsNeverConsultsCronConfig(): void
    {
        $this->cronConfig->expects(self::never())->method('getJobs');

        $schedule = $this->scheduleStub('untracked_job', Schedule::STATUS_SUCCESS);

        $this->sentryCron->sendScheduleStatus($schedule);
    }
}
