<?php

use App\Http\Controllers\UserImportController;
use App\Http\Controllers\ChunkUploadController;

Route::get('/upload-test', function () {
    return view('chunk-upload');
});

Route::get('/import-users', [UserImportController::class, 'showForm'])->name('users.form');
Route::post('/import-users', [UserImportController::class, 'import'])->name('users.import');

Route::post('/uploads/init', [ChunkUploadController::class, 'init']);
Route::post('/uploads/chunk', [ChunkUploadController::class, 'uploadChunk']);
Route::get('/uploads/status/{uploadId}', [ChunkUploadController::class, 'status']);
Route::post('/uploads/complete', [ChunkUploadController::class, 'complete']);

