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
    try {
        $request->validate([
            'upload_id' => 'required|string',
            'checksum' => 'nullable|string',
            'entity_type' => 'nullable|string',
            'entity_id' => 'nullable|integer',
            'is_primary' => 'nullable|boolean',
        ]);

        $uploadId = $request->upload_id;
        $expectedChecksum = $request->checksum;

        $upload = Upload::where('upload_id', $uploadId)->first();
        if (!$upload) {
            return response()->json(['success' => false, 'message' => 'Upload not found'], 404);
        }

        if ($upload->completed) {
            return response()->json(['success' => true, 'message' => 'Already completed']);
        }

        $chunkDir = storage_path("app/uploads/{$uploadId}/chunks");
        if (!is_dir($chunkDir)) {
            return response()->json(['success' => false, 'message' => 'No chunks found'], 422);
        }

        // collect chunks
        $chunkFiles = array_filter(scandir($chunkDir), fn($f) => is_file($chunkDir.'/'.$f));
        $chunkIndices = array_map('intval', $chunkFiles);
        sort($chunkIndices);

        if (count($chunkIndices) !== $upload->total_chunks) {
            return response()->json([
                'success' => false,
                'message' => 'Missing chunks',
                'received' => count($chunkIndices),
                'expected' => $upload->total_chunks
            ], 422);
        }

        // assemble file
        $assembledDir = storage_path("app/uploads/{$uploadId}/assembled");
        if (!is_dir($assembledDir)) mkdir($assembledDir, 0755, true);
        $assembledPath = "{$assembledDir}/{$upload->filename}";

        $out = fopen($assembledPath, 'w');
        foreach ($chunkIndices as $i) {
            $in = fopen("{$chunkDir}/{$i}", 'r');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        // checksum check
        $computed = hash_file('sha256', $assembledPath);
        $expected = $upload->checksum ?: $expectedChecksum;

        if ($expected && $computed !== $expected) {
            unlink($assembledPath);
            return response()->json([
                'success' => false,
                'message' => 'Checksum mismatch',
                'expected' => $expected,
                'computed' => $computed
            ], 422);
        }

        // move final file to permanent storage
        $ext = pathinfo($upload->filename, PATHINFO_EXTENSION) ?: 'jpg';
        $finalDir = "images/{$uploadId}";
        Storage::makeDirectory($finalDir);
        $finalPath = "{$finalDir}/original.{$ext}";
        Storage::put($finalPath, file_get_contents($assembledPath));
		
		$image = Image::create([
			'upload_id'   => $upload->id,
			'path'        => $finalPath,
			'is_primary'  => $request->input('is_primary', true),
			'variant'     => 'original',
			//'width'       => $width,
			//'height'      => $height,
			'entity_type' => $request->input('entity_type'), // e.g. User, Product
			'entity_id'   => $request->input('entity_id')
		]);


        // update DB
        $upload->completed = true;
        $upload->checksum = $upload->checksum ?: $expectedChecksum;
        $upload->save();

        return response()->json([
            'success' => true,
            'message' => 'Upload completed',
            'path' => $finalPath
        ]);
    } catch (\Throwable $e) {
        \Log::error("Upload complete error", ['error' => $e->getMessage()]);
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

}
