<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * 已精简模块的降级（stub）控制器。
 *
 * 背景：后台管理面板的前端产物（public/assets/admin）编译自上游完整版，
 * 里面仍硬编码着已被精简掉的模块的接口路径（订单 / 套餐 / 支付 / 优惠券 /
 * 礼品卡 / 工单 / 知识库 / 公告 / 邮件模板 / 统计 / 主题 / 机器节点 machine …）。
 *
 * 其中 /server/machine/fetch 会被前端以 60 秒间隔轮询，后端缺失时会持续
 * 抛出 404，导致后台一直弹「The route xxx could not be found」。
 *
 * 本控制器为这些接口提供空实现：统一返回成功 + 空数据，
 * 使前端不再报错，对应页面呈现为空列表。
 * 这样无需改动上游前端产物，也不影响后续升级。
 *
 * 返回策略按 action 名自动推断：
 *   - 列表 / 查询类（fetch、getXxx、show、detail …）→ 空数组 []，前端渲染空列表
 *   - 配置 / 详情类                                     → 空对象 {}，前端读取 key 不炸
 *   - 写操作类（save、drop、update、delete …）          → true
 */
class StubController extends Controller
{
    /**
     * 列表 / 查询类 action 关键词（返回空数组）
     */
    private const LIST_ACTIONS = [
        'fetch', 'list', 'index', 'sort', 'statistics', 'stats', 'history',
        'templates', 'codes', 'usages', 'logs', 'records', 'nodes', 'rank',
    ];

    /**
     * 写操作类 action 关键词（返回 true）
     */
    private const WRITE_ACTIONS = [
        'save', 'update', 'drop', 'delete', 'create', 'generate', 'assign',
        'paid', 'cancel', 'close', 'reply', 'upload', 'reset', 'toggle',
        'enable', 'disable', 'install', 'uninstall', 'test',
    ];

    /**
     * 统一降级入口。
     */
    public function stub(Request $request, ?string $action = null)
    {
        $action = strtolower((string) ($action ?? $request->route('action') ?? ''));

        if ($this->matches($action, self::LIST_ACTIONS)) {
            return $this->success([]);
        }

        if ($this->matches($action, self::WRITE_ACTIONS)) {
            return $this->success(true);
        }

        // 其余（配置 / 详情 / 未分类）返回空对象，避免前端读取属性时报错
        return $this->success((object) []);
    }

    /**
     * 判断 action 是否命中关键词集合（前缀匹配，兼容 getNodes / batchDelete 这类驼峰名）。
     */
    private function matches(string $action, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if ($action === $kw || str_starts_with($action, $kw)) {
                return true;
            }
            // 驼峰：getXxx / batchXxx 等以动词开头的情况
            if (str_starts_with($action, 'get') && str_contains($action, $kw)) {
                return true;
            }
        }

        return false;
    }
}
