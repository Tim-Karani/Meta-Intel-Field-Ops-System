<?php

namespace App\Domain\Forms;

use App\Enums\QuestionType;
use App\Models\Form;
use App\Models\FormQuestion;
use Illuminate\Support\Facades\DB;

/** Server-side validation of form answers: types, ranges, options and conditional visibility. */
class FormResponseValidator
{
    /**
     * @param array<string,mixed> $answers keyed by question id
     * @return array{errors: array<string,string>, normalized: array<string,mixed>, visible: array<string,bool>}
     */
    public function validate(Form $form, array $answers, ?string $campaignId = null, bool $checkRequired = false): array
    {
        $form->loadMissing('questions.options', 'questions.conditions');
        $questions = $form->questions->keyBy('id');

        $visible = $this->visibility($questions->all(), $answers);
        $errors = [];
        $normalized = [];

        foreach ($answers as $qid => $value) {
            if (!$questions->has($qid)) {
                $errors[$qid] = 'This question does not belong to the form.';
            }
        }

        foreach ($questions as $qid => $q) {
            if ($q->type->isEvidence() || !$visible[$qid]) {
                continue;
            }
            if (!array_key_exists($qid, $answers) || $this->isEmpty($answers[$qid])) {
                if ($checkRequired && $q->required) {
                    $errors[$qid] = 'This question is required.';
                }
                continue;
            }
            [$ok, $result] = $this->check($q, $answers[$qid], $campaignId);
            $ok ? $normalized[$qid] = $result : $errors[$qid] = $result;
        }

        return compact('errors', 'normalized', 'visible');
    }

    /** @return array<string,bool> */
    public function visibility(array $questions, array $answers): array
    {
        $visible = [];
        $resolving = [];
        $resolve = function (string $qid) use (&$resolve, &$visible, &$resolving, $questions, $answers): bool {
            if (isset($visible[$qid])) {
                return $visible[$qid];
            }
            if (isset($resolving[$qid])) {
                return $visible[$qid] = true;   // circular rule: fail open rather than loop
            }
            $resolving[$qid] = true;
            $show = true;
            foreach ($questions[$qid]->conditions as $c) {
                $dep = $c->depends_on_question_id;
                $depValue = (isset($questions[$dep]) && $resolve($dep)) ? ($answers[$dep] ?? null) : null;
                if (!$this->conditionHolds($c->operator, $c->value, $depValue)) {
                    $show = false;
                    break;
                }
            }
            unset($resolving[$qid]);
            return $visible[$qid] = $show;
        };
        foreach (array_keys($questions) as $qid) {
            $resolve((string) $qid);
        }
        return $visible;
    }

    private function conditionHolds(string $op, mixed $expected, mixed $actual): bool
    {
        return match ($op) {
            'answered' => !$this->isEmpty($actual),
            'equals' => is_array($actual) ? in_array($expected, $actual, false) : $this->loose($actual) === $this->loose($expected),
            'not_equals' => is_array($actual) ? !in_array($expected, $actual, false) : $this->loose($actual) !== $this->loose($expected),
            'contains' => is_array($actual) ? in_array($expected, $actual, false) : (is_string($actual) && is_string($expected) && str_contains($actual, $expected)),
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            default => true,
        };
    }

    private function loose(mixed $v): mixed
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        return is_scalar($v) ? strtolower((string) $v) : $v;
    }

    private function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    /** @return array{0:bool,1:mixed} [ok, normalizedValue | errorMessage] */
    private function check(FormQuestion $q, mixed $v, ?string $campaignId): array
    {
        $rules = $q->validation ?? [];
        $num = fn ($x) => is_numeric($x) ? $x + 0 : null;

        switch ($q->type) {
            case QuestionType::ShortText:
            case QuestionType::LongText:
            case QuestionType::Barcode:
                if (!is_string($v)) {
                    return [false, 'Must be text.'];
                }
                $max = $rules['max_length'] ?? ($q->type === QuestionType::LongText ? 5000 : 255);
                if (mb_strlen($v) > $max) {
                    return [false, "Must be at most {$max} characters."];
                }
                if (isset($rules['min_length']) && mb_strlen($v) < $rules['min_length']) {
                    return [false, "Must be at least {$rules['min_length']} characters."];
                }
                if (!empty($rules['pattern']) && @preg_match('/'.str_replace('/', '\/', $rules['pattern']).'/u', $v) !== 1) {
                    return [false, 'Does not match the required format.'];
                }
                return [true, trim($v)];

            case QuestionType::Integer:
                $n = $num($v);
                return ($n === null || (float) $n !== (float) (int) $n) ? [false, 'Must be a whole number.'] : $this->range((int) $n, $rules);

            case QuestionType::Decimal:
            case QuestionType::Currency:
                $n = $num($v);
                return $n === null ? [false, 'Must be a number.'] : $this->range((float) $n, $rules);

            case QuestionType::Percentage:
                $n = $num($v);
                return ($n === null || $n < 0 || $n > 100) ? [false, 'Must be between 0 and 100.'] : [true, (float) $n];

            case QuestionType::Rating:
                $max = (int) ($q->settings['max'] ?? 5);
                $n = $num($v);
                return ($n === null || (int) $n != $n || $n < 1 || $n > $max) ? [false, "Must be a rating from 1 to {$max}."] : [true, (int) $n];

            case QuestionType::YesNo:
                $b = match (true) {
                    is_bool($v) => $v,
                    in_array(strtolower((string) $v), ['yes', 'true', '1'], true) => true,
                    in_array(strtolower((string) $v), ['no', 'false', '0'], true) => false,
                    default => null,
                };
                return $b === null ? [false, 'Must be yes or no.'] : [true, $b];

            case QuestionType::SingleSelect:
            case QuestionType::Dropdown:
                return (is_string($v) && in_array($v, $q->options->pluck('value')->all(), true)) ? [true, $v] : [false, 'Select one of the available options.'];

            case QuestionType::MultiSelect:
                if (!is_array($v) || array_diff($v, $q->options->pluck('value')->all()) !== []) {
                    return [false, 'Select only from the available options.'];
                }
                return [true, array_values(array_unique($v))];

            case QuestionType::DateTime:
                try {
                    return [true, \Carbon\Carbon::parse((string) $v)->toIso8601String()];
                } catch (\Throwable) {
                    return [false, 'Must be a valid date/time.'];
                }

            case QuestionType::Gps:
                if (!is_array($v) || !isset($v['lat'], $v['lng']) || !is_numeric($v['lat']) || !is_numeric($v['lng']) || abs($v['lat']) > 90 || abs($v['lng']) > 180) {
                    return [false, 'Must be a valid coordinate pair.'];
                }
                return [true, ['lat' => (float) $v['lat'], 'lng' => (float) $v['lng'], 'accuracy_m' => isset($v['accuracy_m']) ? (float) $v['accuracy_m'] : null]];

            case QuestionType::Product:
                $ok = is_string($v) && DB::table('products')->where('id', $v)->whereNull('deleted_at')
                    ->when($campaignId, fn ($qq) => $qq->whereIn('id', DB::table('campaign_products')->where('campaign_id', $campaignId)->select('product_id')))->exists();
                return $ok ? [true, $v] : [false, 'Select a product that belongs to this campaign.'];

            default:
                return [true, $v];
        }
    }

    private function range(int|float $n, array $rules): array
    {
        if (isset($rules['min']) && $n < $rules['min']) {
            return [false, "Must be at least {$rules['min']}."];
        }
        if (isset($rules['max']) && $n > $rules['max']) {
            return [false, "Must be at most {$rules['max']}."];
        }
        return [true, $n];
    }
}
