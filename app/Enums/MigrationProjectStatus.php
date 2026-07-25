<?php

namespace App\Enums;

enum MigrationProjectStatus: string
{
    case Draft = 'draft';
    case Discovering = 'discovering';
    case Discovered = 'discovered';
    case Planning = 'planning';
    case Planned = 'planned';
    case Approved = 'approved';
    case Applying = 'applying';
    case Applied = 'applied';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case PartiallyApplied = 'partially_applied';
    case Failed = 'failed';
}
