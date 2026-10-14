<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Visit */
class VisitResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'client_uuid' => $this->client_uuid, 'status' => $this->status->value,
            'visit_date' => $this->visit_date?->toDateString(),
            'started_at' => $this->started_at?->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(), 'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note, 'gps_outcome' => $this->gps_outcome?->value,
            'assignment_id' => $this->assignment_id, 'form_id' => $this->form_id, 'notes' => $this->notes,
            'campaign' => $this->whenLoaded('campaign', fn () => $this->campaign?->only(['id', 'name'])),
            'activity' => $this->whenLoaded('activity', fn () => $this->activity?->only(['id', 'name'])),
            'location' => $this->whenLoaded('location', fn () => $this->location?->only(['id', 'name', 'address', 'town', 'latitude', 'longitude', 'geofence_radius_m'])),
            'agent' => $this->whenLoaded('agent', fn () => $this->agent?->only(['id', 'name'])),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->only(['id', 'name'])),
            'checkin' => $this->whenLoaded('checkin'), 'checkout' => $this->whenLoaded('checkout'),
            'responses' => $this->whenLoaded('responses', fn () => $this->responses->pluck('value', 'question_id')),
            'sales' => $this->whenLoaded('sales'), 'osa_records' => $this->whenLoaded('osaRecords'),
            'merchandising_records' => $this->whenLoaded('merchandisingRecords'),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => $p->toArray() + ['url' => "/api/v1/photos/{$p->id}/file"])->all()),
            'issues' => $this->whenLoaded('issues', fn () => $this->issues->map->only(['id', 'title', 'status', 'priority'])->all()),
            'events' => $this->whenLoaded('events'),
        ];
    }
}
