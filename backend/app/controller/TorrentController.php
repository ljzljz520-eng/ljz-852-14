<?php

namespace app\controller;

use app\model\Torrent;
use support\Request;

class TorrentController
{
    public function detail(Request $request, string $infohash)
    {
        $torrent = Torrent::find($infohash);

        return view('torrent/detail', [
            'infohash' => $infohash,
            'torrent' => $torrent ? $torrent->toArray() : null,
        ]);
    }
}
