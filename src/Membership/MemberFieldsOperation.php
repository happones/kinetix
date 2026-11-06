<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

/**
 * Where a {@see MemberFields} class is being called from.
 */
enum MemberFieldsOperation: string
{
    case Provision  = 'provision';
    case Edit       = 'edit';
    case Activation = 'activation';
}
