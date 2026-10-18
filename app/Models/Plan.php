<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Plan
 *
 * 精简版只保留「订阅模板」语义：一个套餐就是
 * group_id + transfer_enable + 限额 的一组默认值。
 * 用户被分配套餐时，这些值会被抄到 v2_user 对应字段上。
 *
 * @property int $id
 * @property int|null $group_id 权限组ID
 * @property int|null $transfer_enable 流量额度（字节）
 * @property string $name 套餐名
 * @property int|null $speed_limit 限速 Mbps，null/0 不限
 * @property bool $show 是否在用户端展示（精简版不用）
 * @property int|null $sort 排序
 * @property bool $renew 是否允许续费（精简版不用）
 * @property string|null $content 描述
 * @property string|null $prices 价格 JSON（精简版不用）
 * @property bool $sell 是否出售（精简版固定 false）
 * @property int|null $reset_traffic_method 重置方式 0每月1号 1按月 2不重置 3每年 4按年
 * @property int|null $capacity_limit 人数上限，0 不限
 * @property int|null $device_limit 设备数限制
 * @property array|null $tags
 * @property int $created_at
 * @property int $updated_at
 */
class Plan extends Model
{
    protected $table = 'v2_plan';

    protected $dateFormat = 'U';

    protected $guarded = ['id'];

    protected $casts = [
        'prices' => 'array',
        'tags' => 'array',
        'show' => 'boolean',
        'sell' => 'boolean',
        'renew' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
