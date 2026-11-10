<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

// SQLite fixture: the production model allocates the number using MySQL SHOW TABLE STATUS.
class CotizacionServicioModel extends Model
{
    protected $table = 'cservicios';
    protected $guarded = [];
    protected $casts = ['condiciones_servicio' => 'array'];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->n_cotizacion = str_pad((string) (static::max('id') + 1), 6, '0', STR_PAD_LEFT);
        });
    }
}
