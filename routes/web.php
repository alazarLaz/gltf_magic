<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GLTFController;

Route::get('/', function () {
    return view('upload');
});

Route::post('/upload', [GLTFController::class, 'upload'])->name('upload');
Route::post('/decrypt', [GLTFController::class, 'decrypt'])->name('decrypt');