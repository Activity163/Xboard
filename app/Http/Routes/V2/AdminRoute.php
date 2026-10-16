<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\PluginController;
use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Http\Controllers\V2\Admin\Server\RouteController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
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
        });

    }
}
