<?php

return [
    'web_url' => env('WEB_URL', 'http://localhost:8080'),
    'auth' => [
        'max_failed_logins' => (int) env('AUTH_MAX_FAILED_LOGINS', 5),
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
    ],
    'uploads' => [
        'disk' => env('EVIDENCE_DISK', 'evidence'),
        'max_kb' => (int) env('UPLOAD_MAX_KB', 10240),
        'max_dimension' => 2048,
        'jpeg_quality' => 82,
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'document_mimes' => ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
    ],
    // Default GPS rules; overridable per campaign via gps_rules.config. See App\Domain\Visits\GpsValidator.
    'gps' => [
        // Capturing an outlet's position (first visit / field-added) demands a good fix.
        'capture_max_accuracy_m' => 50,
        'default_radius_m' => 100, 'accuracy_poor_m' => 100, 'accuracy_unusable_m' => 500, 'accuracy_credit_cap_m' => 100,
        'near_multiplier' => 2.0, 'far_distance_m' => 1000, 'far_action' => 'reject',
        'skew_warn_s' => 300, 'skew_suspicious_s' => 3600, 'max_speed_kmh' => 200, 'travel_window_h' => 12, 'suspicious_threshold' => 3,
        'weights' => [
            'geofence_near' => 1, 'geofence_outside' => 2, 'geofence_far' => 3, 'accuracy_poor' => 1, 'timestamp_skew' => 1,
            'timestamp_skew_large' => 2, 'impossible_travel' => 2, 'mock_location' => 2, 'device_changed' => 1, 'duplicate_coordinates' => 1,
        ],
    ],
];
