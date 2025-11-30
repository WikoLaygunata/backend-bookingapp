<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use HasUlids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'field_id',
        'name',
        'duration_slots',
        'price',
        'description',
    ];

    public function field()
    {
        return $this->belongsTo(Field::class, 'field_id');
    }
}
