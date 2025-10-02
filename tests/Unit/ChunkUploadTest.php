<?php

namespace Tests\Unit;

use App\Models\Upload;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Intervention\Image\Facades\Image As Image2;

class ChunkUploadTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_generates_image_variants()
    {
        Storage::fake('local');

        // Arrange: upload record
        $upload = Upload::factory()->create([
            'upload_id' => 'abc123',
            'filename' => 'test.jpg',
			'total_chunks' => 1,
            'uploaded_chunks' => 1,
        ]);
		
		

        // Fake image
        $img = Image2::canvas(1200, 800, '#00ff00')->encode('jpg');
        $path = "images/abc123/original.jpg";
        Storage::disk('local')->put($path, (string)$img);

        // Act: generate variants
        foreach ([256, 512, 1024] as $size) {
            $variant = Image2::make((string)$img)
                ->resize($size, $size, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            $variantPath = "images/abc123/variant_{$size}.jpg";
            Storage::disk('local')->put($variantPath, (string)$variant->encode());

            Image::create([
                'upload_id' => $upload->id,
                'variant'   => (string)$size,
                'path'      => $variantPath,
                'width'     => $variant->width(),
                'height'    => $variant->height(),
            ]);
        }

        // Assert: DB has 3 variants
        $this->assertEquals(3, Image::count());

        // Assert: files exist
        Storage::disk('local')->assertExists("images/abc123/variant_256.jpg");
        Storage::disk('local')->assertExists("images/abc123/variant_512.jpg");
        Storage::disk('local')->assertExists("images/abc123/variant_1024.jpg");
    }
}
