<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class GalleryController extends Controller
{
    /**
     * Display a paginated list of media files.
     */
    public function index(Request $request)
    {
        $media = Media::orderByRaw('COALESCE(taken_at, created_at) DESC')->paginate(48);

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json([
                'data' => $media->items(),
                'current_page' => $media->currentPage(),
                'has_more' => $media->hasMorePages(),
                'next_page_url' => $media->nextPageUrl(),
                'last_page' => $media->lastPage(),
                'total' => $media->total(),
            ]);
        }

        return view('gallery.index', compact('media'));
    }

    /**
     * Store newly uploaded media files.
     */
    public function store(Request $request)
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp,heic,mp4,mov,webm'],
        ], [
            'files.*.uploaded' => 'File upload failed. The file likely exceeds PHP\'s upload_max_filesize or post_max_size limit on the server.',
            'files.*.mimes' => 'Unsupported file format. Allowed: JPG, PNG, GIF, WEBP, HEIC, MP4, MOV, WEBM.',
        ]);

        $uploadedMedia = [];
        $disk = config('filesystems.default') === 'minio' || Storage::disk('minio')->getConfig() ? 'minio' : 'public';

        // Check if minio disk is available, else fallback to public disk for local dev/testing if minio isn't reachable
        try {
            Storage::disk('minio');
        } catch (\Throwable $e) {
            $disk = 'public';
        }

        foreach ($request->file('files') as $file) {
            $uuid = (string) Str::uuid();
            $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
            $isPhoto = str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic']);

            $folder = 'media/' . date('Y/m');
            $objectKey = $folder . '/' . $uuid . '.' . $extension;

            // Stream file to storage to minimize RAM usage on 4GB hardware
            $stream = fopen($file->getRealPath(), 'r+');
            Storage::disk($disk)->writeStream($objectKey, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $thumbnailPath = null;
            $width = null;
            $height = null;
            $takenAt = null;

            if ($isPhoto) {
                // Get dimensions
                if ($imageInfo = @getimagesize($file->getRealPath())) {
                    $width = $imageInfo[0] ?? null;
                    $height = $imageInfo[1] ?? null;
                }

                // Attempt EXIF date extraction for JPEG/TIFF
                if (in_array($extension, ['jpg', 'jpeg', 'tiff'])) {
                    try {
                        $exif = @exif_read_data($file->getRealPath());
                        if (!empty($exif['DateTimeOriginal'])) {
                            $takenAt = date('Y-m-d H:i:s', strtotime($exif['DateTimeOriginal']));
                        } elseif (!empty($exif['DateTime'])) {
                            $takenAt = date('Y-m-d H:i:s', strtotime($exif['DateTime']));
                        }
                    } catch (\Throwable $e) {
                        // Ignore EXIF parsing failures
                    }
                }

                // Generate ~400px width thumbnail using Intervention Image (GD driver)
                try {
                    $manager = new ImageManager(new Driver());
                    $img = $manager->read($file->getRealPath());
                    $img->scale(width: 400);
                    $encodedJpeg = $img->toJpeg(85);

                    $thumbKey = 'thumbnails/' . date('Y/m') . '/' . $uuid . '.jpg';
                    Storage::disk($disk)->put($thumbKey, (string) $encodedJpeg);
                    $thumbnailPath = $thumbKey;
                } catch (\Throwable $e) {
                    // Fallback if GD / Intervention cannot process thumbnail
                    $thumbnailPath = null;
                }
            }

            $media = Media::create([
                'uuid' => $uuid,
                'disk' => $disk,
                'path' => $objectKey,
                'thumbnail_path' => $thumbnailPath,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'type' => $isPhoto ? 'photo' : 'video',
                'size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
                'duration_seconds' => null,
                'taken_at' => $takenAt ?: now(),
            ]);

            $uploadedMedia[] = $media;
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'message' => count($uploadedMedia) . ' file(s) uploaded successfully.',
                'media' => $uploadedMedia,
            ]);
        }

        return redirect()->route('gallery.index')->with('success', count($uploadedMedia) . ' file(s) uploaded successfully.');
    }

    /**
     * Remove the specified media from storage and database.
     */
    public function destroy(Media $media, Request $request)
    {
        try {
            if ($media->path && Storage::disk($media->disk)->exists($media->path)) {
                Storage::disk($media->disk)->delete($media->path);
            }

            if ($media->thumbnail_path && Storage::disk($media->disk)->exists($media->thumbnail_path)) {
                Storage::disk($media->disk)->delete($media->thumbnail_path);
            }
        } catch (\Throwable $e) {
            // Log or ignore storage deletion errors to ensure DB row removal
        }

        $mediaId = $media->id;
        $media->delete();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Media deleted successfully.',
                'id' => $mediaId,
            ]);
        }

        return redirect()->route('gallery.index')->with('success', 'Media deleted successfully.');
    }
}
