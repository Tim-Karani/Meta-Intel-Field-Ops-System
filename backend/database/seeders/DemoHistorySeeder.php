<?php

namespace Database\Seeders;

use App\Domain\Assignments\AssignmentService;
use App\Domain\Audit\AuditService;
use App\Domain\Evidence\EvidenceStorage;
use App\Domain\Visits\VisitDataService;
use App\Domain\Visits\VisitService;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Location;
use App\Models\Photo;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY. Adds ~2 weeks of backdated field activity on top of DemoSeeder so that the dashboard,
 * charts, map, review queues, evidence gallery, attendance and issues look like a live system.
 *
 * Everything goes through the real domain services (exactly what the mobile app will trigger); only the clock is
 * moved, using Carbon::setTestNow(). Run:  php artisan db:seed --class=DemoHistorySeeder --force
 */
class DemoHistorySeeder extends Seeder
{
    private int $made = 0;
    private int $skipped = 0;

    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('DemoHistorySeeder only runs in local/testing environments.');
        }
        if (!Client::query()->where('code', 'DEMO-KBL')->exists()) {
            $this->call(DemoSeeder::class);
        }
        if (DB::table('audit_logs')->where('action', 'demo.history_seeded')->exists()) {
            $this->command?->info('Demo history already present; nothing to do.');
            return;
        }

        mt_srand(2026);   // reproducible demo data

        $campaign = Campaign::query()->where('code', 'DEMO-ZURI-Q4')->firstOrFail();
        $activity = Activity::query()->where('campaign_id', $campaign->id)->firstOrFail();
        $products = $campaign->products()->get()->values();
        $locations = Location::query()->where('code', 'like', 'DEMO-L%')->orderBy('code')->get()->values();
        $agents = User::query()->where('email', 'like', 'demo.agent%@example.com')->orderBy('email')->get()->values();
        $supervisor = User::query()->where('email', 'demo.supervisor@example.com')->firstOrFail();
        $manager = User::query()->where('email', 'demo.campaign@example.com')->firstOrFail();
        $form = $activity->form()->first();
        $questions = $form->questions->keyBy('label');

        $assignments = app(AssignmentService::class);
        $visits = app(VisitService::class);
        $data = app(VisitDataService::class);
        $storage = app(EvidenceStorage::class);

        $realNow = Carbon::now();
        try {
            for ($offset = 14; $offset >= 1; $offset--) {
                $day = $realNow->copy()->subDays($offset)->startOfDay();
                if ($day->isSunday()) {
                    continue;
                }

                $submittedToday = [];
                foreach ($agents as $ai => $agent) {
                    // Attendance for the day (late roughly one day in six).
                    $late = mt_rand(1, 6) === 1;
                    $in = $day->copy()->setTime(8, mt_rand(40, 58))->addMinutes($late ? 30 : 0);
                    Attendance::query()->create([
                        'user_id' => $agent->id, 'attendance_date' => $day->toDateString(), 'clock_in_at' => $in,
                        'clock_in_lat' => -1.2864 + mt_rand(-30, 30) / 10000, 'clock_in_lng' => 36.8172 + mt_rand(-30, 30) / 10000, 'clock_in_accuracy_m' => mt_rand(5, 25),
                        'clock_out_at' => $day->copy()->setTime(17, mt_rand(0, 45)), 'status' => $late ? 'late' : 'present',
                    ]);

                    foreach ($locations->slice($ai * 2, 2)->values() as $li => $loc) {
                        Carbon::setTestNow($day->copy()->setTime(7, 30));
                        try {
                            $assignment = $assignments->create([
                                'campaign_id' => $campaign->id, 'activity_id' => $activity->id, 'agent_id' => $agent->id, 'location_id' => $loc->id,
                                'scheduled_date' => $day->toDateString(), 'start_time' => sprintf('%02d:00', 9 + $li * 2), 'end_time' => sprintf('%02d:00', 10 + $li * 2),
                                'instructions' => 'Check facings and shelf cleanliness; record OSA for all campaign products.',
                            ], $manager);
                        } catch (\Throwable $e) {
                            $this->skipped++;
                            continue;
                        }

                        // About one in ten visits is simply missed; the overdue job will flag it afterwards.
                        if (mt_rand(1, 10) === 1) {
                            continue;
                        }
                        try {
                            $visit = $this->workVisit($visits, $data, $storage, $assignment, $agent, $loc, $form, $questions, $products, $day, 9 + $li * 2);
                            $submittedToday[] = $visit->id;
                            $this->made++;
                        } catch (\Throwable $e) {
                            $this->skipped++;
                            $this->command?->warn('Skipped a visit: '.$e->getMessage());
                        }
                    }
                }

                // Supervisor reviews that evening; the most recent day is left in the queue.
                Carbon::setTestNow($day->copy()->setTime(17, 30));
                foreach ($submittedToday as $visitId) {
                    if ($offset <= 1) {
                        break;
                    }
                    $roll = mt_rand(1, 100);
                    try {
                        $visit = Visit::query()->findOrFail($visitId);
                        if ($roll <= 70) {
                            $visits->startReview($visit, $supervisor);
                            $visits->approve($visit->refresh(), $supervisor, 'Good execution.');
                            Photo::query()->where('visit_id', $visitId)->update(['review_status' => 'accepted', 'reviewed_by' => $supervisor->id, 'reviewed_at' => now()]);
                        } elseif ($roll <= 82) {
                            $visits->startReview($visit, $supervisor);
                            $visits->reject($visit->refresh(), $supervisor, 'Shelf photos unclear and facings look wrong. Please revisit.');
                            Photo::query()->where('visit_id', $visitId)->update(['review_status' => 'rejected', 'review_note' => 'Unclear', 'reviewed_by' => $supervisor->id, 'reviewed_at' => now()]);
                        } elseif ($roll <= 88) {
                            $visits->startReview($visit, $supervisor);   // left under review
                        }
                    } catch (\Throwable $e) {
                        $this->skipped++;
                    }
                }
            }

            $this->issues($supervisor, $agents, $manager, $campaign, $locations, $realNow);
        } finally {
            Carbon::setTestNow();
        }

        // Real clock again: anything still pending in the past becomes overdue, as the nightly job would do.
        $overdue = app(AssignmentService::class)->markOverdue();

        AuditService::record('demo.history_seeded', 'system', null, null, ['visits' => $this->made, 'skipped' => $this->skipped, 'overdue' => $overdue]);
        $this->command?->info("Demo history: {$this->made} visits created, {$this->skipped} skipped, {$overdue} assignments flagged overdue.");
    }

    private function workVisit(VisitService $visits, VisitDataService $data, EvidenceStorage $storage, Assignment $assignment, User $agent, Location $loc, $form, $q, $products, Carbon $day, int $startHour): Visit
    {
        // Mostly inside the 100 m geofence; occasionally just outside (a warning) or with a poor accuracy reading.
        $roll = mt_rand(1, 100);
        $spread = $roll <= 82 ? 0.0004 : ($roll <= 94 ? 0.0014 : 0.0003);
        $accuracy = $roll > 94 ? mt_rand(110, 160) : mt_rand(5, 30);
        $lat = $loc->latitude + mt_rand(-1000, 1000) / 1000 * $spread;
        $lng = $loc->longitude + mt_rand(-1000, 1000) / 1000 * $spread;
        $gps = fn () => ['latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => $accuracy, 'device_ts' => now()->toIso8601String(), 'provider' => 'gps', 'device_id' => 'demo-'.$agent->id];

        Carbon::setTestNow($day->copy()->setTime($startHour, mt_rand(0, 12)));
        $visit = $visits->start($agent, $assignment, (string) Str::uuid(), 'demo-'.$agent->id);
        $visit = $visits->checkIn($visit, $agent, $gps());

        Carbon::setTestNow($day->copy()->setTime($startHour, mt_rand(15, 30)));
        $stocked = mt_rand(1, 100) <= 88;
        $answers = [$q['Is the brand stocked?']->id => $stocked, $q['Shelf cleanliness (1-5)']->id => mt_rand(2, 5)];
        if ($stocked) {
            $answers[$q['Number of facings']->id] = mt_rand(2, 10);
            $answers[$q['Display type']->id] = ['shelf', 'floor', 'chiller'][mt_rand(0, 2)];
        } else {
            $answers[$q['Why not?']->id] = 'Out of stock; next delivery expected later in the week.';
        }
        $visits->saveResponses($visit, $answers);

        foreach ($products as $p) {
            $available = $stocked && mt_rand(1, 100) <= 85;
            $data->saveOsa($visit, [
                'product_id' => $p->id, 'available' => $available, 'facings' => $available ? mt_rand(1, 6) : 0,
                'price_on_shelf' => $p->unit_price + (mt_rand(1, 10) === 1 ? 10 : 0), 'display_compliant' => $available && mt_rand(1, 100) <= 80,
            ]);
        }
        $data->addMerchandising($visit, [
            'posm_present' => mt_rand(1, 100) <= 70, 'posm_type' => 'shelf_strip', 'planogram_compliant' => mt_rand(1, 100) <= 75,
            'price_compliant' => mt_rand(1, 100) <= 85, 'competitor_present' => mt_rand(1, 100) <= 60,
        ]);
        if (mt_rand(1, 100) <= 40) {
            $items = collect($products)->shuffle()->take(mt_rand(1, 3))->map(fn ($p) => ['product_id' => $p->id, 'quantity' => mt_rand(2, 24)])->all();
            $data->addSale($visit, ['payment_method' => mt_rand(1, 100) <= 70 ? 'mpesa' : 'cash', 'items' => $items]);
        }
        for ($i = 0, $n = mt_rand(1, 2); $i < $n; $i++) {
            $this->photo($storage, $visit, $agent, $loc, $loc->name.' · shelf '.($i + 1));
        }

        Carbon::setTestNow($day->copy()->setTime($startHour, mt_rand(40, 55)));
        $visit = $visits->checkOut($visit, $agent, $gps());

        Carbon::setTestNow($day->copy()->setTime($startHour, 58));
        return $visits->submit($visit, $agent);
    }

    /** A unique placeholder JPEG (so the evidence gallery has something to show and SHA-256 reuse detection stays quiet). */
    private function photo(EvidenceStorage $storage, Visit $visit, User $agent, Location $loc, string $label): void
    {
        $img = imagecreatetruecolor(640, 480);
        $hue = mt_rand(0, 255);
        for ($y = 0; $y < 480; $y += 4) {
            $c = imagecolorallocate($img, ($hue + $y / 3) % 200 + 30, (int) (90 + $y / 6), 140 + mt_rand(0, 60));
            imagefilledrectangle($img, 0, $y, 640, $y + 3, $c);
        }
        for ($i = 0; $i < 6; $i++) {   // "products" on a shelf
            imagefilledrectangle($img, 40 + $i * 95, 250, 100 + $i * 95, 400, imagecolorallocate($img, mt_rand(150, 255), mt_rand(20, 120), mt_rand(20, 120)));
        }
        imagefilledrectangle($img, 0, 400, 640, 410, imagecolorallocate($img, 230, 230, 230));
        imagestring($img, 5, 16, 16, 'DEMO PHOTO', imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 3, 16, 40, substr(preg_replace('/[^\x20-\x7E]/', '-', $label), 0, 70), imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 2, 16, 450, Str::random(24), imagecolorallocate($img, 255, 255, 255));   // uniqueness

        $tmp = tempnam(sys_get_temp_dir(), 'demo');
        imagejpeg($img, $tmp, 85);
        imagedestroy($img);
        try {
            $storage->storePhoto(new UploadedFile($tmp, 'shelf.jpg', 'image/jpeg', null, true), $visit, $agent, ['latitude' => $loc->latitude, 'longitude' => $loc->longitude]);
        } finally {
            @unlink($tmp);
        }
    }

    private function issues(User $supervisor, $agents, User $manager, Campaign $campaign, $locations, Carbon $realNow): void
    {
        $rows = [
            ['Out of stock: Zuri Cola 2L', 'No stock on shelf or in the backroom. Store manager says next delivery is Thursday.', 'stock', 'high', 'open', 12, 0],
            ['Competitor display blocking our shelf', 'A competitor floor stack is covering the main shelf bay.', 'competitor', 'medium', 'assigned', 11, 1],
            ['Wrong shelf price on Zuri Orange', 'Shelf label shows KES 70 instead of KES 60.', 'pricing', 'medium', 'in_progress', 9, 2],
            ['Chiller not working', 'Store chiller has been off since the weekend; product is warm.', 'equipment', 'critical', 'escalated', 8, 3],
            ['Damaged POSM material', 'Shelf strips are torn and need replacing.', 'posm', 'low', 'resolved', 10, 4],
            ['Store closed during scheduled visit', 'Premises closed for stock-taking; visit could not be done.', 'access', 'medium', 'resolved', 7, 5],
            ['Promoter absent', 'The in-store promoter did not report for the shift.', 'staffing', 'high', 'closed', 13, 6],
            ['Expired product found on shelf', 'Three units past best-before date removed with manager approval.', 'quality', 'high', 'open', 3, 7],
        ];
        foreach ($rows as $i => [$title, $desc, $cat, $priority, $status, $daysAgo, $locIdx]) {
            $when = $realNow->copy()->subDays($daysAgo)->setTime(11, mt_rand(0, 59));
            Carbon::setTestNow($when);
            $reporter = $agents[$i % count($agents)];
            $assignee = in_array($status, ['open'], true) ? null : $supervisor->id;
            $issue = new Issue;
            $issue->forceFill([
                'campaign_id' => $campaign->id, 'location_id' => $locations[$locIdx % count($locations)]->id, 'title' => $title, 'description' => $desc,
                'category' => $cat, 'priority' => $priority, 'status' => $status, 'reported_by' => $reporter->id, 'assigned_to' => $assignee,
                'escalated_at' => $status === 'escalated' ? $when->copy()->addHours(3) : null,
                'resolution' => in_array($status, ['resolved', 'closed'], true) ? 'Handled with the store manager and confirmed on the next visit.' : null,
                'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $when->copy()->addDay() : null,
                'closed_at' => $status === 'closed' ? $when->copy()->addDays(2) : null,
            ])->save();
            IssueComment::query()->create(['issue_id' => $issue->id, 'user_id' => $reporter->id, 'type' => 'status', 'body' => 'Issue reported.']);
            if ($assignee) {
                IssueComment::query()->create(['issue_id' => $issue->id, 'user_id' => $supervisor->id, 'type' => 'comment', 'body' => 'Looking into this with the store and the campaign manager.']);
            }
        }
    }
}
