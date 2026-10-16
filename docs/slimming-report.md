# Xboard 精简完成报告

> 执行日期：2026-10-16
> 基线 commit：`f193bfd`　最终 commit：`3985a40`
> 目标：只保留 XrayR 对接 + 核心功能，个人自用

---

## 一、最终成果

| 指标 | 精简前 | 精简后 | 变化 |
|---|---|---|---|
| `app/` PHP 行数 | 31,098 | **16,672** | −46% |
| `app/` PHP 文件数 | 252 | **133** | −47% |
| 全项目 PHP 文件 | 400+ | **209** | — |
| Git 变更 | — | — | 193 files changed, **18,002 deletions**, 79 insertions |
| 节点协议 | 11 种 | **3 种** | vmess / vless / shadowsocks |
| XrayR 接口 | 6 个 | **3 个** | config / user / push |
| 控制器 | 40+ | **20** | — |

> 剩余的 16,672 行中，`app/Protocols/`（4,858 行，订阅格式，按要求全留）与插件系统骨架（约 1,800 行）占 40%，实际核心业务代码约 10,000 行。

---

## 二、批次执行记录（每批次独立可回滚）

| 批次 | Commit | 内容 |
|---|---|---|
| 0 | `f193bfd` | 建立基线：`git init` + 物理快照 `D:/项目/Xboard-baseline-20261016.tar.gz` |
| 1 | `63baf1d` | 删除 `plugins-core/` 全部 6 个支付插件 + Telegram，删除 `PaymentInterface` |
| 2 | `9763568` | 收窄 XrayR 接口层：删除 DeviceStateService / NodeRegistry / NodeSyncService / WebSocket / machine 体系 / ServerV2 中间件 / Tidalab 控制器 / 两个 Observer |
| 3 | `4c79057` | 核心链路解耦：流量落库只保留 `TrafficFetchJob`（写 `v2_user.u/d`），删除 StatUserJob / StatServerJob |
| 4a | `d1a3cd1` | 删除交易类：订单 / 支付 / 套餐 / 优惠券 / 礼品卡 / 邀请返佣（45 文件） |
| 4b | `8e4e275` | 删除运营类：工单 / 知识库 / 公告（22 文件） |
| 4c | `ecaad9d` | 删除通知类：邮件 / Telegram（含邮箱验证、邮件链接登录） |
| 4d | `c84b5e7` | 删除统计 / 更新 / 备份 / 多主题 + 前端（`theme/Xboard` 1.4M、`public/assets/admin`） |
| 4e | `941cba5` | 路由清理：重写全部 V1/V2 路由文件 |
| 5 | `69341d8` | 协议收窄 11 → 3（含删除 `2022-blake3-chacha20-poly1305`） |
| 6 | `3985a40` | composer 依赖精简（−11 个）+ 孤立配置清理 + 回归验证 |

**回滚方式**：
```bash
git checkout f193bfd -- <路径>     # 恢复单个文件
git checkout f193bfd -- .          # 全量恢复
git reset --hard <批次commit>      # 回退到某批次
```

---

## 三、保留的能力

- **用户**：增删改、改流量/到期/限速、封禁、重置订阅 token、重置密码
- **权限组**：分组控制可用节点（XrayR 靠 `group_ids` 过滤用户）
- **节点**：vmess / vless / shadowsocks，含 REALITY、multiplex、utls、ECH、路由规则、倍率
- **订阅**：全部客户端格式保留（General / Clash / ClashMeta / SingBox / Surge / Surfboard / Loon / QuantumultX / Shadowrocket / Stash / Shadowsocks）
- **XrayR 对接**：`config` / `user` / `push` 三条链路 + ETag/304
- **流量重置**：`TrafficResetService`（Plan 常量已迁移进来，改为纯系统设置驱动）
- **后台管理 API**：config / server(group,route,manage) / user / system / plugin / traffic-reset

---

## 四、回归验证（Debian 12 虚拟机 10.0.0.131）

环境：PHP 8.2.34 + SQLite + Redis 7.0.15，`php artisan serve`

| 验证项 | 结果 |
|---|---|
| `GET /api/v1/server/UniProxy/config`（vmess+WS） | ✅ 200，返回 `network:ws` + `networkSettings.path/headers` |
| `GET /api/v1/server/UniProxy/config`（vless+REALITY） | ✅ 200，返回 `tls:2` + `tls_settings`（含 private_key / short_id / server_name / server_port） |
| `GET /api/v1/server/UniProxy/config`（ss2022） | ✅ 200，返回 `cipher` + `server_key` |
| `GET /api/v1/server/UniProxy/user` | ✅ 200，返回 users[] |
| `POST /api/v1/server/UniProxy/push` | ✅ 200，`TrafficFetchJob` 落库 **u=1024 d=2048**（与推送值一致） |
| `/status` `/alive` `/alivelist` | ✅ 已删除，返回 404 |
| 订阅接口（Clash） | ✅ vmess 节点含 `ws-opts.path` + `headers.Host` |
| 订阅接口（通用 base64） | ✅ 三种节点（vmess / vless+reality / ss2022）均正确 |
| SS2022 密钥派生一致性 | ✅ 面板 `base64(uuid[:16])` == XrayR `base64(password[:16])` |
| Laravel 错误日志 | ✅ 无 |

---

## 五、精简过程中修掉的关键问题

1. **`User::isActive()` 依赖 `plan_id`** —— 删除 Plan 后若不改，所有无套餐用户的订阅都会被拒（403）。已改为只校验封禁与到期时间。
2. **`TrafficResetService` 深度依赖 Plan 常量** —— 已把 `RESET_TRAFFIC_*` 常量迁移到该类，流量重置改为纯 `admin_setting('reset_traffic_mode')` 驱动。
3. **邮件模块牵连 Auth 流程** —— `SendEmailJob` 被 `MailLinkService`（token 登录）与 Passport 控制器引用。已保留 `handleTokenLogin`，仅移除邮件发送逻辑。
4. **`V1/User/CommController` 引用已删的 `Payment` 模型** —— 已移除 `getStripePublicKey`。
5. **`UserFactory` 引用 `App\User`**（不存在的命名空间）—— 已修正为 `App\Models\User`。
6. **`Server::VALID_TYPES` 被 `Protocols/*` 间接依赖** —— 收窄时保留了全部 `TYPE_*` 常量，只改 `VALID_TYPES` 数组。

---

## 六、未处理 / 需注意

| 项 | 说明 |
|---|---|
| `database/migrations/` | **未动**（历史迁移链，删除会破坏迁移完整性）；无用表保留在库中 |
| 前端 dist | `theme/Xboard` 与 `public/assets/admin` 已删，首页改为存活探测。后台管理只能走 API（curl / 脚本） |
| 插件系统骨架 | 保留（`HookManager` 被 25+ 处引用，删除需改大量调用点）。`plugins-core/` 已清空，等于空转 |
| `composer.lock` | **未重新生成**。本地 `composer install` 仍会拉取旧 lock 里的多余包；如需彻底精简，在虚拟机上跑 `composer update --no-dev` |
| 队列 | `TrafficFetchJob` 走 `traffic_fetch` 队列，需要常驻 worker（`php artisan queue:work` 或 Horizon） |
| 邮件提醒 | 已移除，收不到到期/流量提醒邮件 |

---

## 七、虚拟机上的测试环境（可复用）

```
主机：aihelper-vm130（10.0.0.131）
路径：/root/xboard
环境：PHP 8.2.34 + SQLite(database/database.sqlite) + Redis 7.0.15
管理后台地址：http://127.0.0.1:8000/d673bafd
管理员：admin@test.com
节点 token：testtoken123
测试节点：1=vmess+ws  2=vless+reality  3=ss2022
测试用户：u1@test.com（id=2，group_id=1）
启动：cd /root/xboard && php artisan serve --host=127.0.0.1 --port=8000
队列：php artisan queue:work redis --queue=traffic_fetch
```
