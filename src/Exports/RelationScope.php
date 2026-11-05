<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * The sealed relation scope an export is narrowed by (a relation manager's
 * "export the children of this parent").
 *
 * The scope names a parent record and a relation method, so it must only
 * ever come from the server: {@see seal()} encrypts it for one exporter
 * class, and {@see Exporter::resolveExportQuery()} only honours what
 * {@see open()} returns. A plain array, a forged string, another exporter's
 * seal or any other encrypted Kinetix payload (a relation-manager
 * descriptor, say) opens to null, and the export narrows to zero rows.
 */
final class RelationScope
{
    /**
     * Tells this payload apart from every other Crypt payload Kinetix makes.
     */
    private const PURPOSE = 'kinetix-export-relation';

    /**
     * Seal the scope "the `$relation` records of `$parent`" for one exporter.
     *
     * @param class-string<Exporter> $exporterClass
     */
    public static function seal(Model $parent, string $relation, string $exporterClass): string
    {
        return Crypt::encrypt([
            'purpose'  => self::PURPOSE,
            'exporter' => $exporterClass,
            'parent'   => $parent::class,
            'key'      => $parent->getKey(),
            'name'     => $relation,
        ]);
    }

    /**
     * The scope a seal carries, when it was made for `$exporterClass`; null
     * for anything else.
     *
     * @param  class-string<Exporter>                                                 $exporterClass
     * @return array{parent: class-string<Model>, key: int|string, name: string}|null
     */
    public static function open(mixed $sealed, string $exporterClass): ?array
    {
        if (! is_string($sealed) || $sealed === '') {
            return null;
        }

        try {
            $payload = Crypt::decrypt($sealed);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload) || ($payload['purpose'] ?? null) !== self::PURPOSE || ($payload['exporter'] ?? null) !== $exporterClass) {
            return null;
        }

        $parent = $payload['parent'] ?? null;
        $key    = $payload['key']    ?? null;
        $name   = $payload['name']   ?? null;

        if (! is_string($parent) || ! is_subclass_of($parent, Model::class)) {
            return null;
        }

        if ((! is_int($key) && ! is_string($key)) || ! is_string($name) || $name === '') {
            return null;
        }

        return ['parent' => $parent, 'key' => $key, 'name' => $name];
    }
}
