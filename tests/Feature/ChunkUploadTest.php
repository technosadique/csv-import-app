<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChunkUploadTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_processes_image_and_generates_variants()
    {
        Storage::fake();

        // Arrange: make a fake upload record
        $upload = Upload::factory()->create([
            'upload_id' => 'abc123',
            'filename' => 'test.jpg',
            'total_chunks' => 1,
            'uploaded_chunks' => 1,
            'completed' => false,
        ]);

        // Fake original image in storage
        $fakeImage = \Intervention\Image\Facades\Image::canvas(1200, 800, '#ff0000')
            ->encode('jpg');
        $assembledPath = storage_path("app/uploads/abc123/assembled/test.jpg");
        if (!is_dir(dirname($assembledPath))) {
            mkdir(dirname($assembledPath), 0755, true);
        }
        file_put_contents($assembledPath, $fakeImage);

        // Act: call complete endpoint
        $response = $this->postJson('/upload/complete', [
            'upload_id' => 'abc123',
        ]);

        // Assert
        $response->assertJson(['success' => true]);

        // Check DB has original + 3 variants
        $this->assertEquals(4, Image::where('upload_id', $upload->id)->count());

        // Check files exist in storage
        Storage::disk('local')->assertExists("images/abc123/original.jpg");
        Storage::disk('local')->assertExists("images/abc123/variant_256.jpg");
        Storage::disk('local')->assertExists("images/abc123/variant_512.jpg");
        Storage::disk('local')->assertExists("images/abc123/variant_1024.jpg");
    }
}
