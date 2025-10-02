<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image as InterventionImage;
use Illuminate\Support\Str;

class ChunkUploadController extends Controller
{
    // Optional: initialize an upload record (client can call before sending chunks)
    public function init(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string',
            'filename' => 'required|string',
            'size' => 'required|integer',
            'total_chunks' => 'required|integer',
            'checksum' => 'nullable|string', // sha256 hex
        ]);

        $upload = Upload::firstOrCreate(
            ['upload_id' => $request->upload_id],
            [
                'filename' => $request->filename,
                'size' => $request->size,
                'total_chunks' => $request->total_chunks,
                'checksum' => $request->checksum,
            ]
        );

        return response()->json(['success' => true, 'upload' => $upload]);
    }

    // Accept a chunk
    public function uploadChunk(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string',
            'index' => 'required|integer|min:0',
            'total' => 'required|integer|min:1',
            'chunk' => 'required|file',
        ]);

        $uploadId = $request->upload_id;
        $index = $request->index;
        $total = $request->total;

        // save chunk to storage/app/uploads/{upload_id}/chunks/{index}
        $chunkPath = "uploads/{$uploadId}/chunks";
        Storage::makeDirectory($chunkPath);

        // Use a temp filename for chunk
        $stored = $request->file('chunk')->storeAs($chunkPath, (string)$index);

        // update uploaded_chunks count in DB safely
        DB::transaction(function () use ($uploadId, $total) {
            $upload = Upload::where('upload_id', $uploadId)->lockForUpdate()->first();
            if ($upload) {
                $uploadedChunks = Storage::files("uploads/{$uploadId}/chunks");
                $upload->uploaded_chunks = count($uploadedChunks);
                $upload->total_chunks = $total;
                $upload->save();
            } else {
                // maybe create a new record with minimal data if not present
            }
        });

        return response()->json(['success' => true, 'index' => $index]);
    }

    // Return array of uploaded chunk indexes (so client can resume)
    public function status($uploadId)
    {
        $chunkDir = "uploads/{$uploadId}/chunks";
        $files = Storage::exists($chunkDir) ? Storage::files($chunkDir) : [];
        $uploaded = [];
        foreach ($files as $file) {
            $uploaded[] = basename($file);
        }
        return response()->json(['uploaded' => $uploaded]);
    }

    // Assemble chunks, validate checksum, create variants
   public function complete(Request $request)
{
    $request->validate([
        'upload_id'   => 'required|string',
        'checksum'    => 'nullable|string',
        'entity_type' => 'nullable|string',
        'entity_id'   => 'nullable|integer',
        'is_primary'  => 'nullable|boolean',
    ]);

    $uploadId         = $request->upload_id;
    $expectedChecksum = $request->checksum;

    $upload = Upload::where('upload_id', $uploadId)->firstOrFail();

    if ($upload->completed) {
        return response()->json(['success' => true, 'message' => 'Already completed']);
    }

    $chunkDir = storage_path("app/uploads/{$uploadId}/chunks");
    if (!is_dir($chunkDir)) {
        return response()->json(['success' => false, 'message' => 'No chunks found'], 422);
    }

    $chunkFiles   = scandir($chunkDir);
    $chunkIndices = [];
    foreach ($chunkFiles as $f) {
        if (is_file($chunkDir . DIRECTORY_SEPARATOR . $f) && is_numeric($f)) {
            $chunkIndices[] = (int)$f;
        }
    }
    sort($chunkIndices);

    if (count($chunkIndices) !== $upload->total_chunks) {
        return response()->json([
            'success'  => false,
            'message'  => 'Missing chunks',
            'received' => count($chunkIndices)
        ], 422);
    }

    // Assemble final file
    $assembledDir  = storage_path("app/uploads/{$uploadId}/assembled");
    if (!is_dir($assembledDir)) {
        mkdir($assembledDir, 0755, true);
    }
    $assembledPath = $assembledDir . DIRECTORY_SEPARATOR . $upload->filename;

    $lockFile = fopen($assembledPath . '.lock', 'w+');
    if ($lockFile === false) {
        return response()->json(['success' => false, 'message' => 'Could not create lock'], 500);
    }

    try {
        if (!flock($lockFile, LOCK_EX)) {
            throw new \Exception("Could not acquire lock");
        }

        // Write chunks into one file
        $out = fopen($assembledPath, 'w');
        if ($out === false) {
            throw new \Exception("Could not open assembled file for writing");
        }

        foreach ($chunkIndices as $index) {
            $chunkFile = $chunkDir . DIRECTORY_SEPARATOR . $index;
            $in        = fopen($chunkFile, 'r');
            if ($in === false) {
                fclose($out);
                throw new \Exception("Could not open chunk {$index}");
            }
            while (!feof($in)) {
                $buffer = fread($in, 1024 * 1024);
                fwrite($out, $buffer);
            }
            fclose($in);
        }

        fflush($out);
        fclose($out);

        // Checksum validation
        $computed = hash_file('sha256', $assembledPath);
        $expected = $upload->checksum ?: $expectedChecksum;

        if ($expected && $computed !== $expected) {
            unlink($assembledPath);
            flock($lockFile, LOCK_UN);
            fclose($lockFile);
            return response()->json([
                'success'  => false,
                'message'  => 'Checksum mismatch',
                'expected' => $expected,
                'computed' => $computed
            ], 422);
        }

        // Move assembled file to permanent location
        $ext       = pathinfo($upload->filename, PATHINFO_EXTENSION) ?: 'jpg';
        $finalDir  = "images/{$uploadId}";
        $finalPath = "{$finalDir}/original.{$ext}";

        Storage::makeDirectory($finalDir);
        Storage::put($finalPath, file_get_contents($assembledPath));

        $fullAssembledPath = storage_path("app/{$finalPath}");

        // Create image + variants
        $variants = [
            'original' => null,
            '1024'     => 1024,
            '512'      => 512,
            '256'      => 256,
        ];

        DB::transaction(function () use ($upload, $finalPath, $fullAssembledPath, $variants, $uploadId, $request) {
            foreach ($variants as $variant => $max) {
                try {
                    if ($variant === 'original') {
                        [$w, $h] = @getimagesize($fullAssembledPath) ?: [null, null];
                        Image::create([
                            'upload_id'   => $upload->id,
                            'variant'     => 'original',
                            'path'        => $finalPath,
                            'width'       => $w,
                            'height'      => $h,
                            'entity_type' => $request->entity_type,
                            'entity_id'   => $request->entity_id,
                            'is_primary'  => $request->boolean('is_primary'),
                        ]);
                    } else {
                        $img = InterventionImage::make($fullAssembledPath)->orientate();
                        $img->resize($max, $max, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        });

                        $variantFilename    = "variant_{$variant}." . pathinfo($finalPath, PATHINFO_EXTENSION);
                        $variantStoragePath = "images/{$uploadId}/{$variantFilename}";
                        Storage::put($variantStoragePath, (string)$img->encode());

                        Image::create([
                            'upload_id'   => $upload->id,
                            'variant'     => (string)$variant,
                            'path'        => $variantStoragePath,
                            'width'       => $img->width(),
                            'height'      => $img->height(),
                            'entity_type' => $request->entity_type,
                            'entity_id'   => $request->entity_id,
                            'is_primary'  => $request->boolean('is_primary'),
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error("Image variant save failed: " . $e->getMessage());
                }
            }

            $upload->completed = true;
            $upload->checksum  = $upload->checksum ?: $request->checksum;
            $upload->save();
        });

        flock($lockFile, LOCK_UN);
        fclose($lockFile);
        return response()->json(['success' => true, 'message' => 'Upload assembled and processed']);

    } catch (\Exception $e) {
        if (isset($out) && is_resource($out)) @fclose($out);
        flock($lockFile, LOCK_UN);
        fclose($lockFile);
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

}
