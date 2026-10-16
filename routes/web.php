<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| 用户前端（展示型主页）已移除，个人自用无需。
| 保留：首页存活探测 + 后台管理面板入口 + 订阅入口 + 管理 API（见 app/Http/Routes/V2）。
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

// 后台管理面板入口（React dist 位于 public/assets/admin）
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => config('app.version', '1.0.0'),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');
