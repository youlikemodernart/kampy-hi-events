<?php

namespace HiEvents\DomainObjects\Status;

enum OutgoingMessageStatus
{
    case CLAIMED;
    case SUBMITTING;
    case SENT;
    case FAILED;
    case FAILED_CONFIRMED;
    case SUPPRESSED;
    case CANCELLED;
    case UNKNOWN;
}
