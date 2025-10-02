<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model
{
    protected $guarded = [];
	public $timestamps = false;
	
	protected $fillable = [
        'upload_id',
        'path',
        'is_primary',
        'variant',
        'width',
        'height',
        'entity_type',
        'entity_id'
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
