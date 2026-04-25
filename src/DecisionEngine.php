<?php declare(strict_types=1);

namespace Aleoosha\DssCore;

use Aleoosha\DssCore\DTO\DecisionResult;
use Aleoosha\Support\Types\FixedPoint;
use Aleoosha\TauPid\Contracts\PidCalculatorInterface;
use Aleoosha\TauPid\Contracts\PidTunerInterface;
use Aleoosha\TauPid\Contracts\PidStateRepositoryInterface;
use Aleoosha\TauPid\Contracts\DTO\MetricProfile;
use Aleoosha\TauPid\Contracts\DTO\FixedPidResult;
use Aleoosha\TauPid\Contracts\DTO\PidSettings;
use Aleoosha\Telemetry\Contracts\DTO\NodeMetrics;

/**
 * DecisionEngine - The central intelligence of the HiveMind system.
 * Orchestrates metric evaluation, dynamic gain synthesis, and PID execution.
 */
class DecisionEngine
{
    public function __construct(
        protected PidCalculatorInterface $calculator,
        protected PidTunerInterface $tuner,
        protected PidStateRepositoryInterface $repository,
        protected array $profiles
    ) {}

    /**
     * Evaluates current node metrics and determines the global shedding rate.
     */
    public function evaluate(NodeMetrics $metrics): DecisionResult
    {
        $maxShedding = new FixedPoint(0);
        $alerts = [];
        $now = (int)(microtime(true) * 1000);

        foreach ($this->profiles as $profile) {
            $current = $this->extractValue($metrics, $profile->metricName);
            $stateKey = "pid_state_{$profile->metricName}";
            $history = $this->repository->getState($stateKey);

            // 1. Calculate normalized error based on domain-specific activation margin
            $error = $this->calculateError($current, $profile);

            // 2. Handle safety zone: Reset "pain" (integral) while preserving "experience" (learned gains)
            if ($error->value === 0) {
                $this->repository->saveState($stateKey, $this->coolDownState($now, $history, $profile));
                continue;
            }

            // 3. Synthesize settings and execute the PID cycle
            $result = $this->executeMetricCycle($error, $history, $profile, $now);
            $this->repository->saveState($stateKey, $result);

            // 4. Update aggregate system state
            if ($result->output->isGreaterThan($maxShedding)) {
                $maxShedding = $result->output;
            }

            if ($current->isGreaterThanOrEqual($profile->targetThreshold)) {
                $alerts[] = $profile->metricName;
            }
        }

        return new DecisionResult($maxShedding, $maxShedding, $alerts, $now);
    }

    /**
     * Normalizes the error signal relative to the activation margin (e.g., 90% of target).
     */
    private function calculateError(FixedPoint $current, MetricProfile $profile): FixedPoint
    {
        $target = $profile->targetThreshold;
        $activationPoint = $target->multiply(FixedPoint::fromFloat($profile->activationMargin));

        if ($current->isLessThan($activationPoint)) {
            return new FixedPoint(0);
        }

        $gap = $target->subtract($activationPoint);
        if ($gap->value <= 0) {
            $gap = new FixedPoint(1);
        }

        // Returns error in range [0.0 - 1.0+] relative to the danger zone
        return $current->subtract($activationPoint)->divide($gap);
    }

    /**
     * Orchestrates the evolutionary tuning and PID calculation step.
     */
    private function executeMetricCycle(FixedPoint $error, ?FixedPidResult $history, MetricProfile $profile, int $now): FixedPidResult
    {
        // Get initial gains from settling time or retrieve learned gains from history
        $baseSettings = $this->generateBaseSettings($profile, $history);

        // Adaptive step: let the tuner adjust gains based on current dynamics
        $activeSettings = $this->tuner->tune($baseSettings, $error, $history);
        
        $deltaTime = $history ? ($now - $history->timestampMs) : 1000;

        // Mathematical step: compute control output
        return $this->calculator->calculate($error, $deltaTime, $history, $activeSettings);
    }

    /**
     * Synthesizes PID gains based on the physical settling time of the process.
     * Fast processes (CPU) get higher gains; slow processes (DB) get smoother gains.
     */
    private function generateBaseSettings(MetricProfile $profile, ?FixedPidResult $history): PidSettings
    {
        // If history exists, prefer learned (evolved) coefficients
        if ($history && $history->kp->value > 0) {
            return new PidSettings($history->kp, $history->ki, $history->kd, FixedPoint::fromInt(1));
        }

        // Default synthesis rule: Kp = 10 / SettlingTime
        $kpValue = 10.0 / max($profile->settlingTimeSeconds, 1);
        $kiValue = $kpValue / 2.0;
        $kdValue = $kpValue * 0.5;

        return new PidSettings(
            FixedPoint::fromFloat($kpValue),
            FixedPoint::fromFloat($kiValue),
            FixedPoint::fromFloat($kdValue),
            FixedPoint::fromInt(1)
        );
    }

    /**
     * Wipes current error and integral state during safety periods 
     * but prevents the engine from "forgetting" learned PID gains.
     */
    private function coolDownState(int $ms, ?FixedPidResult $history, MetricProfile $p): FixedPidResult
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
     * Resolves telemetry property based on the metric profile key.
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
