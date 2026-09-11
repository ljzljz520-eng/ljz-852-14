<?php

namespace app\model;

use support\think\Model;

class Torrent extends Model
{
    protected $table = 'torrents';
    protected $pk = 'infohash';
    protected $json = ['files_json'];
}

