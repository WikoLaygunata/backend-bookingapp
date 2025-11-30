<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingDetail extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'booking_header_id',
        'schedule_id',
        'booking_date',
        'price',
    ];

    public function header()
    {
        return $this->belongsTo(BookingHeader::class, 'booking_header_id');
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }
}
