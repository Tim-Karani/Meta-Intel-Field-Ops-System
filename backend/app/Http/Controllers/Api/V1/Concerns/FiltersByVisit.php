<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Date / campaign / agent / location filters for visit-captured records (sales, OSA, merchandising). */
trait FiltersByVisit
{
    protected function indexQuery(Builder $query, Request $request): Builder
    {
        $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']]);
        $visit = array_filter([
            'campaign_id' => $this->filterValue($request, 'campaign_id', true),
            'agent_id' => $this->filterValue($request, 'agent_id', true),
            'location_id' => $this->filterValue($request, 'location_id', true),
        ]);
        $from = $request->query('date_from');
        $to = $request->query('date_to');
        if ($visit || $from || $to) {
            $query->whereHas('visit', function (Builder $v) use ($visit, $from, $to) {
                foreach ($visit as $col => $val) {
                    $v->where("visits.$col", $val);
                }
                $from && $v->where('visits.visit_date', '>=', $from);
                $to && $v->where('visits.visit_date', '<=', $to);
            });
        }

        return $query;
    }
}
