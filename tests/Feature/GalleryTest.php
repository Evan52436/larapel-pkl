<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryTest extends TestCase
{
    public function test_gallery_routes_are_registered()
    {
        $this->assertTrue(Route::has('gallery.index'));
        $this->assertTrue(Route::has('gallery.upload'));
        $this->assertTrue(Route::has('gallery.media.update'));
        $this->assertTrue(Route::has('gallery.destroy'));
        $this->assertTrue(Route::has('gallery.folders.store'));
        $this->assertTrue(Route::has('gallery.folders.update'));
        $this->assertTrue(Route::has('gallery.folders.destroy'));
    }

    public function test_media_model_formatting_methods()
    {
        $media = new Media([
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'disk' => 'public',
            'path' => 'media/test.jpg',
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'type' => 'photo',
            'size' => 4404019, // ~4.2 MB
        ]);

        $this->assertEquals('4.2 MB', $media->formattedSize());
        $this->assertNotNull($media->url());
    }

    public function test_media_model_formatted_sizes_for_various_bytes()
    {
        $small = new Media(['size' => 500]);
        $this->assertEquals('500 B', $small->formattedSize());

        $kb = new Media(['size' => 2048]);
        $this->assertEquals('2.0 KB', $kb->formattedSize());

        $gb = new Media(['size' => 1073741824 * 2.5]);
        $this->assertEquals('2.5 GB', $gb->formattedSize());
    }

    public function test_upload_validation_rejects_disallowed_mimes()
    {
        Storage::fake('public');

        $invalidFile = UploadedFile::fake()->create('malicious.php', 100, 'text/x-php');

        $response = $this->postJson('/gallery/upload', [
            'files' => [$invalidFile],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['files.0']);
    }

    public function test_folder_instantiation_uuid()
    {
        $folder = new Folder(['name' => 'Vacation Photos']);
        $folder->uuid = (string) \Illuminate\Support\Str::uuid();

        $this->assertNotEmpty($folder->uuid);
        $this->assertEquals('Vacation Photos', $folder->name);
    }
}
