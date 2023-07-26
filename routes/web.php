<?php


// use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\GLTFController;

// Route::get('/', function () {
//     return view('upload');
// });

// Route::post('/upload', [GLTFController::class, 'upload'])->name('upload');
// Route::post('/decrypt', [GLTFController::class, 'decrypt'])->name('decrypt');
use App\Http\Controllers\GLTFController;
use Illuminate\Support\Facades\Route;

Route::get('/gltf/upload', [GLTFController::class, 'showUploadForm'])->name('gltf.upload.form');
Route::post('/gltf/upload', [GLTFController::class, 'upload'])->name('gltf.upload');

Route::get('/gltf/decrypt', [GLTFController::class, 'showDecryptForm'])->name('gltf.decrypt.form');
Route::post('/gltf/decrypt', [GLTFController::class, 'decrypt'])->name('gltf.decrypt');

Route::post('/gltf/encrypt', [GLTFController::class, 'encrypt'])->name('gltf.encrypt');
