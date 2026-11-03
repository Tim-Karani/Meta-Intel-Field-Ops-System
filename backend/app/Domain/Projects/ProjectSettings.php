<?php

namespace App\Domain\Projects;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "How this project runs": the setup an administrator picks once when creating a campaign (from a template) and which
 * the whole system then follows. The same JSON is stored on a template and copied onto each campaign, so a project can be
 * tuned without touching the template it came from.
 *
 *   outlet_source   journey_plan | pre_mapped | agent_adds | mixed  -- where the outlets come from (guidance + labels)
 *   journey_plan    true  = permanent agents follow a Fixed Journey Plan; the scheduler creates their visits automatically
 *   tasks           which task tiles the agent sees on a visit (form, sales, osa, merchandising, photos)
 *   min_photos      photos needed before a visit can be submitted
 *   visit_photos    true = a check-in photo and a check-out photo are required (proves the assigned agent is the one at the outlet)
 *   visit_label     what the app calls a visit ("Visit", "Activation", "Roadshow stop")
 *   outlet_type     default type when an agent adds an outlet
 *   review          always 'exceptions': a visit at an outlet whose position the office has set or confirmed, with clean GPS, is
 *                   approved automatically; only visits at new/unconfirmed outlets or with GPS warnings are reviewed by a person.
 *   targets         optional per-project goals shown against the reports: osa_pct, sos_pct, coverage_pct (0-100) and visits_per_outlet (a month)
 *   (Who may add outlets in the field is a switch on each agent: agent_profiles.can_add_outlets.)
 *   capture_geo     office_only | first_visit  -- office_only: outlets must be given their position on the web (typed, picked on the
 *                   map or imported); agents cannot create a position by checking in. first_visit: the first valid check-in maps an
 *                   outlet that has no position (it is then flagged for confirmation).
 */
final class ProjectSettings
{
    public const SOURCES = ['journey_plan', 'pre_mapped', 'agent_adds', 'mixed'];
    public const TASKS = ['form', 'sales', 'osa', 'merchandising', 'photos'];
    public const EMPLOYMENT = ['permanent', 'reliever'];
    public const REVIEW = ['exceptions'];
    public const CAPTURE_GEO = ['office_only', 'first_visit'];

    public const DEFAULTS = [
        'outlet_source' => 'mixed',
        'journey_plan' => false,
        // Sales are only for projects where the agent sells (sales reps, foot soldiers). Merchandising-type work reads
        // sales from the outlet's own records, so the tile is off unless a project turns it on.
        'tasks' => ['form' => true, 'sales' => false, 'osa' => true, 'merchandising' => true, 'photos' => true],
        'min_photos' => 0,
        'visit_photos' => true,
        'review' => 'exceptions',
        'capture_geo' => 'office_only',
        'visit_label' => 'Visit',
        'outlet_type' => 'retail_store',
        'targets' => ['osa_pct' => null, 'sos_pct' => null, 'coverage_pct' => null, 'visits_per_outlet' => null],
    ];

    /** The built-in templates (seeded by migration, marked is_system, not editable; copy one to customise). */
    public const PRESETS = [
        'merchandising' => [
            'name' => 'Merchandising',
            'description' => 'Permanent merchandisers follow a Fixed Journey Plan of outlets by weekday. Relievers can add the outlets they cover. Shelf, availability and photo work.',
            'settings' => [
                'outlet_source' => 'journey_plan', 'journey_plan' => true,
                'tasks' => ['form' => true, 'sales' => false, 'osa' => true, 'merchandising' => true, 'photos' => true],
                'min_photos' => 2, 'visit_label' => 'Visit', 'outlet_type' => 'supermarket', 'capture_geo' => 'office_only',
            ],
        ],
        'activation' => [
            'name' => 'Activation',
            'description' => 'Outlets are set up (and mapped) on the web. Agents only check in at them and run the activation: form, sales and photos.',
            'settings' => [
                'outlet_source' => 'pre_mapped', 'journey_plan' => false,
                'tasks' => ['form' => true, 'sales' => true, 'osa' => false, 'merchandising' => false, 'photos' => true],
                'min_photos' => 2, 'visit_label' => 'Activation', 'outlet_type' => 'retail_store', 'capture_geo' => 'office_only',
            ],
        ],
        'roadshow' => [
            'name' => 'Roadshow',
            'description' => 'Agents move between venues and add each stop as they arrive; its location is saved when they check in. Sales, form and photos.',
            'settings' => [
                'outlet_source' => 'agent_adds', 'journey_plan' => false,
                'tasks' => ['form' => true, 'sales' => true, 'osa' => false, 'merchandising' => false, 'photos' => true],
                'min_photos' => 3, 'visit_label' => 'Roadshow stop', 'outlet_type' => 'event_venue', 'capture_geo' => 'first_visit',
            ],
        ],
        'general_trade' => [
            'name' => 'General Trade',
            'description' => 'Route-based selling and checks. Outlets come from the office or are added by agents in the field. Form, sales, availability and photos.',
            'settings' => [
                'outlet_source' => 'mixed', 'journey_plan' => false,
                'tasks' => ['form' => true, 'sales' => true, 'osa' => true, 'merchandising' => false, 'photos' => true],
                'min_photos' => 1, 'visit_label' => 'Visit', 'outlet_type' => 'retail_store', 'capture_geo' => 'first_visit',
            ],
        ],
        'sales_rep' => [
            'name' => 'Sales rep / Foot soldier',
            'description' => 'Agents are given stock and sell it. Sales are recorded at every stop and accounted for against the stock issued to them.',
            'settings' => [
                'outlet_source' => 'mixed', 'journey_plan' => false,
                'tasks' => ['form' => true, 'sales' => true, 'osa' => false, 'merchandising' => false, 'photos' => true],
                'min_photos' => 1, 'visit_label' => 'Stop', 'outlet_type' => 'retail_store', 'capture_geo' => 'first_visit',
            ],
        ],
        'custom' => [
            'name' => 'Custom made',
            'description' => 'Start from everything switched on and choose exactly what this project needs.',
            'settings' => self::DEFAULTS,
        ],
    ];

    /** Fills gaps with defaults and drops anything unknown, so stored settings are always complete and safe. */
    public static function normalize(?array $in): array
    {
        $in ??= [];
        $out = self::DEFAULTS;
        if (in_array($in['outlet_source'] ?? null, self::SOURCES, true)) {
            $out['outlet_source'] = $in['outlet_source'];
        }
        if (array_key_exists('journey_plan', $in)) {
            $out['journey_plan'] = filter_var($in['journey_plan'], FILTER_VALIDATE_BOOL);
        }
        foreach (self::TASKS as $t) {
            if (isset($in['tasks']) && is_array($in['tasks']) && array_key_exists($t, $in['tasks'])) {
                $out['tasks'][$t] = filter_var($in['tasks'][$t], FILTER_VALIDATE_BOOL);
            }
        }
        if (array_key_exists('visit_photos', $in)) {
            $out['visit_photos'] = filter_var($in['visit_photos'], FILTER_VALIDATE_BOOL);
        }
        foreach (['review' => self::REVIEW, 'capture_geo' => self::CAPTURE_GEO] as $k => $allowed) {
            if (in_array($in[$k] ?? null, $allowed, true)) {
                $out[$k] = $in[$k];
            }
        }
        if (isset($in['min_photos'])) {
            $out['min_photos'] = max(0, min(50, (int) $in['min_photos']));
        }
        foreach (['osa_pct' => 100, 'sos_pct' => 100, 'coverage_pct' => 100, 'visits_per_outlet' => 100] as $k => $max) {
            $v = $in['targets'][$k] ?? null;
            $out['targets'][$k] = is_numeric($v) && (float) $v > 0 ? round(min($max, (float) $v), 1) : null;
        }
        foreach (['visit_label' => 40, 'outlet_type' => 40] as $k => $max) {
            if (isset($in[$k]) && is_string($in[$k]) && trim($in[$k]) !== '') {
                $out[$k] = mb_substr(trim($in[$k]), 0, $max);
            }
        }

        return $out;
    }

    public static function for(Campaign $campaign): array
    {
        return self::normalize($campaign->settings);
    }

    /** The project's goals (osa_pct, sos_pct, coverage_pct, visits_per_outlet); each is null when none is set. */
    public static function targetsFor(?string $campaignId): array
    {
        $empty = self::DEFAULTS['targets'];
        if (!$campaignId || !preg_match('/^[0-9a-f-]{36}$/i', $campaignId)) {
            return $empty;
        }
        $raw = DB::table('campaigns')->where('id', $campaignId)->value('settings');

        return self::normalize(is_string($raw) ? json_decode($raw, true) : (array) $raw)['targets'];
    }

    public static function salesEnabled(Campaign $campaign): bool
    {
        return (bool) (self::for($campaign)['tasks']['sales'] ?? false);
    }

    public static function employmentType(User $agent): string
    {
        return DB::table('agent_profiles')->where('user_id', $agent->getKey())->value('employment_type') ?? 'permanent';
    }

    /** Null when the agent may add an outlet to this campaign, otherwise the reason they may not. */
    public static function outletAddDenial(Campaign $campaign, User $agent): ?string
    {
        $allowed = (bool) DB::table('agent_profiles')->where('user_id', $agent->getKey())->value('can_add_outlets');

        return $allowed ? null : 'Adding outlets is not switched on for you. Ask your supervisor to add the outlet to your plan, or to switch this on for you.';
    }
}
