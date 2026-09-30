<?php

namespace HiEvents\Services\Domain\Email\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UniversityEmailThemeDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $primary,
        public readonly string $secondary,
        public readonly string $onPrimary,
        public readonly string $onSecondary,
        public readonly string $secondarySoft,
    ) {
    }
}
