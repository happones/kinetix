<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * A second notifiable model whose keys overlap the users'.
 */
class RoutingClient extends Model
{
    use Notifiable;

    protected $table = 'routing_clients';

    public $timestamps = false;

    protected $guarded = [];
}
