<?php

namespace HiEvents\DomainObjects;

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\Helper\AddressHelper;

class EventSettingDomainObject extends Generated\EventSettingDomainObjectAbstract
{
    /**
     * @todo This should not be here.
     */
    public function getGetEmailFooterHtml(): string
    {
        if ($this->getEmailFooterMessage() === null) {
            return '';
        }

        return <<<HTML
<div style="color: #888; margin-top: 30px; margin-bottom: 30px; font-size: .9em;">
    {$this->getEmailFooterMessage()}
</div>
HTML;
    }

    public function getAddressString(): string
    {
        return AddressHelper::formatAddress($this->getLocationDetails());
    }

    /**
     * Venue name for event-specific confirmation copy (Rule C-1).
     *
     * Returns null when there is no usable venue name, so the caller can fall back
     * to the shorter headline. A formatted street address is deliberately NOT a
     * fallback: "You're going to Fall Kamp 2026 at 1 Campus Drive, Allendale, MI"
     * reads like a shipping label.
     *
     * getLocationDetails() is declared array|string|null, so the string case is
     * handled rather than indexed into.
     */
    public function getConfirmationVenueName(): ?string
    {
        $details = $this->getLocationDetails();

        if (is_string($details)) {
            $decoded = json_decode($details, true);
            $details = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($details)) {
            return null;
        }

        $venueName = $details['venue_name'] ?? null;

        if (! is_string($venueName)) {
            return null;
        }

        $venueName = trim($venueName);

        return $venueName === '' ? null : $venueName;
    }

    public function getAddress(): AddressDTO
    {
        return new AddressDTO(
            venue_name: $this->getLocationDetails()['venue_name'] ?? null,
            address_line_1: $this->getLocationDetails()['address_line_1'] ?? null,
            address_line_2: $this->getLocationDetails()['address_line_2'] ?? null,
            city: $this->getLocationDetails()['city'] ?? null,
            state_or_region: $this->getLocationDetails()['state_or_region'] ?? null,
            zip_or_postal_code: $this->getLocationDetails()['zip_or_postal_code'] ?? null,
            country: $this->getLocationDetails()['country'] ?? null,
        );
    }
}
