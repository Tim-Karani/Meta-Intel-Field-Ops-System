<?php

namespace App\Domain\Forms;

use App\Enums\QuestionType;
use App\Models\Form;
use App\Models\FormCondition;
use App\Models\FormOption;
use App\Models\FormQuestion;
use App\Models\FormSection;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Builds, validates, copies and publishes form structures. Published forms are immutable. */
class FormStructureService
{
    public const MAX_QUESTIONS = 300;
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'gt', 'lt', 'answered'];

    /**
     * Normalised structure:
     * sections[{title, description, questions[{ref, type, label, ..., options[{label,value,score}], conditions[{depends_on, operator, value}]}]}]
     * Throws a ValidationException that points at the offending path.
     */
    public function assertStructure(array $sections): void
    {
        $errors = [];
        $refs = [];
        $count = 0;

        foreach ($sections as $si => $section) {
            foreach ($section['questions'] ?? [] as $qi => $q) {
                $count++;
                $path = "sections.$si.questions.$qi";
                $ref = (string) ($q['ref'] ?? '');
                if ($ref === '') {
                    $errors["$path.ref"] = 'Each question needs a unique ref.';
                } elseif (isset($refs[$ref])) {
                    $errors["$path.ref"] = 'Duplicate question ref.';
                } else {
                    $refs[$ref] = ['order' => $count, 'type' => $q['type'] ?? null];
                }
            }
        }
        if ($count === 0) {
            $errors['sections'] = 'A form needs at least one question.';
        }
        if ($count > self::MAX_QUESTIONS) {
            $errors['sections'] = 'A form can have at most '.self::MAX_QUESTIONS.' questions.';
        }

        foreach ($sections as $si => $section) {
            foreach ($section['questions'] ?? [] as $qi => $q) {
                $path = "sections.$si.questions.$qi";
                $type = QuestionType::tryFrom((string) ($q['type'] ?? ''));
                if (!$type) {
                    $errors["$path.type"] = 'Unknown question type.';
                    continue;
                }
                $options = $q['options'] ?? [];
                if ($type->hasOptions()) {
                    if (count($options) < 2) {
                        $errors["$path.options"] = 'Choice questions need at least two options.';
                    }
                    $values = array_column($options, 'value');
                    if (count($values) !== count(array_unique(array_map('strval', $values)))) {
                        $errors["$path.options"] = 'Option values must be unique.';
                    }
                } elseif ($options) {
                    $errors["$path.options"] = 'Only choice questions can have options.';
                }
                $v = $q['validation'] ?? [];
                if (isset($v['min'], $v['max']) && $v['min'] > $v['max']) {
                    $errors["$path.validation"] = 'Minimum cannot exceed maximum.';
                }
                foreach ($q['conditions'] ?? [] as $ci => $c) {
                    $cp = "$path.conditions.$ci";
                    $dep = (string) ($c['depends_on'] ?? '');
                    if ($dep === ($q['ref'] ?? null)) {
                        $errors["$cp.depends_on"] = 'A question cannot depend on itself.';
                    } elseif (!isset($refs[$dep])) {
                        $errors["$cp.depends_on"] = 'The referenced question does not exist in this form.';
                    } elseif ($refs[$dep]['order'] > ($refs[$q['ref']]['order'] ?? 0)) {
                        $errors["$cp.depends_on"] = 'A question can only depend on an earlier question.';
                    } elseif (in_array($refs[$dep]['type'], ['photo', 'signature'], true)) {
                        $errors["$cp.depends_on"] = 'Conditions cannot depend on photo or signature questions.';
                    }
                    if (!in_array($c['operator'] ?? null, self::OPERATORS, true)) {
                        $errors["$cp.operator"] = 'Unknown operator.';
                    }
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Replace the whole structure of a DRAFT form. */
    public function replace(Form $form, array $sections): Form
    {
        $this->assertDraft($form);
        $this->assertStructure($sections);

        return DB::transaction(function () use ($form, $sections) {
            FormSection::query()->where('form_id', $form->id)->delete();   // cascades to questions/options/conditions
            $this->build($form, $sections);

            return $form->refresh();
        });
    }

    /** @param list<array> $sections */
    private function build(Form $form, array $sections): void
    {
        $idByRef = [];
        $pending = [];

        foreach (array_values($sections) as $si => $s) {
            $section = FormSection::query()->create([
                'form_id' => $form->id, 'title' => $s['title'], 'description' => $s['description'] ?? null, 'position' => $si,
            ]);
            foreach (array_values($s['questions'] ?? []) as $qi => $q) {
                $question = FormQuestion::query()->create([
                    'form_id' => $form->id, 'section_id' => $section->id, 'type' => $q['type'], 'label' => $q['label'],
                    'help_text' => $q['help_text'] ?? null, 'required' => (bool) ($q['required'] ?? false),
                    'evidence_required' => (bool) ($q['evidence_required'] ?? false), 'position' => $qi,
                    'validation' => $q['validation'] ?? null, 'scoring' => $q['scoring'] ?? null, 'settings' => $q['settings'] ?? null,
                ]);
                $idByRef[$q['ref']] = $question->id;
                foreach (array_values($q['options'] ?? []) as $oi => $o) {
                    FormOption::query()->create([
                        'question_id' => $question->id, 'label' => $o['label'], 'value' => (string) $o['value'],
                        'score' => $o['score'] ?? null, 'position' => $oi,
                    ]);
                }
                foreach ($q['conditions'] ?? [] as $c) {
                    $pending[] = [$question->id, $c];
                }
            }
        }
        foreach ($pending as [$qid, $c]) {
            FormCondition::query()->create([
                'question_id' => $qid, 'depends_on_question_id' => $idByRef[$c['depends_on']], 'operator' => $c['operator'],
                'value' => array_key_exists('value', $c) ? (is_array($c['value']) ? $c['value'] : [$c['value']]) : null,
            ]);
        }
    }

    /** Export a form's structure in the same shape the builder submits. */
    public function export(Form $form): array
    {
        $form->loadMissing('sections.questions.options', 'sections.questions.conditions');

        return $form->sections->map(fn ($s) => [
            'id' => $s->id, 'title' => $s->title, 'description' => $s->description,
            'questions' => $s->questions->map(fn ($q) => [
                'id' => $q->id, 'ref' => $q->id, 'type' => $q->type->value, 'label' => $q->label, 'help_text' => $q->help_text,
                'required' => $q->required, 'evidence_required' => $q->evidence_required, 'validation' => $q->validation,
                'scoring' => $q->scoring, 'settings' => $q->settings,
                'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'value' => $o->value, 'score' => $o->score])->all(),
                'conditions' => $q->conditions->map(fn ($c) => ['depends_on' => $c->depends_on_question_id, 'operator' => $c->operator, 'value' => $c->value])->all(),
            ])->all(),
        ])->all();
    }

    /** Copy structure into a new draft (new version of the same group, or a fresh group when duplicating). */
    public function copy(Form $source, string $actorFormOwner, bool $newVersion, ?string $name = null): Form
    {
        return DB::transaction(function () use ($source, $actorFormOwner, $newVersion, $name) {
            $groupId = $newVersion ? $source->group_id : (string) Str::uuid();
            $version = $newVersion ? (int) Form::withTrashed()->where('group_id', $groupId)->max('version') + 1 : 1;

            $copy = Form::query()->create([
                'group_id' => $groupId, 'version' => $version, 'name' => $name ?? ($newVersion ? $source->name : $source->name.' (copy)'),
                'description' => $source->description, 'status' => 'draft', 'requires_live_capture' => $source->requires_live_capture,
                'created_by' => $actorFormOwner,
            ]);

            // Re-use the builder format: refs are the source question ids.
            $this->build($copy, $this->export($source));

            return $copy;
        });
    }

    public function publish(Form $form): Form
    {
        $this->assertDraft($form);
        $this->assertStructure($this->export($form));
        $form->update(['status' => 'published']);

        return $form->refresh();
    }

    public function assertDraft(Form $form): void
    {
        if ($form->status !== 'draft') {
            throw DomainRuleException::conflict('Published forms are immutable. Create a new version to change them.', 'form_not_draft');
        }
    }
}
