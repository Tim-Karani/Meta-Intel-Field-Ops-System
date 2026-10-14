<?php

namespace Database\Seeders;

use App\Domain\Assignments\AssignmentService;
use App\Domain\Audit\AuditService;
use App\Domain\Forms\FormStructureService;
use App\Domain\Visits\VisitDataService;
use App\Domain\Visits\VisitService;
use App\Enums\CampaignStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Activity;
use App\Models\AgentProfile;
use App\Models\Assignment;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignKpi;
use App\Models\Client;
use App\Models\Form;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Route;
use App\Models\Team;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY (see DatabaseSeeder). Everything is labelled "[DEMO]" and uses Nairobi coordinates.
 * Demo accounts share DEMO_PASSWORD; this seeder refuses to run outside the local environment.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo@Field2026!';

    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('DemoSeeder only runs in local/testing environments.');
        }
        if (Client::query()->where('code', 'DEMO-KBL')->exists()) {
            return;   // already seeded
        }

        $users = $this->users();
        $admin = $users['admin'];

        // --- clients -----------------------------------------------------------------------------
        $kbl = Client::query()->create(['name' => '[DEMO] Kenya Beverages Ltd', 'code' => 'DEMO-KBL', 'company_name' => 'Kenya Beverages Ltd', 'industry' => 'FMCG', 'email' => 'demo-client@example.com', 'created_by' => $admin->id]);
        $dairy = Client::query()->create(['name' => '[DEMO] Savannah Dairy', 'code' => 'DEMO-SAV', 'industry' => 'Dairy', 'created_by' => $admin->id]);
        $users['client']->forceFill(['client_id' => $kbl->id])->save();
        $kbl->hasMany(\App\Models\ClientContact::class)->create(['name' => '[DEMO] Brand Manager', 'email' => 'brand@example.com', 'is_primary' => true]);

        // --- catalogue ---------------------------------------------------------------------------
        $cat = ProductCategory::query()->create(['name' => '[DEMO] Soft drinks']);
        $brand = Brand::query()->create(['client_id' => $kbl->id, 'name' => '[DEMO] Zuri Cola']);
        $products = collect([['Zuri Cola 500ml', 'DEMO-ZC-500', 60], ['Zuri Cola 2L', 'DEMO-ZC-2000', 180], ['Zuri Orange 500ml', 'DEMO-ZO-500', 60], ['Zuri Water 1L', 'DEMO-ZW-1000', 50]])
            ->map(fn ($p) => Product::query()->create(['client_id' => $kbl->id, 'brand_id' => $brand->id, 'category_id' => $cat->id, 'name' => '[DEMO] '.$p[0], 'sku' => $p[1], 'unit_price' => $p[2]]));

        // --- geography ---------------------------------------------------------------------------
        $terr = Territory::query()->create(['name' => '[DEMO] Nairobi Central', 'code' => 'DEMO-NBO-C', 'region' => 'Nairobi']);
        $places = [
            ['Westlands Quickmart', 'Westlands', -1.2673, 36.8118], ['CBD Naivas', 'Nairobi CBD', -1.2833, 36.8219], ['Kilimani Carrefour', 'Kilimani', -1.2921, 36.7872],
            ['Karen Shopping Centre', 'Karen', -1.3190, 36.7120], ['Eastleigh Wholesale', 'Eastleigh', -1.2750, 36.8480], ['Lang\'ata Tuskys', 'Lang\'ata', -1.3600, 36.7400],
            ['Parklands Chandarana', 'Parklands', -1.2630, 36.8180], ['South C Store', 'South C', -1.3180, 36.8300],
        ];
        $locations = collect($places)->map(fn ($p, $i) => Location::query()->create([
            'client_id' => null, 'territory_id' => $terr->id, 'name' => "[DEMO] {$p[0]}", 'code' => 'DEMO-L'.($i + 1), 'type' => 'supermarket',
            'address' => $p[1].', Nairobi', 'county' => 'Nairobi', 'town' => $p[1], 'latitude' => $p[2], 'longitude' => $p[3], 'geofence_radius_m' => 100, 'created_by' => $admin->id,
        ]));
        $route = Route::query()->create(['territory_id' => $terr->id, 'name' => '[DEMO] Westlands loop', 'code' => 'DEMO-R1']);
        $route->locations()->sync($locations->take(3)->values()->mapWithKeys(fn ($l, $i) => [$l->id => ['position' => $i]])->all());

        // --- form (published) --------------------------------------------------------------------
        $structure = app(FormStructureService::class);
        $form = Form::query()->create(['group_id' => (string) Str::uuid(), 'version' => 1, 'name' => '[DEMO] Store audit', 'status' => 'draft', 'created_by' => $admin->id]);
        $structure->replace($form, [[
            'title' => 'Store audit', 'questions' => [
                ['ref' => 'stocked', 'type' => 'yes_no', 'label' => 'Is the brand stocked?', 'required' => true],
                ['ref' => 'why', 'type' => 'long_text', 'label' => 'Why not?', 'conditions' => [['depends_on' => 'stocked', 'operator' => 'equals', 'value' => false]]],
                ['ref' => 'facings', 'type' => 'integer', 'label' => 'Number of facings', 'validation' => ['min' => 0, 'max' => 200]],
                ['ref' => 'cleanliness', 'type' => 'rating', 'label' => 'Shelf cleanliness (1-5)', 'validation' => ['min' => 1, 'max' => 5]],
                ['ref' => 'display', 'type' => 'single_select', 'label' => 'Display type', 'options' => [['label' => 'Shelf', 'value' => 'shelf'], ['label' => 'Floor stack', 'value' => 'floor'], ['label' => 'Chiller', 'value' => 'chiller']]],
            ],
        ]]);
        $form = $structure->publish($form);

        // --- campaigns ---------------------------------------------------------------------------
        $campaign = Campaign::query()->create([
            'client_id' => $kbl->id, 'code' => 'DEMO-ZURI-Q4', 'name' => '[DEMO] Zuri Cola Q4 Merchandising', 'campaign_type' => 'merchandising',
            'description' => 'Demo campaign for local development.', 'budget' => 1500000, 'start_date' => now()->subDays(14)->toDateString(),
            'end_date' => now()->addDays(60)->toDateString(), 'status' => CampaignStatus::Draft, 'created_by' => $admin->id,
        ]);
        $audit = Activity::query()->create(['campaign_id' => $campaign->id, 'form_id' => $form->id, 'name' => '[DEMO] Shelf audit', 'activity_type' => 'store_audit', 'target' => 200, 'status' => 'active', 'created_by' => $admin->id]);
        $campaign->locations()->sync($locations->mapWithKeys(fn ($l) => [$l->id => ['visit_frequency' => 'weekly']])->all());
        $campaign->products()->sync($products->pluck('id'));
        $campaign->forms()->sync([$form->id]);
        $campaign->users()->sync([$users['campaign_manager']->id]);
        CampaignKpi::query()->create(['campaign_id' => $campaign->id, 'name' => 'Approved visits', 'metric' => 'visits_completed', 'target' => 200, 'unit' => 'visits']);
        CampaignKpi::query()->create(['campaign_id' => $campaign->id, 'name' => 'OSA rate', 'metric' => 'osa_rate', 'target' => 90, 'unit' => '%']);
        $campaign->update(['status' => CampaignStatus::Active]);

        Campaign::query()->create(['client_id' => $dairy->id, 'code' => 'DEMO-SAV-LAUNCH', 'name' => '[DEMO] Savannah Yoghurt Launch', 'campaign_type' => 'sampling', 'status' => CampaignStatus::Planned, 'start_date' => now()->addDays(10)->toDateString(), 'created_by' => $admin->id]);

        // --- team --------------------------------------------------------------------------------
        $team = Team::query()->create(['name' => '[DEMO] Nairobi Team A', 'region' => 'Nairobi', 'supervisor_id' => $users['supervisor']->id]);
        $team->members()->sync(collect($users['agents'])->pluck('id'));
        $campaign->teams()->sync([$team->id]);

        // --- assignments for today ---------------------------------------------------------------
        $svc = app(AssignmentService::class);
        $assignments = collect($users['agents'])->flatMap(fn ($agent, $ai) => $locations->slice($ai * 2, 2)->values()->map(fn ($loc, $li) => $svc->create([
            'campaign_id' => $campaign->id, 'activity_id' => $audit->id, 'agent_id' => $agent->id, 'location_id' => $loc->id,
            'scheduled_date' => now()->toDateString(), 'start_time' => sprintf('%02d:00', 9 + $li * 2), 'end_time' => sprintf('%02d:00', 10 + $li * 2),
            'instructions' => 'Check facings and shelf cleanliness; record OSA for all campaign products.',
        ], $users['campaign_manager'])));

        // --- a few worked visits so queues and reports have real rows ----------------------------
        $this->workVisit($assignments->get(0), $form, $products, true);    // approved
        $this->workVisit($assignments->get(2), $form, $products, false);   // waiting for review
        $this->workVisit($assignments->get(4), $form, $products, false);

        $approve = \App\Models\Visit::query()->where('assignment_id', $assignments->get(0)->id)->first();
        $visits = app(VisitService::class);
        $visits->startReview($approve, $users['supervisor']);
        $visits->approve($approve->refresh(), $users['supervisor'], 'Looks good.');

        AuditService::record('demo.seeded', 'system');
    }

    /** Drive a visit through the real service layer, exactly like the mobile app will. */
    private function workVisit(Assignment $assignment, Form $form, $products, bool $withSale): void
    {
        $agent = User::query()->findOrFail($assignment->agent_id);
        $loc = Location::query()->findOrFail($assignment->location_id);
        $gps = ['latitude' => $loc->latitude, 'longitude' => $loc->longitude, 'accuracy_m' => 8, 'device_ts' => now()->toIso8601String(), 'provider' => 'gps', 'device_id' => 'demo-'.$agent->id];

        $visits = app(VisitService::class);
        $data = app(VisitDataService::class);
        $visit = $visits->start($agent, $assignment, (string) Str::uuid(), $gps['device_id']);
        $visit = $visits->checkIn($visit, $agent, $gps);

        $q = $form->questions->keyBy('label');
        $visits->saveResponses($visit, [
            $q['Is the brand stocked?']->id => true, $q['Number of facings']->id => random_int(2, 8),
            $q['Shelf cleanliness (1-5)']->id => random_int(3, 5), $q['Display type']->id => 'shelf',
        ]);
        foreach ($products as $p) {
            $data->saveOsa($visit, ['product_id' => $p->id, 'available' => true, 'facings' => random_int(1, 6), 'price_on_shelf' => $p->unit_price, 'display_compliant' => true]);
        }
        if ($withSale) {
            $data->addSale($visit, ['payment_method' => 'mpesa', 'items' => [['product_id' => $products[0]->id, 'quantity' => 6], ['product_id' => $products[2]->id, 'quantity' => 4]]]);
        }
        $visit = $visits->checkOut($visit, $agent, $gps);
        $visits->submit($visit, $agent);
    }

    /** @return array<string,mixed> */
    private function users(): array
    {
        $make = function (string $key, string $name, string $email, RoleEnum $role) {
            $u = User::query()->create(['name' => "[DEMO] $name", 'email' => $email, 'password' => self::DEMO_PASSWORD, 'status' => 'active', 'phone' => '+2547'.random_int(10000000, 99999999)]);
            $u->roles()->attach(Role::query()->where('slug', $role->value)->value('id'));

            return $u;
        };

        $out = [
            'admin' => $make('admin', 'Admin', 'demo.admin@example.com', RoleEnum::SystemAdmin),
            'ops' => $make('ops', 'Operations Manager', 'demo.ops@example.com', RoleEnum::OperationsManager),
            'campaign_manager' => $make('cm', 'Campaign Manager', 'demo.campaign@example.com', RoleEnum::CampaignManager),
            'supervisor' => $make('sup', 'Supervisor', 'demo.supervisor@example.com', RoleEnum::Supervisor),
            'analyst' => $make('an', 'Analyst', 'demo.analyst@example.com', RoleEnum::Analyst),
            'client' => $make('cl', 'Client User', 'demo.client@example.com', RoleEnum::ClientUser),
        ];
        $out['agents'] = collect([['Amina Wanjiru', 'merchandiser'], ['Brian Otieno', 'brand_ambassador'], ['Cynthia Mutua', 'promoter'], ['David Kiprop', 'merchandiser']])
            ->map(function ($a, $i) use ($make, $out) {
                $u = $make('ag'.$i, $a[0], 'demo.agent'.($i + 1).'@example.com', RoleEnum::FieldAgent);
                AgentProfile::query()->create(['user_id' => $u->id, 'agent_type' => $a[1], 'agent_code' => 'DEMO-A'.($i + 1), 'supervisor_id' => $out['supervisor']->id, 'region' => 'Nairobi']);

                return $u;
            })->all();

        return $out;
    }
}
