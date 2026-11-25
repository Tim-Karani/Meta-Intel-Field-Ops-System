<?php

namespace App\Console\Commands;

use App\Domain\Assignments\AssignmentService;
use App\Domain\Assignments\JourneyPlanService;
use App\Domain\Audit\AuditService;
use App\Domain\Evidence\EvidenceStorage;
use App\Domain\Forms\FormStructureService;
use App\Domain\Projects\ProjectSettings;
use App\Domain\Visits\VisitDataService;
use App\Domain\Visits\VisitService;
use App\Enums\CampaignStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\VisitStatus;
use App\Models\Activity;
use App\Models\AgentProfile;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignKpi;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Form;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\JourneyPlan;
use App\Models\Location;
use App\Models\OutletStatus;
use App\Models\Photo;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Team;
use App\Models\Territory;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY. Builds a believable running project: Highlands Drinks Ltd, Nairobi Modern Trade.
 *
 *   php artisan demo:highlands [--days=21] [--admin=you@example.com]
 *
 * Everything is created through the real services (the same ones the phone app and the web use) with the clock moved back,
 * so reports, queues, maps, photos and the schedule look like a project that has been running for a few weeks.
 * Product names and pack sizes come from highlandske.com. Prices, outlets' positions, people and results are INVENTED demo data.
 */
class DemoHighlands extends Command
{
    public const PASSWORD = 'Highlands@Demo2026!';

    protected $signature = 'demo:highlands {--days=21 : how many days of history to create (7 to 45)} {--admin= : e-mail of the super administrator (project creator)}';
    protected $description = 'Create the Highlands Drinks Nairobi Modern Trade demo project with weeks of activity (local environments only).';

    private int $made = 0;
    private int $skipped = 0;

    /** brand => [category, code, variants, packs [label => [price, units per case]]] */
    private function range(): array
    {
        return [
            ['Highlands Water', 'Water', 'WTR', ['Mineral Water'], ['500ml' => [30, 24], '1L' => [50, 12], '1.5L' => [70, 12], '5L' => [180, 4], '18.9L' => [350, 1], '18.9L Returnable' => [250, 1], '20L' => [380, 1]]],
            ['Highlands Cordials', 'Cordials', 'CORD', ['Cocopine', 'Orange', 'Lemon', 'Pineapple', 'Mango', 'Tropical'], ['1L' => [280, 12], '1.5L' => [380, 12], '2L' => [480, 6], '3L' => [680, 4], '5L' => [1050, 2]]],
            ['CLUB', 'Soda', 'CLUB', ['Lemonade', 'Pinacolada', 'Lemon Lime', 'Orange', 'Mango', 'Passion', 'Pineapple', 'Blackcurrant', 'Tangawizi', 'Cola', 'Apple'], ['350ml' => [50, 24], '500ml' => [70, 24], '1.25L' => [130, 12], '2L' => [190, 6]]],
            ['Bazuu Energy', 'Energy drinks', 'BAZ', ['Energy Drink'], ['265ml' => [60, 24], '500ml' => [100, 24]]],
            ['Royal Club', 'Mixers', 'ROY', ['Tonic Water', 'Lemonade'], ['500ml' => [110, 24]]],
        ];
    }

    /** The must-stock list the agents are audited against (brand, variant, pack). */
    private const MSL = [
        ['Highlands Water', 'Mineral Water', '500ml'], ['Highlands Water', 'Mineral Water', '1L'], ['Highlands Water', 'Mineral Water', '1.5L'], ['Highlands Water', 'Mineral Water', '5L'],
        ['CLUB', 'Cola', '500ml'], ['CLUB', 'Orange', '500ml'], ['CLUB', 'Lemonade', '500ml'], ['CLUB', 'Lemon Lime', '500ml'], ['CLUB', 'Pineapple', '500ml'], ['CLUB', 'Passion', '500ml'],
        ['CLUB', 'Cola', '2L'], ['CLUB', 'Orange', '2L'], ['CLUB', 'Cola', '1.25L'],
        ['Highlands Cordials', 'Orange', '1L'], ['Highlands Cordials', 'Lemon', '1L'], ['Highlands Cordials', 'Mango', '1L'], ['Highlands Cordials', 'Tropical', '1L'], ['Highlands Cordials', 'Cocopine', '2L'],
        ['Bazuu Energy', 'Energy Drink', '500ml'], ['Bazuu Energy', 'Energy Drink', '265ml'], ['Royal Club', 'Tonic Water', '500ml'], ['Royal Club', 'Lemonade', '500ml'],
    ];

    /** chain => [channel code, baseline execution quality 0-1, branches [name, locality, lat, lng]] */
    private function outlets(): array
    {
        return [
            'Naivas' => [0.88, [['Kilimani', 'Kilimani', -1.2925, 36.7878], ['Westlands', 'Westlands', -1.2640, 36.8040], ["Lang'ata Road", "Lang'ata", -1.3380, 36.7790], ['Buruburu', 'Buruburu', -1.2850, 36.8760], ['Kasarani', 'Kasarani', -1.2230, 36.8920], ['Pipeline', 'Embakasi', -1.3200, 36.9000], ['Ngong Road', 'Ngong Road', -1.3000, 36.7600], ['Karen', 'Karen', -1.3190, 36.7110], ['Donholm', 'Donholm', -1.2960, 36.8880], ['Eastleigh', 'Eastleigh', -1.2745, 36.8470], ['Utawala', 'Utawala', -1.2900, 36.9600], ['CBD Moi Avenue', 'Nairobi CBD', -1.2840, 36.8230]]],
            'Quickmart' => [0.85, [['Kileleshwa', 'Kileleshwa', -1.2790, 36.7800], ['Lavington', 'Lavington', -1.2790, 36.7700], ['Ngong Road', 'Ngong Road', -1.3010, 36.7700], ['Parklands', 'Parklands', -1.2630, 36.8180], ['South C', 'South C', -1.3190, 36.8300], ['Komarock', 'Komarock', -1.2800, 36.9100], ['Roysambu', 'Roysambu', -1.2180, 36.8880], ['Kahawa West', 'Kahawa West', -1.1850, 36.9200]]],
            'Carrefour' => [0.92, [['Two Rivers', 'Runda', -1.2110, 36.8040], ['Garden City', 'Thika Road', -1.2320, 36.8790], ['Westgate', 'Westlands', -1.2570, 36.8030], ['The Hub Karen', 'Karen', -1.3380, 36.7110], ['Junction Mall', 'Ngong Road', -1.2960, 36.7620], ['Thika Road Mall', 'Thika Road', -1.2190, 36.8860]]],
            'Chandarana Foodplus' => [0.90, [['Village Market', 'Gigiri', -1.2290, 36.8040], ['Parklands', 'Parklands', -1.2610, 36.8190], ['Valley Arcade', 'Lavington', -1.2760, 36.7700], ['Muthaiga', 'Muthaiga', -1.2480, 36.8330], ['Capital Centre', 'Mombasa Road', -1.3140, 36.8440]]],
            'Cleanshelf' => [0.76, [['Ngara', 'Ngara', -1.2760, 36.8240], ['Madaraka', 'Madaraka', -1.3070, 36.8070], ['Kilimani', 'Kilimani', -1.2890, 36.7830]]],
        ];
    }

    private const AGENTS = [
        ['Amina Wanjiru', 0], ['Brian Otieno', 0], ['Cynthia Mutua', 0], ['David Kiprop', 0],
        ['Esther Njoki', 1], ['Felix Odhiambo', 1], ['Grace Wairimu', 1], ['Hassan Abdi', 1],
    ];

    public function handle(): int
    {
        if (!app()->environment('local', 'testing')) {
            $this->error('demo:highlands only runs in local or testing environments.');
            return self::FAILURE;
        }
        if (Client::query()->where('code', 'HLD')->exists()) {
            $this->warn('The Highlands demo project already exists. Run demo:wipe first to start again.');
            return self::FAILURE;
        }
        $days = max(7, min(45, (int) $this->option('days')));
        $adminEmail = strtolower(trim((string) $this->option('admin')));
        $admin = $adminEmail !== ''
            ? User::query()->whereRaw('lower(email) = ?', [$adminEmail])->first()
            : User::query()->whereHas('roles', fn ($q) => $q->where('slug', RoleEnum::SystemAdmin->value))->first();
        if (!$admin) {
            $this->error('No System Administrator found. Create one first (ADMIN_EMAIL in backend/.env, then php artisan db:seed).');
            return self::FAILURE;
        }
        mt_srand((int) date('Ymd'));   // varies by day, so a refreshed demo does not look copied

        $this->info('Setting up the client, products and outlets...');
        $client = Client::query()->create(['name' => 'Highlands Drinks Ltd', 'code' => 'HLD', 'company_name' => 'Highlands Drinks Limited', 'industry' => 'Beverages', 'email' => 'brands@highlands-demo.example.com', 'phone' => '+254700000100', 'address' => 'Nairobi, Kenya', 'created_by' => $admin->id]);
        $client->hasMany(ClientContact::class)->create(['name' => 'Wambui Karanja', 'email' => 'wambui.karanja@highlands-demo.example.com', 'phone' => '+254700000101', 'is_primary' => true]);

        $products = $this->catalogue($client);
        $msl = $products->filter(fn ($p) => $p->is_msl)->values();
        $outlets = $this->createOutlets($admin);
        $people = $this->people($client, $admin);

        $this->info('Creating the project...');
        [$campaign, $activity, $form] = $this->project($client, $admin, $products, $outlets, $people);
        $this->journeyPlans($campaign, $activity, $outlets, $people, $admin, $days);

        $this->info("Creating {$days} days of field activity (this takes a few minutes)...");
        $this->history($campaign, $activity, $form, $msl, $people, $outlets, $days);

        AuditService::record('demo.highlands_seeded', 'system', null, null, ['visits' => $this->made, 'skipped' => $this->skipped]);
        $this->newLine();
        $this->info("Done: {$this->made} visits created ({$this->skipped} skipped).");
        $this->line('Demo people all share the password: '.self::PASSWORD);
        $this->table(['Role', 'Name', 'E-mail'], collect($people['list'])->map(fn ($u) => [$u['role'], $u['user']->name, $u['user']->email])->all());

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ catalogue

    private function catalogue(Client $client)
    {
        $msl = collect(self::MSL)->map(fn ($m) => implode('|', $m))->flip();
        $out = collect();
        foreach ($this->range() as [$brandName, $catName, $code, $variants, $packs]) {
            $brand = Brand::query()->create(['client_id' => $client->id, 'name' => $brandName]);
            $cat = ProductCategory::query()->firstOrCreate(['name' => $catName]);
            foreach ($variants as $variant) {
                foreach ($packs as $pack => [$price, $perCase]) {
                    $label = $variant === 'Mineral Water' || $variant === 'Energy Drink' ? $variant : $variant;
                    $name = match ($brandName) {
                        'Highlands Water' => "Highlands Mineral Water {$pack}",
                        'Bazuu Energy' => "Bazuu Energy Drink {$pack}",
                        'Highlands Cordials' => "Highlands Cordial {$label} {$pack}",
                        'Royal Club' => "Royal Club {$label} {$pack}",
                        default => "CLUB {$label} {$pack}",
                    };
                    $out->push(Product::query()->create([
                        'client_id' => $client->id, 'brand_id' => $brand->id, 'category_id' => $cat->id, 'name' => $name,
                        'sku' => 'HL-'.$code.'-'.strtoupper(Str::slug($variant)).'-'.strtoupper(str_replace('.', '', Str::slug($pack))),
                        'unit_price' => $price, 'pack_size' => $pack, 'units_per_case' => $perCase,
                        'is_msl' => $msl->has("{$brandName}|{$variant}|{$pack}"), 'sos_target_pct' => $catName === 'Soda' ? 30 : 20, 'status' => 'active',
                    ]));
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ outlets

    private function createOutlets(User $admin)
    {
        $territories = ['west' => Territory::query()->create(['name' => 'Nairobi West & Central', 'code' => 'HLD-NBO-W', 'region' => 'Nairobi']),
            'east' => Territory::query()->create(['name' => 'Nairobi East & North', 'code' => 'HLD-NBO-E', 'region' => 'Nairobi'])];
        $active = OutletStatus::query()->where('is_default', true)->value('id');
        $result = collect();
        $n = 0;
        foreach ($this->outlets() as $chain => [$quality, $branches]) {
            $channel = Channel::query()->create(['name' => $chain, 'channel_group' => 'Modern trade', 'code' => strtoupper(substr(preg_replace('/\W/', '', $chain), 0, 4))]);
            foreach ($branches as [$branch, $locality, $lat, $lng]) {
                $n++;
                $loc = Location::query()->create([
                    'channel_id' => $channel->id, 'territory_id' => $territories[$lng > 36.82 || $lat > -1.26 ? 'east' : 'west']->id, 'outlet_status_id' => $active,
                    'name' => "{$chain} {$branch}", 'code' => sprintf('HLD-%03d', $n), 'type' => 'supermarket', 'setting' => 'urban',
                    'region' => 'Nairobi', 'county' => 'Nairobi', 'town' => 'Nairobi', 'locality' => $locality, 'address' => "{$chain} {$branch}, {$locality}, Nairobi",
                    'latitude' => $lat, 'longitude' => $lng, 'geofence_radius_m' => 150, 'needs_review' => false,
                    'contact_name' => 'Store Manager', 'contact_phone' => '+2547'.mt_rand(10000000, 99999999), 'opening_hours' => '08:00-21:00', 'created_by' => $admin->id,
                ]);
                $loc->setAttribute('demo_quality', min(0.97, max(0.55, $quality + mt_rand(-10, 8) / 100)));
                $result->push($loc);
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------ people

    private function people(Client $client, User $admin): array
    {
        $list = [];
        $make = function (string $role, RoleEnum $enum, string $name, ?string $clientId = null) use (&$list) {
            $email = Str::of($name)->lower()->replace(' ', '.')->append('@highlands-demo.example.com')->toString();
            $u = User::query()->create(['name' => $name, 'email' => $email, 'password' => self::PASSWORD, 'status' => 'active', 'phone' => '+2547'.mt_rand(10000000, 99999999), 'client_id' => $clientId, 'must_change_password' => false]);
            $u->roles()->attach(Role::query()->where('slug', $enum->value)->value('id'));
            $list[] = ['role' => $enum->label(), 'user' => $u];

            return $u;
        };

        $p = [];
        $p['ops'] = $make('ops', RoleEnum::OperationsManager, 'Lucy Mwende');
        $p['pm'] = $make('pm', RoleEnum::CampaignManager, 'Grace Achieng');
        $p['sup'] = $make('sup', RoleEnum::Supervisor, 'Peter Kamau');
        $p['tl'] = [$make('tl', RoleEnum::TeamLeader, 'Faith Njeri'), $make('tl', RoleEnum::TeamLeader, 'Kevin Mutiso')];
        $p['analyst'] = $make('an', RoleEnum::Analyst, 'Ruth Nyambura');
        $p['client'] = $make('cl', RoleEnum::ClientUser, 'Wambui Karanja', $client->id);
        foreach ($p['tl'] as $i => $tl) {
            AgentProfile::query()->create(['user_id' => $tl->id, 'agent_type' => 'team_leader', 'employment_type' => 'permanent', 'agent_code' => 'HLD-TL'.($i + 1), 'pay_type' => 'daily', 'pay_rate' => 2800, 'supervisor_id' => $p['sup']->id, 'region' => 'Nairobi']);
        }
        $p['agents'] = [];
        foreach (self::AGENTS as $i => [$name, $tlIdx]) {
            $u = $make('ag', RoleEnum::FieldAgent, $name);
            AgentProfile::query()->create(['user_id' => $u->id, 'agent_type' => 'merchandiser', 'employment_type' => 'permanent', 'agent_code' => 'HLD-A'.($i + 1), 'pay_type' => 'daily', 'pay_rate' => 1800, 'supervisor_id' => $p['sup']->id, 'team_leader_id' => $p['tl'][$tlIdx]->id, 'region' => 'Nairobi']);
            $p['agents'][] = $u;
        }
        $p['list'] = $list;

        return $p;
    }

    // ------------------------------------------------------------------ project

    private function project(Client $client, User $admin, $products, $outlets, array $p): array
    {
        $structure = app(FormStructureService::class);
        $form = Form::query()->create(['group_id' => (string) Str::uuid(), 'version' => 1, 'name' => 'Modern Trade shelf audit', 'status' => 'draft', 'created_by' => $admin->id]);
        $structure->replace($form, [[
            'title' => 'Shelf audit', 'questions' => [
                ['ref' => 'stocked', 'type' => 'yes_no', 'label' => 'Are Highlands products on the shelf?', 'required' => true],
                ['ref' => 'why', 'type' => 'long_text', 'label' => 'Why not?', 'conditions' => [['depends_on' => 'stocked', 'operator' => 'equals', 'value' => false]]],
                ['ref' => 'facings', 'type' => 'integer', 'label' => 'Total Highlands facings', 'validation' => ['min' => 0, 'max' => 300]],
                ['ref' => 'chiller', 'type' => 'yes_no', 'label' => 'Is there a chiller with Highlands products?'],
                ['ref' => 'price', 'type' => 'yes_no', 'label' => 'Shelf prices match the price list?'],
                ['ref' => 'competitor', 'type' => 'single_select', 'label' => 'Competitor activity seen', 'options' => [['label' => 'None', 'value' => 'none'], ['label' => 'Competitor promotion', 'value' => 'promo'], ['label' => 'Competitor display', 'value' => 'display'], ['label' => 'Competitor price cut', 'value' => 'price']]],
                ['ref' => 'cleanliness', 'type' => 'rating', 'label' => 'Shelf cleanliness (1-5)', 'validation' => ['min' => 1, 'max' => 5]],
            ],
        ]]);
        $form = $structure->publish($form);

        $start = today()->subDays((int) $this->option('days') + 3);
        $settings = ProjectSettings::normalize(array_replace_recursive(ProjectSettings::PRESETS['merchandising']['settings'], [
            'tasks' => ['orders' => true, 'sales' => false], 'min_photos' => 1,
            'targets' => ['osa_pct' => 90, 'sos_pct' => 30, 'coverage_pct' => 95, 'visits_per_outlet' => 8],
        ]));
        $campaign = Campaign::query()->create([
            'client_id' => $client->id, 'code' => 'HLD-NBO-MT-Q4', 'name' => 'Highlands Nairobi Modern Trade Visibility & Availability Q4', 'campaign_type' => 'merchandising',
            'description' => 'Availability, shelf share, pricing and expiry checks for the Highlands range across Nairobi modern trade supermarkets, with order capture for distributor follow-up.',
            'objectives' => 'Reach 90% on-shelf availability of the must-stock list, 30% share of shelf in soda, and clear expired stock within 48 hours.',
            'budget' => 2400000, 'start_date' => $start->toDateString(), 'end_date' => '2026-12-31', 'status' => CampaignStatus::Draft, 'created_by' => $admin->id,
            'template_id' => DB::table('project_templates')->where('slug', 'merchandising')->value('id'), 'settings' => $settings,
        ]);
        $activity = Activity::query()->create(['campaign_id' => $campaign->id, 'form_id' => $form->id, 'name' => 'Shelf audit & availability', 'activity_type' => 'store_audit', 'target' => 900, 'status' => 'active', 'created_by' => $admin->id]);
        $campaign->locations()->sync($outlets->mapWithKeys(fn ($l) => [$l->id => ['visit_frequency' => 'twice weekly']])->all());
        $campaign->products()->sync($products->pluck('id'));
        $campaign->forms()->sync([$form->id]);
        $campaign->users()->sync([$p['pm']->id, $p['analyst']->id]);
        foreach ([['Approved visits', 'visits_completed', 900, 'visits'], ['On-shelf availability', 'osa_rate', 90, '%'], ['Outlet coverage', 'coverage', 95, '%']] as [$name, $metric, $target, $unit]) {
            CampaignKpi::query()->create(['campaign_id' => $campaign->id, 'name' => $name, 'metric' => $metric, 'target' => $target, 'unit' => $unit]);
        }
        $campaign->update(['status' => CampaignStatus::Active]);

        $team = Team::query()->create(['name' => 'Nairobi Modern Trade Team', 'region' => 'Nairobi', 'supervisor_id' => $p['sup']->id]);
        $team->members()->sync(collect($p['agents'])->pluck('id'));
        $campaign->teams()->sync([$team->id]);

        return [$campaign, $activity, $form];
    }

    private function journeyPlans(Campaign $campaign, Activity $activity, $outlets, array $p, User $admin, int $days): void
    {
        $service = app(JourneyPlanService::class);
        $patterns = [[1, 4], [2, 5], [3, 6], [1, 3, 5], [2, 4, 6]];
        $groups = $outlets->values()->chunk((int) ceil($outlets->count() / count($p['agents'])));
        foreach ($p['agents'] as $i => $agent) {
            $mine = $groups->get($i);
            if (!$mine) {
                continue;
            }
            $plan = JourneyPlan::query()->create(['campaign_id' => $campaign->id, 'agent_id' => $agent->id, 'name' => 'Weekly route', 'status' => 'active', 'starts_on' => $campaign->start_date, 'created_by' => $admin->id]);
            $stops = $mine->values()->map(fn ($loc, $k) => ['location_id' => $loc->id, 'weekdays' => $patterns[$k % count($patterns)], 'activity_id' => $activity->id])->all();
            $service->saveStops($plan, $stops, $admin);   // also creates today onwards
            $service->generate($plan, $admin, $campaign->start_date->copy(), today()->subDay());   // the past, so there is history to work on
        }
    }

    // ------------------------------------------------------------------ history

    private function history(Campaign $campaign, Activity $activity, Form $form, $msl, array $p, $outlets, int $days): void
    {
        $visits = app(VisitService::class);
        $data = app(VisitDataService::class);
        $storage = app(EvidenceStorage::class);
        $questions = $form->questions->keyBy('label');
        $realNow = Carbon::now();
        $byLoc = $outlets->keyBy('id');
        $supervisor = $p['sup'];

        try {
            for ($offset = $days; $offset >= 0; $offset--) {
                $day = $realNow->copy()->subDays($offset)->startOfDay();
                if ($day->isSunday()) {
                    continue;
                }
                $isToday = $offset === 0;
                $submitted = [];

                foreach ($p['agents'] as $agent) {
                    $todays = Assignment::query()->where('campaign_id', $campaign->id)->where('agent_id', $agent->id)->whereDate('scheduled_date', $day->toDateString())->get()->values();
                    if ($todays->isEmpty()) {
                        continue;
                    }
                    if (!$isToday && mt_rand(1, 100) <= 2) {   // a day off or sick: nothing done, nothing clocked in
                        Attendance::query()->create(['user_id' => $agent->id, 'attendance_date' => $day->toDateString(), 'status' => 'absent', 'notes' => 'Off sick']);
                        continue;
                    }
                    if ($isToday && $realNow->hour < 9) {
                        continue;
                    }
                    $late = mt_rand(1, 7) === 1;
                    $in = $day->copy()->setTime(8, mt_rand(35, 58))->addMinutes($late ? 35 : 0);
                    Attendance::query()->create([
                        'user_id' => $agent->id, 'attendance_date' => $day->toDateString(), 'clock_in_at' => $in,
                        'clock_in_lat' => -1.2864 + mt_rand(-300, 300) / 10000, 'clock_in_lng' => 36.8172 + mt_rand(-300, 300) / 10000, 'clock_in_accuracy_m' => mt_rand(5, 25),
                        'clock_out_at' => $isToday ? null : $day->copy()->setTime(17, mt_rand(0, 40)), 'status' => $late ? 'late' : 'present',
                    ]);

                    foreach ($todays as $i => $assignment) {
                        $hour = 9 + $i * 2;
                        if ($isToday && ($hour >= $realNow->hour || $i > 1)) {
                            continue;   // later stops have not happened yet
                        }
                        if (mt_rand(1, 100) <= 2) {
                            continue;   // missed; the overdue job flags it afterwards
                        }
                        try {
                            $submitted[] = $this->workVisit($visits, $data, $storage, $assignment, $agent, $byLoc[$assignment->location_id], $questions, $msl, $day, $hour)->id;
                            $this->made++;
                        } catch (\Throwable $e) {
                            $this->skipped++;
                            $this->command?->warn('Skipped a visit: '.$e->getMessage());
                        }
                    }
                }

                // The supervisor works through the review queue that evening; the last two days are left waiting.
                Carbon::setTestNow($day->copy()->setTime(17, 30));
                foreach ($submitted as $visitId) {
                    if ($offset <= 1) {
                        break;
                    }
                    try {
                        $visit = Visit::query()->findOrFail($visitId);
                        if ($visit->status !== VisitStatus::Submitted) {
                            continue;   // already approved automatically
                        }
                        $roll = mt_rand(1, 100);
                        $visits->startReview($visit, $supervisor);
                        if ($roll <= 80) {
                            $visits->approve($visit->refresh(), $supervisor, 'Position checked and shelf evidence looks fine.');
                            Photo::query()->where('visit_id', $visitId)->update(['review_status' => 'accepted', 'reviewed_by' => $supervisor->id, 'reviewed_at' => now()]);
                        } elseif ($roll <= 92) {
                            $visits->reject($visit->refresh(), $supervisor, 'Shelf photo is unclear. Please retake at the next visit.');
                            Photo::query()->where('visit_id', $visitId)->update(['review_status' => 'rejected', 'review_note' => 'Unclear', 'reviewed_by' => $supervisor->id, 'reviewed_at' => now()]);
                        }
                    } catch (\Throwable $e) {
                        $this->skipped++;
                    }
                }
                $this->output->write('.');
            }
            $this->newLine();
            $this->issues($p, $campaign, $outlets, $realNow);
        } finally {
            Carbon::setTestNow();
        }

        $overdue = app(AssignmentService::class)->markOverdue();
        $future = app(JourneyPlanService::class)->generateAll();   // the schedule ahead
        $this->line("{$overdue} missed visit(s) flagged overdue; {$future} future visit(s) scheduled.");
    }

    private function workVisit(VisitService $visits, VisitDataService $data, EvidenceStorage $storage, Assignment $assignment, User $agent, Location $loc, $q, $msl, Carbon $day, int $hour): Visit
    {
        $quality = (float) $loc->getAttribute('demo_quality');
        $roll = mt_rand(1, 100);
        $spread = $roll <= 92 ? 0.0004 : 0.0014;           // mostly inside the geofence; a few drift outside (those go to review)
        $accuracy = mt_rand(5, 30);
        $lat = $loc->latitude + mt_rand(-1000, 1000) / 1000 * $spread;
        $lng = $loc->longitude + mt_rand(-1000, 1000) / 1000 * $spread;
        $gps = fn () => ['latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => $accuracy, 'device_ts' => now()->toIso8601String(), 'provider' => 'gps', 'device_id' => 'demo-'.$agent->id];
        $meta = ['latitude' => $loc->latitude, 'longitude' => $loc->longitude, 'source' => 'camera'];

        Carbon::setTestNow($day->copy()->setTime($hour, mt_rand(0, 10)));
        $visit = $visits->start($agent, $assignment, (string) Str::uuid(), 'demo-'.$agent->id);
        $visit = $visits->checkIn($visit, $agent, $gps());
        $this->photo($storage, $visit, $agent, $loc, 'check-in', $meta + ['kind' => 'checkin']);

        Carbon::setTestNow($day->copy()->setTime($hour, mt_rand(12, 25)));
        $stocked = mt_rand(1, 100) <= (int) ($quality * 100 + 6);
        $answers = [
            $q['Are Highlands products on the shelf?']->id => $stocked,
            $q['Shelf cleanliness (1-5)']->id => max(1, min(5, (int) round($quality * 5 + mt_rand(-1, 1)))),
            $q['Competitor activity seen']->id => ['none', 'none', 'promo', 'display', 'price'][mt_rand(0, 4)],
            $q['Shelf prices match the price list?']->id => mt_rand(1, 100) <= 85,
        ];
        if ($stocked) {
            $answers[$q['Total Highlands facings']->id] = mt_rand(8, 40);
            $answers[$q['Is there a chiller with Highlands products?']->id] = mt_rand(1, 100) <= 70;
        } else {
            $answers[$q['Why not?']->id] = 'Out of stock; the distributor delivery is expected later in the week.';
        }
        $visits->saveResponses($visit, $answers);

        $ourFacings = 0;
        foreach ($msl as $p) {
            $available = $stocked && mt_rand(1, 100) <= (int) ($quality * 100 + 4);
            $facings = $available ? mt_rand(1, 6) : 0;
            $ourFacings += $facings;
            $shelf = (float) $p->unit_price * (mt_rand(1, 100) <= 85 ? 1 : 1.08);
            $data->saveOsa($visit, ['product_id' => $p->id, 'available' => $available, 'facings' => $facings, 'price_on_shelf' => round($shelf, 0), 'display_compliant' => $available && mt_rand(1, 100) <= 80]);
            if ($available || mt_rand(1, 3) === 1) {
                $big = in_array($p->pack_size, ['5L', '18.9L', '20L', '3L'], true);
                $data->saveStockCount($visit, ['product_id' => $p->id, 'shelf_quantity' => $available ? ($big ? mt_rand(2, 10) : mt_rand(8, 96)) : 0, 'backroom_quantity' => $available && mt_rand(1, 100) <= 60 ? ($big ? mt_rand(0, 24) : mt_rand(0, 240)) : 0]);
            }
        }
        $data->addMerchandising($visit, [
            'posm_present' => mt_rand(1, 100) <= 70, 'posm_type' => 'shelf_strip', 'planogram_compliant' => mt_rand(1, 100) <= 75,
            'price_compliant' => mt_rand(1, 100) <= 85, 'competitor_present' => mt_rand(1, 100) <= 70,
            'our_facings' => $ourFacings, 'total_facings' => $ourFacings + mt_rand(20, 90),
        ]);
        if (mt_rand(1, 100) <= 30) {   // stock close to expiry, or already past it
            foreach ($msl->shuffle()->take(mt_rand(1, 2)) as $p) {
                $expiry = $day->copy()->addDays(mt_rand(-4, 70));
                $data->addExpiry($visit, ['product_id' => $p->id, 'expiry_date' => $expiry->toDateString(), 'quantity' => mt_rand(2, 36), 'batch_no' => 'HL'.$expiry->copy()->subMonths(9)->format('ymd').'A', 'captured_at' => now()]);
            }
        }
        if ($stocked && mt_rand(1, 100) <= 55) {   // order taken from the store manager for the distributor
            $items = $msl->shuffle()->take(mt_rand(2, 5))->map(fn ($p) => ['product_id' => $p->id, 'quantity' => $p->units_per_case * mt_rand(1, 4)])->values()->all();
            $data->addOrder($visit, ['items' => $items, 'notes' => 'Order agreed with the store manager.']);
        }
        $this->photo($storage, $visit, $agent, $loc, 'shelf', $meta + ['kind' => 'evidence']);

        Carbon::setTestNow($day->copy()->setTime($hour, mt_rand(40, 55)));
        $visit = $visits->checkOut($visit, $agent, $gps());
        $this->photo($storage, $visit, $agent, $loc, 'check-out', $meta + ['kind' => 'checkout']);

        Carbon::setTestNow($day->copy()->setTime($hour, 58));

        return $visits->submit($visit, $agent);
    }

    /** A unique placeholder JPEG (so the evidence gallery has something to show and the duplicate-photo check stays quiet). */
    private function photo(EvidenceStorage $storage, Visit $visit, User $agent, Location $loc, string $label, array $meta): void
    {
        $img = imagecreatetruecolor(480, 360);
        $hue = mt_rand(0, 255);
        for ($y = 0; $y < 360; $y += 4) {
            imagefilledrectangle($img, 0, $y, 480, $y + 3, imagecolorallocate($img, ($hue + intdiv($y, 3)) % 200 + 30, 90 + intdiv($y, 6), 140 + mt_rand(0, 60)));
        }
        for ($i = 0; $i < 5; $i++) {
            imagefilledrectangle($img, 30 + $i * 88, 190, 85 + $i * 88, 300, imagecolorallocate($img, mt_rand(150, 255), mt_rand(20, 120), mt_rand(20, 120)));
        }
        imagestring($img, 4, 12, 12, 'DEMO PHOTO - '.$label, imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 3, 12, 34, substr(preg_replace('/[^\x20-\x7E]/', '-', $loc->name), 0, 60), imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 2, 12, 340, Str::random(20), imagecolorallocate($img, 255, 255, 255));
        $tmp = tempnam(sys_get_temp_dir(), 'demo');
        imagejpeg($img, $tmp, 70);
        imagedestroy($img);
        try {
            $storage->storePhoto(new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true), $visit, $agent, $meta);
        } finally {
            @unlink($tmp);
        }
    }

    private function issues(array $p, Campaign $campaign, $outlets, Carbon $realNow): void
    {
        $by = fn (string $name) => $outlets->firstWhere('name', $name) ?? $outlets->first();
        $rows = [
            ['Out of stock: CLUB Cola 2L', 'No stock on the shelf or in the back room. Store manager says the next delivery is on Thursday.', 'stock', 'high', 'open', 2, 'Naivas Kilimani'],
            ['Competitor display blocking our shelf', 'A competitor floor stack covers the main soda bay and hides the CLUB range.', 'competitor', 'medium', 'assigned', 6, 'Quickmart Lavington'],
            ['Wrong shelf price on Highlands Mineral Water 1L', 'Shelf label shows KES 60 instead of KES 50.', 'pricing', 'medium', 'in_progress', 9, 'Carrefour Two Rivers'],
            ['Chiller not working', 'The store chiller with Bazuu Energy has been off since the weekend and product is warm.', 'equipment', 'critical', 'escalated', 5, 'Naivas Westlands'],
            ['Damaged shelf strips', 'POSM strips for Highlands Cordials are torn and need replacing.', 'posm', 'low', 'resolved', 12, 'Chandarana Foodplus Parklands'],
            ['Store closed during scheduled visit', 'Premises were closed for stock-taking; the visit could not be done.', 'access', 'medium', 'resolved', 14, 'Cleanshelf Ngara'],
            ['Expired stock on shelf', 'Highlands Cordial Orange 1L, six units past best-before date removed with the manager approval.', 'quality', 'high', 'open', 1, 'Naivas Buruburu'],
            ['Back room stock not rotated', 'Older Highlands Water 5L batches are behind newer ones in the back room.', 'quality', 'medium', 'closed', 16, 'Carrefour Garden City'],
        ];
        $agents = $p['agents'];
        foreach ($rows as $i => [$title, $desc, $cat, $priority, $status, $daysAgo, $outlet]) {
            $when = $realNow->copy()->subDays($daysAgo)->setTime(11, mt_rand(0, 59));
            Carbon::setTestNow($when);
            $reporter = $agents[$i % count($agents)];
            $assignee = $status === 'open' ? null : $p['sup']->id;
            $issue = new Issue;
            $issue->forceFill([
                'campaign_id' => $campaign->id, 'location_id' => $by($outlet)->id, 'title' => $title, 'description' => $desc, 'category' => $cat, 'priority' => $priority,
                'status' => $status, 'reported_by' => $reporter->id, 'assigned_to' => $assignee,
                'escalated_at' => $status === 'escalated' ? $when->copy()->addHours(3) : null,
                'resolution' => in_array($status, ['resolved', 'closed'], true) ? 'Handled with the store manager and confirmed on the next visit.' : null,
                'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $when->copy()->addDay() : null,
                'closed_at' => $status === 'closed' ? $when->copy()->addDays(2) : null,
            ])->save();
            IssueComment::query()->create(['issue_id' => $issue->id, 'user_id' => $reporter->id, 'type' => 'status', 'body' => 'Issue reported.']);
            if ($assignee) {
                IssueComment::query()->create(['issue_id' => $issue->id, 'user_id' => $p['sup']->id, 'type' => 'comment', 'body' => 'Following this up with the store and the project manager.']);
            }
        }
    }
}
