<?php

namespace Tests\Unit;

use App\Domain\Visits\GpsValidator;
use App\Enums\GpsOutcome;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** Pure-function tests for the GPS engine: no framework or database needed. */
class GpsValidatorTest extends TestCase
{
    private const LAT = -1.2673;
    private const LNG = 36.8118;

    private function validator(array $overrides = []): GpsValidator
    {
        $rules = (require __DIR__.'/../../config/field.php')['gps'];

        return new GpsValidator(array_replace_recursive($rules, $overrides));
    }

    /** Offset in metres north of the target (1 deg latitude ~ 111.19 km). */
    private function reading(float $metersNorth = 0, array $extra = []): array
    {
        $now = Carbon::parse('2026-10-13 10:00:00');

        return $extra + [
            'lat' => self::LAT + $metersNorth / 111195.0, 'lng' => self::LNG, 'accuracy_m' => 10.0,
            'device_time' => $now, 'server_time' => $now, 'assignment_valid' => true,
        ];
    }

    private function target(int $radius = 100): array
    {
        return ['lat' => self::LAT, 'lng' => self::LNG, 'radius_m' => $radius];
    }

    public function test_haversine_known_distance(): void
    {
        // Nairobi (-1.286389, 36.817223) to Mombasa (-4.043477, 39.668206) is about 440 km.
        $d = GpsValidator::haversine(-1.286389, 36.817223, -4.043477, 39.668206);
        $this->assertEqualsWithDelta(440_000, $d, 5_000);
        $this->assertSame(0.0, GpsValidator::haversine(1.0, 2.0, 1.0, 2.0));
    }

    public function test_inside_geofence_is_valid(): void
    {
        $e = $this->validator()->evaluate($this->target(), $this->reading(30));
        $this->assertSame(GpsOutcome::Valid, $e->outcome);
        $this->assertSame([], $e->reasons);
    }

    public function test_accuracy_credit_is_capped(): void
    {
        // 180 m away with 150 m reported accuracy: credit is capped at 100 m, so effective distance is 80 m... inside radius 100.
        $e = $this->validator()->evaluate($this->target(), $this->reading(180, ['accuracy_m' => 150.0]));
        $this->assertEqualsWithDelta(80, $e->effectiveDistanceM, 3);
        // 400 m away can never be explained by accuracy alone.
        $far = $this->validator()->evaluate($this->target(), $this->reading(400, ['accuracy_m' => 400.0]));
        $this->assertNotSame(GpsOutcome::Valid, $far->outcome);
        $this->assertGreaterThan(250, $far->effectiveDistanceM);
    }

    public function test_slightly_outside_is_only_a_warning(): void
    {
        $e = $this->validator()->evaluate($this->target(), $this->reading(150));
        $this->assertSame(GpsOutcome::Warning, $e->outcome);
        $this->assertSame('geofence_near', $e->reasons[0]['code']);
    }

    public function test_single_weak_signal_never_exceeds_warning(): void
    {
        $e = $this->validator()->evaluate($this->target(), $this->reading(0, ['mock_flag' => true]));
        $this->assertSame(GpsOutcome::Warning, $e->outcome);
    }

    public function test_multiple_signals_become_suspicious(): void
    {
        $e = $this->validator()->evaluate($this->target(), $this->reading(250, ['mock_flag' => true, 'device_changed' => true]));
        $this->assertSame(GpsOutcome::Suspicious, $e->outcome);
        $this->assertGreaterThanOrEqual(3, $e->totalWeight);
    }

    public function test_far_away_is_rejected_by_default_and_flaggable_by_policy(): void
    {
        $far = $this->reading(5000);
        $this->assertSame(GpsOutcome::Rejected, $this->validator()->evaluate($this->target(), $far)->outcome);
        $this->assertNotSame(GpsOutcome::Rejected, $this->validator(['far_action' => 'flag'])->evaluate($this->target(), $far)->outcome);
    }

    public function test_unusable_accuracy_is_rejected(): void
    {
        $e = $this->validator()->evaluate($this->target(), $this->reading(0, ['accuracy_m' => 900.0]));
        $this->assertSame(GpsOutcome::Rejected, $e->outcome);
    }

    public function test_invalid_assignment_is_rejected(): void
    {
        $e = $this->validator()->evaluate($this->target(), $this->reading(0, ['assignment_valid' => false, 'assignment_problem' => 'Not today.']));
        $this->assertSame(GpsOutcome::Rejected, $e->outcome);
        $this->assertStringContainsString('Not today.', $e->summary());
    }

    public function test_clock_skew_is_flagged_but_not_for_offline_replays(): void
    {
        $now = Carbon::parse('2026-10-13 10:00:00');
        $skewed = $this->reading(0, ['device_time' => $now->copy()->subHours(3)]);
        $this->assertSame('timestamp_skew_large', $this->validator()->evaluate($this->target(), $skewed)->reasons[0]['code']);
        $this->assertSame(GpsOutcome::Valid, $this->validator()->evaluate($this->target(), $skewed + ['offline_replay' => true])->outcome);
    }

    public function test_impossible_travel_is_detected(): void
    {
        $now = Carbon::parse('2026-10-13 10:00:00');
        // 440 km away from the previous point only 20 minutes ago.
        $r = $this->reading(0, ['previous' => ['lat' => -4.043477, 'lng' => 39.668206, 'at' => $now->copy()->subMinutes(20)]]);
        $codes = array_column($this->validator()->evaluate($this->target(), $r)->reasons, 'code');
        $this->assertContains('impossible_travel', $codes);
    }

    public function test_duplicate_coordinates_at_another_location_are_flagged(): void
    {
        $r = $this->reading(0);
        $r['recent_points'] = [['lat' => $r['lat'], 'lng' => $r['lng']]];
        $codes = array_column($this->validator()->evaluate($this->target(), $r)->reasons, 'code');
        $this->assertContains('duplicate_coordinates', $codes);
    }

    public function test_campaign_override_changes_the_radius_rule(): void
    {
        $strict = $this->validator(['near_multiplier' => 1.0]);
        // 150 m is "near" with the default multiplier (warning) but plain "outside" (weight 2) when strict.
        $this->assertSame('geofence_outside', $strict->evaluate($this->target(), $this->reading(150))->reasons[0]['code']);
    }
}
