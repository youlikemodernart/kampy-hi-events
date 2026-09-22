<?php

declare(strict_types=1);

namespace HiEvents\Models;

class GvsuRegistrationAssignment extends BaseModel
{
    protected $table = 'gvsu_registration_assignments';

    protected function getFillableFields(): array
    {
        return [
            'provision_batch_id',
            'event_id',
            'order_id',
            'attendee_id',
            'attendee_public_id',
            'respondent_id',
            'assignment_id',
            'assignment_revision',
            'attendee_display_name',
            'respondent_display_name',
            'respondent_route',
            'guardian_relationship_reference',
            'respondent_identity_digest_sha256',
            'delivery_destination_ciphertext',
            'payload_digest_sha256',
            'delivery_email_hmac_sha256',
            'replaced_assignment_id',
            'status',
            'bound_at',
            'corrected_at',
            'link_replacement_requested_at',
            'link_replacement_delivered_at',
            'attempted_at',
            'delivered_at',
            'unknown_at',
        ];
    }

    protected function getCastMap(): array
    {
        return [
            'assignment_revision' => 'integer',
            'delivery_destination_ciphertext' => 'encrypted',
            'bound_at' => 'datetime',
            'corrected_at' => 'datetime',
            'link_replacement_requested_at' => 'datetime',
            'link_replacement_delivered_at' => 'datetime',
            'attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'unknown_at' => 'datetime',
        ];
    }
}
