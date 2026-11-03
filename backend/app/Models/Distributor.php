<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Distributor extends Model
{
    use HasUuids, Auditable, SoftDeletes;

    protected $fillable = ['name', 'code', 'contact_name', 'phone', 'email', 'status', 'notes'];

}
