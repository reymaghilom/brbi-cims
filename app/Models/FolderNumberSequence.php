<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FolderNumberSequence extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';
}
