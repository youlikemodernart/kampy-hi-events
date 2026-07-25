<?php

namespace HiEvents\DomainObjects\Enums;

enum StripeRefundRequestStatus: string
{
    case CREATED = 'CREATED';
    case PROVIDER_ACCEPTED = 'PROVIDER_ACCEPTED';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
}
