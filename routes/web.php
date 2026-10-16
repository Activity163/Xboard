<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| 用户前端与后台前端 dist 已移除（个人自用，无需展示型界面）。
| 仅保留：首页存活探测 + 订阅入口 + 管理 API（见 app/Http/Routes/V2）。
|
*/

Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);

        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    return response('Xboard API is running.', 200, ['Content-Type' => 'text/plain']);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');
