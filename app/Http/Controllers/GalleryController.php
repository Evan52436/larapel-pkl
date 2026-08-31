<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class GalleryController extends Controller
{
    /**
     * Display a paginated list of media files and subfolders.
     */
    public function index(Request $request)
    {
        $folderId = $request->query('folder_id');
        $currentFolder = $folderId ? Folder::find($folderId) : null;

        // Build breadcrumb chain
        $breadcrumbs = [];
        $curr = $currentFolder;
        while ($curr) {
            array_unshift($breadcrumbs, [
                'id' => $curr->id,
                'name' => $curr->name,
            ]);
            $curr = $curr->parent;
        }

        // Fetch subfolders in current directory
        $folders = Folder::where('parent_id', $folderId)
            ->withCount(['media', 'children'])
            ->orderBy('name', 'asc')
            ->get();

        // Fetch media items in current directory
        $mediaQuery = Media::query();
        if ($folderId) {
            $mediaQuery->where('folder_id', $folderId);
        } else {
            $mediaQuery->whereNull('folder_id');
        }

        $media = $mediaQuery->orderByRaw('COALESCE(taken_at, created_at) DESC')->paginate(48);

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json([
                'current_folder' => $currentFolder,
                'breadcrumbs' => $breadcrumbs,
                'folders' => $folders,
                'data' => $media->items(),
                'current_page' => $media->currentPage(),
                'has_more' => $media->hasMorePages(),
                'next_page_url' => $media->nextPageUrl(),
                'last_page' => $media->lastPage(),
                'total' => $media->total(),
            ]);
        }

        return view('gallery.index', compact('media', 'folders', 'currentFolder', 'breadcrumbs'));
    }

    /**
     * Store newly uploaded media files into current folder.
     */
    public function store(Request $request)
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp,heic,mp4,mov,webm'],
            'folder_id' => ['nullable', 'exists:folders,id'],
        ], [
            'files.*.uploaded' => 'File upload failed. The file likely exceeds PHP\'s upload_max_filesize or post_max_size limit on the server.',
            'files.*.mimes' => 'Unsupported file format. Allowed: JPG, PNG, GIF, WEBP, HEIC, MP4, MOV, WEBM.',
        ]);

        $folderId = $request->input('folder_id');
        $uploadedMedia = [];
        $disk = config('filesystems.default') === 'minio' || Storage::disk('minio')->getConfig() ? 'minio' : 'public';

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

            $folderPath = 'media/' . date('Y/m');
            $objectKey = $folderPath . '/' . $uuid . '.' . $extension;

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
                if ($imageInfo = @getimagesize($file->getRealPath())) {
                    $width = $imageInfo[0] ?? null;
                    $height = $imageInfo[1] ?? null;
                }

                if (in_array($extension, ['jpg', 'jpeg', 'tiff'])) {
                    try {
                        $exif = @exif_read_data($file->getRealPath());
                        if (!empty($exif['DateTimeOriginal'])) {
                            $takenAt = date('Y-m-d H:i:s', strtotime($exif['DateTimeOriginal']));
                        } elseif (!empty($exif['DateTime'])) {
                            $takenAt = date('Y-m-d H:i:s', strtotime($exif['DateTime']));
                        }
                    } catch (\Throwable $e) {
                        // Ignore EXIF errors
                    }
                }

                try {
                    $manager = new ImageManager(new Driver());
                    $img = $manager->read($file->getRealPath());
                    $img->scale(width: 400);
                    $encodedJpeg = $img->toJpeg(85);

                    $thumbKey = 'thumbnails/' . date('Y/m') . '/' . $uuid . '.jpg';
                    Storage::disk($disk)->put($thumbKey, (string) $encodedJpeg);
                    $thumbnailPath = $thumbKey;
                } catch (\Throwable $e) {
                    $thumbnailPath = null;
                }
            }

            $media = Media::create([
                'uuid' => $uuid,
                'folder_id' => $folderId,
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

        return redirect()->route('gallery.index', array_filter(['folder_id' => $folderId]))
            ->with('success', count($uploadedMedia) . ' file(s) uploaded successfully.');
    }

    /**
     * Rename a media file.
     */
    public function updateMedia(Request $request, Media $media)
    {
        $request->validate([
            'original_name' => ['required', 'string', 'max:255'],
        ]);

        $media->original_name = trim($request->input('original_name'));
        $media->save();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'File renamed successfully.',
                'media' => $media,
            ]);
        }

        return redirect()->back()->with('success', 'File renamed successfully.');
    }

    /**
     * Remove specified media from storage and database.
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
            // Ignore storage deletion error
        }

        $mediaId = $media->id;
        $media->delete();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Media deleted successfully.',
                'id' => $mediaId,
            ]);
        }

        return redirect()->back()->with('success', 'Media deleted successfully.');
    }

    /**
     * Create a new folder.
     */
    public function storeFolder(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:folders,id'],
        ]);

        $folder = Folder::create([
            'name' => trim($request->input('name')),
            'parent_id' => $request->input('parent_id'),
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Folder created successfully.',
                'folder' => $folder->loadCount(['media', 'children']),
            ]);
        }

        return redirect()->back()->with('success', 'Folder created successfully.');
    }

    /**
     * Rename a folder.
     */
    public function updateFolder(Request $request, Folder $folder)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $folder->name = trim($request->input('name'));
        $folder->save();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Folder renamed successfully.',
                'folder' => $folder,
            ]);
        }

        return redirect()->back()->with('success', 'Folder renamed successfully.');
    }

    /**
     * Delete a folder and its contents recursively.
     */
    public function destroyFolder(Folder $folder, Request $request)
    {
        $this->deleteFolderStorageFiles($folder);

        $folderId = $folder->id;
        $folder->delete();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Folder deleted successfully.',
                'id' => $folderId,
            ]);
        }

        return redirect()->back()->with('success', 'Folder deleted successfully.');
    }

    /**
     * Delete storage files for a folder and its children.
     */
    protected function deleteFolderStorageFiles(Folder $folder)
    {
        foreach ($folder->media as $media) {
            try {
                if ($media->path && Storage::disk($media->disk)->exists($media->path)) {
                    Storage::disk($media->disk)->delete($media->path);
                }
                if ($media->thumbnail_path && Storage::disk($media->disk)->exists($media->thumbnail_path)) {
                    Storage::disk($media->disk)->delete($media->thumbnail_path);
                }
            } catch (\Throwable $e) {
                // Ignore storage deletion errors
            }
        }

        foreach ($folder->children as $child) {
            $this->deleteFolderStorageFiles($child);
        }
    }
}
