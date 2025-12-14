<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    use HasUlids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'field_id',
        'start_time',
        'end_time',
        'status',
        'price',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function field()
    {
        return $this->belongsTo(Field::class, 'field_id');
    }

    public function bookings()
    {
        return $this->hasMany(BookingDetail::class, 'schedule_id');
    }
}
