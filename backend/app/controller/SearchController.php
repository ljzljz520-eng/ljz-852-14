<?php

namespace app\controller;

use app\model\Torrent;
use app\service\ManticoreClient;
use support\Request;

class SearchController
{
    public function index(Request $request)
    {
        $latest = Torrent::where('status', '<>', 'spam')->order('created_at', 'desc')->limit(8)->select()->toArray();

        return view('search/index', [
            'q' => (string) $request->get('q', ''),
            'latest' => $latest,
        ]);
    }

    public function list(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $page = max(1, (int) $request->get('page', 1));
        $sort = (string) $request->get('sort', 'new');

        if ($q === '') {
            $pageSize = 20;
            $orderField = $sort === 'size' ? 'size_total' : 'created_at';
            $total = (int) Torrent::where('status', '<>', 'spam')->count();
            $items = Torrent::where('status', '<>', 'spam')->order($orderField, 'desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            return view('search/list', [
                'q' => $q,
                'items' => $items,
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'sort' => $sort,
                'from' => 'mysql',
            ]);
        }

        $pageSize = 20;
        $client = new ManticoreClient();
        $result = $client->search($q, $page, $pageSize, $sort);

        return view('search/list', [
            'q' => $q,
            'items' => $result['items'],
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $result['total'],
            'sort' => $sort,
            'from' => 'manticore',
        ]);
    }
}
