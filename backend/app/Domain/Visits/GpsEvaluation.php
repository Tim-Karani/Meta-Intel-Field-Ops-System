<?php

namespace App\Domain\Visits;

use App\Enums\GpsOutcome;

final class GpsEvaluation
{
    /** @param list<array{code:string,weight:int|float,detail:string,hard:bool}> $reasons */
    public function __construct(
        public readonly GpsOutcome $outcome,
        public readonly float $distanceM,
        public readonly float $effectiveDistanceM,
        public readonly array $reasons,
        public readonly float $totalWeight,
    ) {}

    public function summary(): string
    {
        return $this->reasons === [] ? 'Within the geofence.' : implode(' ', array_column($this->reasons, 'detail'));
    }

    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value, 'distance_m' => $this->distanceM, 'effective_distance_m' => $this->effectiveDistanceM,
            'total_weight' => $this->totalWeight, 'reasons' => $this->reasons,
        ];
    }
}
