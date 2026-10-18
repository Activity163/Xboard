<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;

/**
 * 订阅（套餐）只读接口。
 *
 * 精简版删掉了订阅管理的增删改与订单/支付，但后台「用户编辑」和
 * 「生成用户」两个弹窗里仍保留「订阅」下拉，其选项数据来自 GET plan/fetch。
 * 这里只提供查询，不做写入 —— 套餐本身通过安装命令或数据库维护。
 */
class PlanController extends Controller
{
    public function fetch()
    {
        return $this->success(
            Plan::query()->orderBy('sort')->orderBy('id')->get()
        );
    }
}
