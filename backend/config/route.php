<?php

use Webman\Route;

Route::get('/', 'app\\controller\\SearchController@index');
Route::get('/search', 'app\\controller\\SearchController@list');
Route::get('/torrent/{infohash}', 'app\\controller\\TorrentController@detail');
Route::get('/healthz', function () {
    return response('ok');
});

