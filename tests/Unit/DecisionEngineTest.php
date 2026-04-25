<?php

use Aleoosha\DssCore\DecisionEngine;
use Aleoosha\Support\Types\FixedPoint;
use Aleoosha\TauPid\Contracts\PidCalculatorInterface;
use Aleoosha\TauPid\Contracts\PidTunerInterface;
use Aleoosha\TauPid\Contracts\PidStateRepositoryInterface;
use Aleoosha\TauPid\Contracts\DTO\MetricProfile;
use Aleoosha\TauPid\Contracts\DTO\PidSettings;
use Aleoosha\TauPid\Contracts\DTO\FixedPidResult;
use Aleoosha\Telemetry\Contracts\DTO\NodeMetrics;
use Mockery;

afterEach(function () {
    Mockery::close();
});

test('it calculates high shedding rate when metrics exceed thresholds', function () {
    // 1. Готовим зависимости (Mocks)
    $calculator = Mockery::mock(PidCalculatorInterface::class);
    $tuner = Mockery::mock(PidTunerInterface::class);
    $repository = Mockery::mock(PidStateRepositoryInterface::class);

    // Данные для настройки
    $settings = new PidSettings(
        FixedPoint::fromFloat(0.5), 
        FixedPoint::fromFloat(0.1), 
        FixedPoint::fromFloat(0.1), 
        FixedPoint::fromInt(100)
    );

    $profile = new MetricProfile('cpu_percent', FixedPoint::fromFloat(0.5), $settings);

    // 2. Ожидаемое поведение: имитируем, что ПИД насчитал 80% сброса нагрузки
    $expectedResult = new FixedPidResult(
        FixedPoint::fromFloat(0.8), // output 80%
        FixedPoint::fromFloat(0.1), FixedPoint::fromFloat(0.1),
        (int)(microtime(true)*1000), 
        $settings->kp, $settings->ki, $settings->kd
    );

    $tuner->shouldReceive('tune')->andReturn($settings);
    $repository->shouldReceive('getState')->andReturn(null);
    $repository->shouldReceive('saveState')->once();
    $calculator->shouldReceive('calculate')->andReturn($expectedResult);

    // 3. Запускаем "Мозг"
    $engine = new DecisionEngine($calculator, $tuner, $repository, [$profile]);
    
    // Имитируем плохие метрики (CPU = 90%)
    $metrics = new NodeMetrics(
        FixedPoint::fromFloat(0.9), // CPU
        FixedPoint::fromInt(0), FixedPoint::fromInt(0), FixedPoint::fromInt(0),
        (int)(microtime(true)*1000), 'node-1'
    );

    $decision = $engine->evaluate($metrics);

    // 4. Проверяем вердикт
    expect($decision->sheddingRate->toFloat())->toBe(0.8);
    expect($decision->alerts)->toContain('cpu_percent');
});
