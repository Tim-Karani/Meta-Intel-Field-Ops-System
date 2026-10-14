<?php

namespace Tests\Feature;

use App\Models\Form;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class FormBuilderTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->manager);
    }

    private function structure(array $questions): array
    {
        return ['sections' => [['title' => 'S1', 'questions' => $questions]]];
    }

    public function test_draft_publish_new_version_flow_keeps_published_forms_immutable(): void
    {
        $form = $this->api('POST', 'forms', ['name' => 'Survey'] + $this->structure([
            ['ref' => 'a', 'type' => 'short_text', 'label' => 'Name'],
            ['ref' => 'b', 'type' => 'single_select', 'label' => 'Size', 'options' => [['label' => 'S', 'value' => 's'], ['label' => 'L', 'value' => 'l']], 'conditions' => [['depends_on' => 'a', 'operator' => 'answered']]],
        ]))->assertCreated()->json('data');

        $this->api('POST', "forms/{$form['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->api('PUT', "forms/{$form['id']}/structure", $this->structure([['ref' => 'z', 'type' => 'short_text', 'label' => 'Z']]))->assertStatus(409);
        $this->api('PATCH', "forms/{$form['id']}", ['name' => 'Renamed'])->assertStatus(409);
        $this->api('DELETE', "forms/{$form['id']}")->assertStatus(409);

        $v2 = $this->api('POST', "forms/{$form['id']}/new-version")->assertCreated()->json('data');
        $this->assertSame(2, $v2['version']);
        $this->assertSame($form['group_id'], $v2['group_id']);
        $this->assertSame('draft', $v2['status']);
        $this->assertCount(2, $v2['sections'][0]['questions']);
        $this->assertCount(1, $v2['sections'][0]['questions'][1]['conditions'], 'conditions are re-mapped to the new question ids');
        $this->assertNotSame($form['sections'][0]['questions'][0]['id'], $v2['sections'][0]['questions'][0]['id']);
        $this->api('POST', "forms/{$form['id']}/new-version")->assertStatus(409);   // only one open draft per form
    }

    public function test_structure_validation(): void
    {
        $bad = [
            'no questions' => ['sections' => [['title' => 'S', 'questions' => []]]],
            'unknown type' => $this->structure([['ref' => 'a', 'type' => 'teleport', 'label' => 'x']]),
            'select without options' => $this->structure([['ref' => 'a', 'type' => 'single_select', 'label' => 'x']]),
            'options on text' => $this->structure([['ref' => 'a', 'type' => 'short_text', 'label' => 'x', 'options' => [['label' => 'a', 'value' => 'a'], ['label' => 'b', 'value' => 'b']]]]),
            'duplicate refs' => $this->structure([['ref' => 'a', 'type' => 'short_text', 'label' => 'x'], ['ref' => 'a', 'type' => 'short_text', 'label' => 'y']]),
            'self reference' => $this->structure([['ref' => 'a', 'type' => 'short_text', 'label' => 'x', 'conditions' => [['depends_on' => 'a', 'operator' => 'answered']]]]),
            'forward reference' => $this->structure([['ref' => 'a', 'type' => 'short_text', 'label' => 'x', 'conditions' => [['depends_on' => 'b', 'operator' => 'answered']]], ['ref' => 'b', 'type' => 'short_text', 'label' => 'y']]),
            'min above max' => $this->structure([['ref' => 'a', 'type' => 'integer', 'label' => 'x', 'validation' => ['min' => 10, 'max' => 1]]]),
            'bad operator' => $this->structure([['ref' => 'a', 'type' => 'short_text', 'label' => 'x'], ['ref' => 'b', 'type' => 'short_text', 'label' => 'y', 'conditions' => [['depends_on' => 'a', 'operator' => 'sounds_like']]]]),
        ];
        foreach ($bad as $label => $payload) {
            $this->api('POST', 'forms', ['name' => "Bad: $label"] + $payload)->assertStatus(422);
        }
        $this->assertSame(0, Form::query()->where('name', 'like', 'Bad:%')->count(), 'nothing is persisted for an invalid structure');
    }

    public function test_every_question_type_can_be_built(): void
    {
        $types = ['short_text', 'long_text', 'integer', 'decimal', 'currency', 'yes_no', 'date_time', 'rating', 'percentage', 'product', 'gps', 'photo', 'signature', 'barcode'];
        $qs = collect($types)->map(fn ($t) => ['ref' => $t, 'type' => $t, 'label' => $t])->all();
        foreach (['single_select', 'multi_select', 'dropdown'] as $t) {
            $qs[] = ['ref' => $t, 'type' => $t, 'label' => $t, 'options' => [['label' => 'A', 'value' => 'a', 'score' => 1], ['label' => 'B', 'value' => 'b', 'score' => 0]]];
        }
        $this->api('POST', 'forms', ['name' => 'All types'] + $this->structure($qs))->assertCreated();
        $this->assertCount(17, $qs, 'all 17 question types from the spec');
    }

    public function test_only_published_forms_can_be_linked_to_campaigns(): void
    {
        $draft = $this->api('POST', 'forms', ['name' => 'Draft'] + $this->structure([['ref' => 'a', 'type' => 'short_text', 'label' => 'x']]))->json('data.id');
        $this->api('PUT', "campaigns/{$this->s->campaignA->id}/links/forms", ['ids' => [$draft]])->assertStatus(422);
        $this->api('PUT', "campaigns/{$this->s->campaignA->id}/links/forms", ['ids' => [$this->s->formA->id]])->assertOk();
    }

    public function test_agents_cannot_manage_forms(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('POST', 'forms', ['name' => 'x'] + $this->structure([['ref' => 'a', 'type' => 'short_text', 'label' => 'x']]))->assertForbidden();
    }
}
