<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use Illuminate\Http\Request;

class UniProxyController extends Controller
{
    private function getNodeInfo(Request $request)
    {
        return $request->attributes->get('node_info');
    }

    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        $node = $this->getNodeInfo($request);

        ServerService::touchNode($node);

        $response['users'] = ServerService::getAvailableUsers($node);

        $eTag = sha1(json_encode($response));
        if (str_contains($request->header('If-None-Match', ''), $eTag)) {
            return response(null, 304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    public function push(Request $request)
    {
        $res = json_decode(request()->getContent(), true);
        if (!is_array($res)) {
            return $this->fail([422, 'Invalid data format']);
        }

        $node = $this->getNodeInfo($request);

        ServerService::processTraffic($node, $res);

        return $this->success(true);
    }

    public function config(Request $request)
    {
        $node = $this->getNodeInfo($request);
        $response = ServerService::buildNodeConfig($node);

        $response['base_config'] = [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60)
        ];

        $eTag = sha1(json_encode($response));
        if (str_contains($request->header('If-None-Match', ''), $eTag)) {
            return response(null, 304);
        }
        return response($response)->header('ETag', "\"{$eTag}\"");
    }
}
