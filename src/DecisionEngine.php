<?php declare(strict_types=1);

namespace Aleoosha\DssCore;

use Aleoosha\DssCore\DTO\DecisionResult;
use Aleoosha\Support\Types\FixedPoint;
use Aleoosha\TauPid\Contracts\PidCalculatorInterface;
use Aleoosha\TauPid\Contracts\PidTunerInterface;
use Aleoosha\TauPid\Contracts\PidStateRepositoryInterface;
use Aleoosha\TauPid\Contracts\DTO\MetricProfile;
use Aleoosha\TauPid\Contracts\DTO\FixedPidResult;
use Aleoosha\Telemetry\Contracts\DTO\NodeMetrics;

class DecisionEngine
{
    public function __construct(
        protected PidCalculatorInterface $calculator,
        protected PidTunerInterface $tuner,
        protected PidStateRepositoryInterface $repository,
        protected array $profiles
    ) {}

    /**
     * Entry point for evaluating system health and making a shedding decision.
     */
    public function evaluate(NodeMetrics $metrics): DecisionResult
    {
        $maxShedding = new FixedPoint(0);
        $alerts = [];
        $now = (int)(microtime(true) * 1000);

        foreach ($this->profiles as $profile) {
            $currentValue = $this->extractValue($metrics, $profile->metricName);
            $stateKey = "pid_state_{$profile->metricName}";
            $history = $this->repository->getState($stateKey);

            // 1. Calculate normalized error using pre-emptive wall logic
            $error = $this->calculateNormalizedError($currentValue, $profile->targetThreshold);

            // 2. Handle safety zone (Dead-zone)
            if ($error->value === 0) {
                $this->repository->saveState($stateKey, $this->resetState($now, $history, $profile));
                continue;
            }

            // 3. Run PID cycle (Tuning + Calculation)
            $result = $this->runPidCycle($error, $history, $profile, $now);
            $this->repository->saveState($stateKey, $result);

            // 4. Update aggregate results
            if ($result->output->isGreaterThan($maxShedding)) {
                $maxShedding = $result->output;
            }

            if ($currentValue->isGreaterThanOrEqual($profile->targetThreshold)) {
                $alerts[] = $profile->metricName;
            }
        }

        return new DecisionResult($maxShedding, $maxShedding, $alerts, $now);
    }

    /**
     * Calculates error based on 90% pre-emptive activation point.
     */
    private function calculateNormalizedError(FixedPoint $current, FixedPoint $target): FixedPoint
    {
        $activationPoint = $target->multiply(FixedPoint::fromFloat(0.9));

        if ($current->isLessThan($activationPoint)) {
            return new FixedPoint(0);
        }

        $gap = $target->subtract($activationPoint);
        if ($gap->value <= 0) {
            $gap = new FixedPoint(1);
        }

        return $current->subtract($activationPoint)->divide($gap);
    }

    /**
     * Executes tuning and PID calculation for a specific metric.
     */
    private function runPidCycle(FixedPoint $error, ?FixedPidResult $history, MetricProfile $profile, int $now): FixedPidResult
    {
        $activeSettings = $this->tuner->tune($profile->pidSettings, $error, $history);
        $deltaTime = $history ? ($now - $history->timestampMs) : 1000;

        return $this->calculator->calculate($error, $deltaTime, $history, $activeSettings);
    }

    /**
     * Resets transient calculation values while preserving the "experience" (learned gains).
     */
    private function resetState(int $ms, ?FixedPidResult $history, MetricProfile $p): FixedPidResult
    {
        $z = new FixedPoint(0);
        return new FixedPidResult(
            output: $z,
            lastError: $z,
            integral: $z,
            timestampMs: $ms,
            kp: $history?->kp ?? $p->pidSettings->kp,
            ki: $history?->ki ?? $p->pidSettings->ki,
            kd: $history?->kd ?? $p->pidSettings->kd
        );
    }

    /**
     * Maps metric names to NodeMetrics properties.
     */
    private function extractValue(NodeMetrics $m, string $key): FixedPoint
    {
        return match ($key) {
            'cpu_percent' => $m->cpu,
            'memory_percent' => $m->memory,
            'db_latency_ms' => $m->dbLatency,
            'api_latency_ms' => $m->apiLatency,
            default => new FixedPoint(0),
        };
    }
}
