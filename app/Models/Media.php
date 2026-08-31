<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'disk',
        'path',
        'thumbnail_path',
        'original_name',
        'mime_type',
        'type',
        'size',
        'width',
        'height',
        'duration_seconds',
        'taken_at',
    ];

    protected $casts = [
        'taken_at' => 'datetime',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_seconds' => 'integer',
    ];

    protected $appends = [
        'url',
        'thumbnail_url',
        'formatted_size',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($media) {
            if (empty($media->uuid)) {
                $media->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get presigned URL for full resolution original file.
     */
    public function url(): string
    {
        try {
            return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes(30));
        } catch (\Throwable $e) {
            return Storage::disk($this->disk)->url($this->path);
        }
    }

    /**
     * Get presigned URL for generated thumbnail (photos only).
     */
    public function thumbnailUrl(): ?string
    {
        if (!$this->thumbnail_path) {
            return null;
        }

        try {
            return Storage::disk($this->disk)->temporaryUrl($this->thumbnail_path, now()->addMinutes(30));
        } catch (\Throwable $e) {
            return Storage::disk($this->disk)->url($this->thumbnail_path);
        }
    }

    /**
     * Human-readable file size string.
     */
    public function formattedSize(): string
    {
        $bytes = (int) $this->size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * Accessor for 'url' attribute.
     */
    public function getUrlAttribute(): string
    {
        return $this->url();
    }

    /**
     * Accessor for 'thumbnail_url' attribute.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnailUrl();
    }

    /**
     * Accessor for 'formatted_size' attribute.
     */
    public function getFormattedSizeAttribute(): string
    {
        return $this->formattedSize();
    }
}
