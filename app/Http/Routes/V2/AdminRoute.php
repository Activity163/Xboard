<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Controllers\V2\Admin\PluginController;
use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Http\Controllers\V2\Admin\Server\RouteController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\StubController;
use App\Http\Controllers\V2\Admin\SystemController;
use App\Http\Controllers\V2\Admin\TrafficResetController;
use App\Http\Controllers\V2\Admin\UserController;
use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            // Config
            $router->group([
                'prefix' => 'config'
            ], function ($router) {
                $router->get('/fetch', [ConfigController::class, 'fetch']);
                $router->post('/save', [ConfigController::class, 'save']);
            });

            // Plan（订阅）—— 只读。用户编辑 / 生成用户弹窗的「订阅」下拉需要它
            // 注意：必须定义在文件末尾 StubController 的 plan/{action} 降级路由之前，
            // Laravel 按注册顺序匹配，先注册的具体路由优先。
            $router->get('plan/fetch', [PlanController::class, 'fetch']);

            // Server
            $router->group([
                'prefix' => 'server/group'
            ], function ($router) {
                $router->get('/fetch', [GroupController::class, 'fetch']);
                $router->post('/save', [GroupController::class, 'save']);
                $router->post('/drop', [GroupController::class, 'drop']);
            });
            $router->group([
                'prefix' => 'server/route'
            ], function ($router) {
                $router->get('/fetch', [RouteController::class, 'fetch']);
                $router->post('/save', [RouteController::class, 'save']);
                $router->post('/drop', [RouteController::class, 'drop']);
            });
            // 节点管理接口
            $router->group([
                'prefix' => 'server/manage'
            ], function ($router) {
                $router->get('/getNodes', [ManageController::class, 'getNodes']);
                $router->post('/update', [ManageController::class, 'update']);
                $router->post('/save', [ManageController::class, 'save']);
                $router->post('/drop', [ManageController::class, 'drop']);
                $router->post('/copy', [ManageController::class, 'copy']);
                $router->post('/sort', [ManageController::class, 'sort']);
                $router->post('/batchDelete', [ManageController::class, 'batchDelete']);
                $router->post('/batchUpdate', [ManageController::class, 'batchUpdate']);
                $router->post('/resetTraffic', [ManageController::class, 'resetTraffic']);
                $router->post('/batchResetTraffic', [ManageController::class, 'batchResetTraffic']);
                $router->get('/generateEchKey', [ManageController::class, 'generateEchKey']);
            });

            // User
            $router->group([
                'prefix' => 'user'
            ], function ($router) {
                $router->any('/fetch', [UserController::class, 'fetch']);
                $router->post('/update', [UserController::class, 'update']);
                $router->get('/getUserInfoById', [UserController::class, 'getUserInfoById']);
                $router->post('/generate', [UserController::class, 'generate']);
                $router->post('/dumpCSV', [UserController::class, 'dumpCSV']);
                $router->post('/ban', [UserController::class, 'ban']);
                $router->post('/resetSecret', [UserController::class, 'resetSecret']);
                $router->post('/destroy', [UserController::class, 'destroy']);
            });

            // System
            $router->group([
                'prefix' => 'system'
            ], function ($router) {
                $router->get('/getSystemStatus', [SystemController::class, 'getSystemStatus']);
                $router->get('/getQueueStats', [SystemController::class, 'getQueueStats']);
                $router->get('/getQueueWorkload', [SystemController::class, 'getQueueWorkload']);
                $router->get('/getQueueMasters', '\\Laravel\\Horizon\\Http\\Controllers\\MasterSupervisorController@index');
                $router->get('/getHorizonFailedJobs', [SystemController::class, 'getHorizonFailedJobs']);
                $router->any('/getAuditLog', [SystemController::class, 'getAuditLog']);
            });

            // Plugin
            $router->group([
                'prefix' => 'plugin'
            ], function ($router) {
                $router->get('/types', [PluginController::class, 'types']);
                $router->get('/getPlugins', [PluginController::class, 'index']);
                $router->post('/upload', [PluginController::class, 'upload']);
                $router->post('/delete', [PluginController::class, 'delete']);
                $router->post('install', [PluginController::class, 'install']);
                $router->post('uninstall', [PluginController::class, 'uninstall']);
                $router->post('enable', [PluginController::class, 'enable']);
                $router->post('disable', [PluginController::class, 'disable']);
                $router->get('config', [PluginController::class, 'getConfig']);
                $router->post('config', [PluginController::class, 'updateConfig']);
                $router->post('upgrade', [PluginController::class, 'upgrade']);
            });

            // 流量重置管理
            $router->group([
                'prefix' => 'traffic-reset'
            ], function ($router) {
                $router->get('logs', [TrafficResetController::class, 'logs']);
                $router->get('stats', [TrafficResetController::class, 'stats']);
                $router->get('user/{userId}/history', [TrafficResetController::class, 'userHistory']);
                $router->post('reset-user', [TrafficResetController::class, 'resetUser']);
            });

            // ------------------------------------------------------------------
            // 已精简模块的降级路由（stub）
            //
            // 后台前端产物编译自上游完整版，仍会请求这些已删除模块的接口。
            // 其中 server/machine/fetch 被前端以 60s 间隔轮询，缺失会导致后台
            // 持续弹出 "route could not be found" 报错。
            // 这里统一返回空数据，使页面显示为空列表。
            // 详见 App\Http\Controllers\V2\Admin\StubController。
            // ------------------------------------------------------------------

            // 机器节点集群（已移除）—— 前端轮询，必须保留
            $router->any('server/machine/{action}', [StubController::class, 'stub'])
                ->where('action', '[a-zA-Z]+');

            // 交易类：订单 / 套餐 / 优惠券 / 礼品卡 / 支付
            foreach (['order', 'plan', 'coupon', 'gift-card', 'payment'] as $prefix) {
                $router->any($prefix . '/{action}', [StubController::class, 'stub'])
                    ->where('action', '[a-zA-Z-]+');
            }

            // 运营类：工单 / 知识库 / 公告
            $router->any('ticket/{action}', [StubController::class, 'stub'])->where('action', '[a-zA-Z]+');
            $router->any('knowledge/{action}', [StubController::class, 'stub'])->where('action', '[a-zA-Z]+');
            $router->any('config/knowledge', [StubController::class, 'stub']);
            $router->any('config/notice', [StubController::class, 'stub']);

            // 统计类
            $router->any('stat/{action}', [StubController::class, 'stub'])->where('action', '[a-zA-Z]+');

            // 主题类
            $router->any('theme/{action}', [StubController::class, 'stub'])->where('action', '[a-zA-Z]+');

            // 邮件 / Telegram 等通知配置
            $router->any('config/testSendMail', [StubController::class, 'stub']);
            $router->any('config/setTelegramWebhook', [StubController::class, 'stub']);
            $router->any('config/getEmailTemplate', [StubController::class, 'stub']);
            $router->any('config/payment', [StubController::class, 'stub']);
            $router->any('config/plugin', [StubController::class, 'stub']);
            $router->any('config/theme', [StubController::class, 'stub']);

            // 系统设置分组（前端按 tab 请求）
            $router->any('config/system/{group}', [StubController::class, 'stub'])
                ->where('group', '[a-zA-Z-]+');

            // 流量审计插件（已移除）
            $router->any('plugin/traffic-analytics/records/{action}', [StubController::class, 'stub'])
                ->where('action', '[a-zA-Z]+');
        });

    }
}
