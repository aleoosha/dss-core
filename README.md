# Resilience DSS Core

**Decision Support System** (DSS) kernel for automated system resilience and load management.

## Overview
The DSS Core is the central intelligence of the ecosystem. It orchestrates the flow between **Telemetry** (sensing) and **PID Control** (logic) to produce high-level survival decisions (Load Shedding, Health Scoring).

It operates on a multi-profile basis, evaluating various system signals simultaneously to find the most critical bottleneck.

## How it works
1. Receives raw telemetry via `NodeMetrics`.
2. Evaluates multiple `MetricProfiles` using adaptive PID logic.
3. Produces a `DecisionResult` indicating the required shedding rate and system health.

## Installation
```bash
composer require aleoosha/dss-core
```

## Level in Architecture
**Level 1 (Logic)**: Orchestrates Level 0 libraries to perform complex decision-making.
