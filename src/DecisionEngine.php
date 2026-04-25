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

    public function evaluate(NodeMetrics $metrics): DecisionResult
    {
        $maxShedding = new FixedPoint(0);
        $alerts = [];
        $now = (int)(microtime(true) * 1000);

        foreach ($this->profiles as $profile) {
            $currentValue = $this->extractValue($metrics, $profile->metricName);
            $target = $profile->targetThreshold;
            $stateKey = "pid_state_{$profile->metricName}";
            
            $history = $this->repository->getState($stateKey);

            // 1. Normalization: Signal = Current / Target (1.0 means exactly at threshold)
            $signal = $currentValue->divide($target);
            $activationPoint = new FixedPoint(1000); // 1.0 representation

            if ($signal->isLessThan($activationPoint)) {
                // System Safe: Reset calc state, but PRESERVE learned coefficients
                $this->repository->saveState($stateKey, $this->resetState($now, $history, $profile));
                continue;
            }

            // 2. Error calculation (Normalized)
            // If CPU is 12% and Target is 10% -> Signal is 1.2 -> Error is 0.2
            $error = $signal->subtract($activationPoint);

            // 3. Process PID
            $activeSettings = $this->tuner->tune($profile->pidSettings, $error, $history);
            $deltaTime = $history ? ($now - $history->timestampMs) : 1000;
            $result = $this->calculator->calculate($error, $deltaTime, $history, $activeSettings);

            $this->repository->saveState($stateKey, $result);

            if ($result->output->isGreaterThan($maxShedding)) {
                $maxShedding = $result->output;
            }

            if ($signal->isGreaterThan($activationPoint)) {
                $alerts[] = $profile->metricName;
            }
        }

        return new DecisionResult($maxShedding, $maxShedding, $alerts, $now);
    }

    private function resetState(int $ms, ?FixedPidResult $history, MetricProfile $p): FixedPidResult {
        $z = new FixedPoint(0);
        return new FixedPidResult(
            $z, $z, $z, $ms, 
            $history?->kp ?? $p->pidSettings->kp, 
            $history?->ki ?? $p->pidSettings->ki, 
            $history?->kd ?? $p->pidSettings->kd
        );
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
