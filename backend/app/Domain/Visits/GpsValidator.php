<?php

namespace App\Domain\Visits;

use App\Enums\GpsOutcome;
use Carbon\CarbonInterface;

/**
 * Layered, server-side GPS evidence evaluation. The app submits RAW readings; this class decides the
 * outcome and is a pure function of its inputs, so every rule is unit-testable.
 *
 *  - Outcomes: VALID, WARNING, SUSPICIOUS, REJECTED.
 *  - A single weak signal can never produce more than WARNING.
 *  - SUSPICIOUS needs several signals whose combined weight reaches the configured threshold.
 *  - REJECTED is reserved for HARD failures: invalid assignment, unusable accuracy, or being far
 *    beyond the geofence (when the campaign policy says "reject").
 *  - This is evidence for a human reviewer, NOT a guarantee against spoofing.
 */
class GpsValidator
{
    private const EARTH_RADIUS_M = 6371008.8;

    public function __construct(private array $rules) {}

    /** Config defaults merged with optional (agency/campaign) overrides. */
    public static function make(array $overrides = []): self
    {
        return new self(array_replace_recursive(config('field.gps'), $overrides));
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /**
     * @param array{lat:float,lng:float,radius_m:int|float} $target
     * @param array{
     *   lat:float, lng:float, accuracy_m:?float, device_time:?CarbonInterface, server_time:CarbonInterface,
     *   mock_flag?:?bool, offline_replay?:bool, device_changed?:bool, assignment_valid?:bool, assignment_problem?:?string,
     *   previous?:?array{lat:float,lng:float,at:CarbonInterface}, recent_points?:list<array{lat:float,lng:float}>
     * } $reading
     */
    public function evaluate(array $target, array $reading): GpsEvaluation
    {
        $r = $this->rules;
        $w = $r['weights'];
        $signals = [];
        $hard = false;

        $add = function (string $code, int|float $weight, string $detail, bool $isHard = false) use (&$signals, &$hard) {
            $signals[] = ['code' => $code, 'weight' => $weight, 'detail' => $detail, 'hard' => $isHard];
            $hard = $hard || $isHard;
        };

        $distance = self::haversine($target['lat'], $target['lng'], $reading['lat'], $reading['lng']);
        $accuracy = $reading['accuracy_m'] ?? null;
        $radius = (float) $target['radius_m'];

        // Accuracy gives the device some benefit of the doubt, capped so a terrible fix cannot explain away distance.
        $credit = $accuracy === null ? 0.0 : min((float) $accuracy, (float) $r['accuracy_credit_cap_m']);
        $effective = max(0.0, $distance - $credit);

        // 1. Assignment validity (hard).
        if (($reading['assignment_valid'] ?? true) === false) {
            $add('assignment_invalid', 5, $reading['assignment_problem'] ?? 'The assignment is not valid for a visit right now.', true);
        }

        // 2. Accuracy.
        if ($accuracy === null) {
            $add('accuracy_missing', $w['accuracy_poor'], 'The device did not report a GPS accuracy.');
        } elseif ($accuracy > $r['accuracy_unusable_m']) {
            $add('accuracy_unusable', 0, sprintf('GPS accuracy of %.0f m is too poor to validate a location. Move to open sky and retry.', $accuracy), true);
        } elseif ($accuracy > $r['accuracy_poor_m']) {
            $add('accuracy_poor', $w['accuracy_poor'], sprintf('GPS accuracy is poor (%.0f m).', $accuracy));
        }

        // 3. Geofence.
        if ($effective > $radius) {
            if ($distance > $r['far_distance_m']) {
                $add('geofence_far', $w['geofence_far'], sprintf('%.0f m from the location (geofence %.0f m).', $distance, $radius), ($r['far_action'] ?? 'reject') === 'reject');
            } elseif ($effective <= $radius * $r['near_multiplier']) {
                $add('geofence_near', $w['geofence_near'], sprintf('Slightly outside the geofence: %.0f m away (geofence %.0f m).', $distance, $radius));
            } else {
                $add('geofence_outside', $w['geofence_outside'], sprintf('Outside the geofence: %.0f m away (geofence %.0f m).', $distance, $radius));
            }
        }

        // 4. Device clock vs server clock (skipped for offline replays, where delay is expected).
        if (empty($reading['offline_replay']) && ($reading['device_time'] ?? null) !== null) {
            $skew = abs($reading['server_time']->getTimestamp() - $reading['device_time']->getTimestamp());
            if ($skew > $r['skew_suspicious_s']) {
                $add('timestamp_skew_large', $w['timestamp_skew_large'], sprintf('Device clock differs from the server by %d s.', $skew));
            } elseif ($skew > $r['skew_warn_s']) {
                $add('timestamp_skew', $w['timestamp_skew'], sprintf('Device clock differs from the server by %d s.', $skew));
            }
        }

        // 5. Impossible movement since the agent's previous recorded point.
        if (!empty($reading['previous'])) {
            $prev = $reading['previous'];
            $seconds = max(1, abs($reading['server_time']->getTimestamp() - $prev['at']->getTimestamp()));
            if ($seconds <= $r['travel_window_h'] * 3600) {
                $moved = self::haversine($prev['lat'], $prev['lng'], $reading['lat'], $reading['lng']);
                $speed = ($moved / 1000) / ($seconds / 3600);
                if ($moved > 500 && $speed > $r['max_speed_kmh']) {
                    $add('impossible_travel', $w['impossible_travel'], sprintf('Moved %.1f km in %d min (%.0f km/h).', $moved / 1000, intdiv($seconds, 60), $speed));
                }
            }
        }

        // 6. Device signals.
        if (($reading['mock_flag'] ?? null) === true) {
            $add('mock_location', $w['mock_location'], 'The device reported a mock/test location provider.');
        }
        if (!empty($reading['device_changed'])) {
            $add('device_changed', $w['device_changed'], "Different device than the agent's previous visit.");
        }

        // 7. Identical coordinates already used at other locations.
        foreach ($reading['recent_points'] ?? [] as $p) {
            if (abs($p['lat'] - $reading['lat']) < 1e-9 && abs($p['lng'] - $reading['lng']) < 1e-9) {
                $add('duplicate_coordinates', $w['duplicate_coordinates'], 'Identical coordinates were submitted for a different location.');
                break;
            }
        }

        $total = array_sum(array_column($signals, 'weight'));
        $outcome = match (true) {
            $hard => GpsOutcome::Rejected,
            $total >= $r['suspicious_threshold'] => GpsOutcome::Suspicious,
            $total >= 1 => GpsOutcome::Warning,
            default => GpsOutcome::Valid,
        };

        return new GpsEvaluation($outcome, round($distance, 2), round($effective, 2), $signals, (float) $total);
    }
}
