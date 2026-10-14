<?php

namespace Tests\Support;

use App\Domain\Assignments\AssignmentService;
use App\Domain\Forms\FormStructureService;
use App\Enums\CampaignStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Activity;
use App\Models\AgentProfile;
use App\Models\Assignment;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Form;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Builds a small but complete "world" (two clients, two campaigns, a supervised team, two agents, forms,
 * products, locations, assignments) so each test states only what it is about.
 */
class Scenario
{
    public const PASSWORD = 'Str0ng!Passw0rd';
    public const LAT = -1.2673;
    public const LNG = 36.8118;

    public User $admin;
    public User $ops;
    public User $manager;
    public User $supervisor;
    public User $agent;        // supervised by $supervisor, assigned to campaign A
    public User $otherAgent;   // not supervised by $supervisor
    public User $analyst;
    public User $clientAUser;
    public User $clientBUser;
    public Client $clientA;
    public Client $clientB;
    public Campaign $campaignA;
    public Campaign $campaignB;
    public Activity $activityA;
    public Location $locationA;
    public Location $locationB;
    public Form $formA;
    public Product $productA;
    public Assignment $assignmentA;     // agent @ locationA today
    public Assignment $assignmentB;     // otherAgent @ locationB today (campaign B)
    public array $questions = [];

    public static function build(): self
    {
        $s = new self;
        $s->admin = self::user('Admin', RoleEnum::SystemAdmin);
        $s->ops = self::user('Ops', RoleEnum::OperationsManager);
        $s->manager = self::user('Manager', RoleEnum::CampaignManager);
        $s->supervisor = self::user('Supervisor', RoleEnum::Supervisor);
        $s->analyst = self::user('Analyst', RoleEnum::Analyst);
        $s->agent = self::user('Agent One', RoleEnum::FieldAgent);
        $s->otherAgent = self::user('Agent Two', RoleEnum::FieldAgent);
        AgentProfile::query()->create(['user_id' => $s->agent->id, 'supervisor_id' => $s->supervisor->id, 'agent_type' => 'merchandiser']);
        AgentProfile::query()->create(['user_id' => $s->otherAgent->id, 'agent_type' => 'promoter']);

        $s->clientA = Client::query()->create(['name' => 'Client A', 'code' => 'CA']);
        $s->clientB = Client::query()->create(['name' => 'Client B', 'code' => 'CB']);
        $s->clientAUser = self::user('Client A User', RoleEnum::ClientUser, ['client_id' => $s->clientA->id]);
        $s->clientBUser = self::user('Client B User', RoleEnum::ClientUser, ['client_id' => $s->clientB->id]);

        $s->locationA = self::location('Store A', self::LAT, self::LNG);
        $s->locationB = self::location('Store B', -1.30, 36.85);
        $s->productA = Product::query()->create(['client_id' => $s->clientA->id, 'name' => 'Cola 500ml', 'sku' => 'COLA-500', 'unit_price' => 60]);

        $s->formA = self::form();
        $s->campaignA = self::campaign($s->clientA, 'CAMP-A', [$s->locationA], $s->formA, [$s->productA]);
        $s->campaignB = self::campaign($s->clientB, 'CAMP-B', [$s->locationB], null, []);
        $s->campaignA->users()->sync([$s->manager->id]);
        $s->activityA = Activity::query()->create(['campaign_id' => $s->campaignA->id, 'form_id' => $s->formA->id, 'name' => 'Audit', 'status' => 'active']);
        Activity::query()->create(['campaign_id' => $s->campaignB->id, 'name' => 'Audit B', 'status' => 'active']);

        $svc = app(AssignmentService::class);
        $s->assignmentA = $svc->create(['campaign_id' => $s->campaignA->id, 'activity_id' => $s->activityA->id, 'agent_id' => $s->agent->id, 'location_id' => $s->locationA->id, 'scheduled_date' => now()->toDateString()], $s->manager);
        $s->assignmentB = $svc->create(['campaign_id' => $s->campaignB->id, 'agent_id' => $s->otherAgent->id, 'location_id' => $s->locationB->id, 'scheduled_date' => now()->toDateString()], $s->ops);

        return $s;
    }

    public static function user(string $name, RoleEnum $role, array $extra = []): User
    {
        $u = User::query()->create($extra + [
            'name' => $name, 'email' => Str::slug($name).'-'.Str::lower(Str::random(5)).'@example.test', 'password' => self::PASSWORD, 'status' => 'active',
        ]);
        $u->roles()->attach(Role::query()->where('slug', $role->value)->value('id'));

        return $u->refresh();
    }

    public static function location(string $name, float $lat, float $lng, int $radius = 100): Location
    {
        return Location::query()->create(['name' => $name, 'code' => Str::upper(Str::random(6)), 'type' => 'retail_store', 'latitude' => $lat, 'longitude' => $lng, 'geofence_radius_m' => $radius]);
    }

    /** A published form: yes/no (required) -> conditional text, integer 0..50, photo (not required). */
    public static function form(): Form
    {
        $svc = app(FormStructureService::class);
        $form = Form::query()->create(['group_id' => (string) Str::uuid(), 'version' => 1, 'name' => 'Audit form', 'status' => 'draft']);
        $svc->replace($form, [[
            'title' => 'Audit', 'questions' => [
                ['ref' => 'stocked', 'type' => 'yes_no', 'label' => 'Stocked?', 'required' => true],
                ['ref' => 'why', 'type' => 'long_text', 'label' => 'Why not?', 'required' => true, 'conditions' => [['depends_on' => 'stocked', 'operator' => 'equals', 'value' => false]]],
                ['ref' => 'facings', 'type' => 'integer', 'label' => 'Facings', 'validation' => ['min' => 0, 'max' => 50]],
                ['ref' => 'shelf', 'type' => 'photo', 'label' => 'Shelf photo'],
            ],
        ]]);

        return $svc->publish($form);
    }

    /** @return array<string,string> label => question id */
    public function questionIds(): array
    {
        return $this->questions ?: $this->questions = $this->formA->questions()->pluck('id', 'label')->all();
    }

    public static function campaign(Client $client, string $code, array $locations, ?Form $form, array $products): Campaign
    {
        $c = Campaign::query()->create(['client_id' => $client->id, 'code' => $code, 'name' => $code, 'status' => CampaignStatus::Active, 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString()]);
        $c->locations()->sync(collect($locations)->pluck('id')->all());
        $c->products()->sync(collect($products)->pluck('id')->all());
        $form && $c->forms()->sync([$form->id]);

        return $c;
    }

    public function actingAs(User $user): self
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    /** GPS reading exactly at (or $metersNorth metres from) location A. */
    public static function gps(float $metersNorth = 0, array $extra = []): array
    {
        return $extra + ['latitude' => self::LAT + $metersNorth / 111195.0, 'longitude' => self::LNG, 'accuracy_m' => 8, 'device_ts' => now()->toIso8601String(), 'device_id' => 'test-device'];
    }
}
