<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Folder extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'parent_id',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($folder) {
            if (empty($folder->uuid)) {
                $folder->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Parent folder relationship.
     */
    public function parent()
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    /**
     * Subfolders relationship.
     */
    public function children()
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    /**
     * Media items contained in this folder.
     */
    public function media()
    {
        return $this->hasMany(Media::class);
    }
}
