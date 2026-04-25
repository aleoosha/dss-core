<?php 

declare(strict_types=1);

namespace Aleoosha\DssCore\DTO;

use Aleoosha\Support\Types\FixedPoint;

/**
 * The final decision output from the DSS core.
 */
final class DecisionResult
{
    public function __construct(
        public readonly FixedPoint $systemLoad,     // Calculated total load (0-1.0)
        public readonly FixedPoint $sheddingRate,   // Probability of dropping requests (0-1.0)
        public readonly array $alerts,              // List of triggered metric keys
        public readonly int $timestampMs            // Time of decision
    ) {}
}
