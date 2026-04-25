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

    public function evaluate(NodeMetrics $metrics, ?FixedPidResult $previousState = null): DecisionResult
    {
        $maxShedding = new FixedPoint(0);
        $alerts = [];
        $now = (int)(microtime(true) * 1000);

        foreach ($this->profiles as $profile) {
            $currentValue = $this->extractValue($metrics, $profile->metricName);
            $target = $profile->targetThreshold;

            // 1. Define Safety Zone (e.g., 80% of threshold)
            // If threshold is 10%, we start reacting at 8%
            $margin = FixedPoint::fromFloat(0.8);
            $activationPoint = $target->multiply($margin);

            if ($currentValue->isLessThan($activationPoint)) {
                // ZONE 0: System is safe. Hard reset for this metric.
                $error = new FixedPoint(0);
                $stateKey = "pid_state_{$profile->metricName}";
                $this->repository->saveState($stateKey, $this->emptyState($now, $profile));
                continue;
            }

            // ZONE 1: Dangerous area. 
            // Calculate error relative to the activation point, not zero.
            $denom = $target->subtract($activationPoint);
            if ($denom->value === 0) $denom = new FixedPoint(1);
            
            $error = $currentValue->subtract($activationPoint)->divide($denom);

            // 2. Standard PID Flow
            $stateKey = "pid_state_{$profile->metricName}";
            $history = $this->repository->getState($stateKey);
            $activeSettings = $this->tuner->tune($profile->pidSettings, $error, $history);
            
            $deltaTime = $history ? ($now - $history->timestampMs) : 1000;
            $result = $this->calculator->calculate($error, $deltaTime, $history, $activeSettings);

            $this->repository->saveState($stateKey, $result);

            if ($result->output->isGreaterThan($maxShedding)) {
                $maxShedding = $result->output;
            }

            if ($currentValue->isGreaterThan($target)) {
                $alerts[] = $profile->metricName;
            }
        }

        return new DecisionResult($maxShedding, $maxShedding, $alerts, $now);
    }

    private function emptyState(int $ms, MetricProfile $p): FixedPidResult {
        $z = new FixedPoint(0);
        return new FixedPidResult($z, $z, $z, $ms, $p->pidSettings->kp, $p->pidSettings->ki, $p->pidSettings->kd);
    }

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
