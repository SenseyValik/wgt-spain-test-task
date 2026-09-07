<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => ['data' => ['status' => 'ok']]);
