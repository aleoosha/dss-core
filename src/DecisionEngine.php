<?php declare(strict_types=1);

namespace Aleoosha\DssCore;

use Aleoosha\DssCore\DTO\DecisionResult;
use Aleoosha\Support\Types\FixedPoint;
use Aleoosha\TauPid\Contracts\PidCalculatorInterface;
use Aleoosha\TauPid\Contracts\PidTunerInterface;
use Aleoosha\TauPid\Contracts\PidStateRepositoryInterface;
use Aleoosha\TauPid\Contracts\DTO\FixedPidResult;
use Aleoosha\TauPid\Contracts\DTO\MetricProfile;
use Aleoosha\Telemetry\Contracts\DTO\NodeMetrics;

class DecisionEngine
{
    /** @param MetricProfile[] $profiles */
    public function __construct(
        protected PidCalculatorInterface $calculator,
        protected PidTunerInterface $tuner,
        protected PidStateRepositoryInterface $repository,
        protected array $profiles
    ) {}

    public function evaluate(NodeMetrics $metrics, ?FixedPidResult $previousState): DecisionResult
    {
        $maxShedding = FixedPoint::fromInt(0);
        $alerts = [];
        $now = (int)(microtime(true) * 1000);

        foreach ($this->profiles as $profile) {
            $currentValue = $this->extractValue($metrics, $profile->metricName);
            
            // Error = (Current - Target) / Target (relative error)
            // In FixedPoint: (Value - Target) * SCALE / Target
            $error = $currentValue->subtract($profile->targetThreshold)
                ->multiply(FixedPoint::fromInt(1)) 
                ->divide($profile->targetThreshold);

            $stateKey = "pid_state_{$profile->metricName}";
            $previousState = $this->repository->getState($stateKey);

            // 1. Tuning
            $activeSettings = $this->tuner->tune($profile->pidSettings, $currentValue, $previousState);

            // 2. Calculation
            $deltaTime = $previousState ? ($now - $previousState->timestampMs) : 1000;
            $result = $this->calculator->calculate($error, $deltaTime, $previousState, $activeSettings);

            // 3. Save state
            $this->repository->saveState($stateKey, $result);

            if ($result->output->isGreaterThan($maxShedding)) {
                $maxShedding = $result->output;
            }
            
            if ($currentValue->isGreaterThan($profile->targetThreshold)) {
                $alerts[] = $profile->metricName;
            }
        }

        return new DecisionResult($maxShedding, $maxShedding, $alerts, $now);
    }

    private function extractValue(NodeMetrics $m, string $key): FixedPoint
    {
        return match ($key) {
            'cpu_percent' => $m->cpu,
            'memory_percent' => $m->memory,
            'db_latency_ms' => $m->dbLatency,
            'api_latency_ms' => $m->apiLatency,
            default => FixedPoint::fromInt(0),
        };
    }
}
