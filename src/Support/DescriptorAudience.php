<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * The endpoint family a signed descriptor is minted for
 * ({@see SignedDescriptor}).
 *
 * Every descriptor names its audience, and every endpoint accepts only its
 * own: a token minted for one endpoint can't be replayed against another
 * whose payload happens to have the same shape (a relation-manager token
 * carries everything the table-repeater endpoint reads, for instance).
 *
 * @internal
 */
enum DescriptorAudience: string
{
    case TableWrite        = 'table.write';
    case TableBulk         = 'table.bulk';
    case TableFormAction   = 'table.form-action';
    case TableAggregates   = 'table.aggregates';
    case TableRecordModals = 'table.record-modals';
    case RelationManager   = 'relation-manager';
    case FormRecompute     = 'form.recompute';
    case TableRepeater     = 'form.table-repeater';
    case Search            = 'form.search';
    case Upload            = 'form.upload';
    case AddressLocations  = 'form.address-locations';
    case CalendarMove      = 'calendar.move';
    case KanbanMove        = 'kanban.move';
    case ClassToken        = 'class';
    case ImportFile        = 'import.file';
    case Comments          = 'comments';
    case Tags              = 'tags';
}
