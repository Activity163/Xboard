# Xboard 精简清单（对接本项目定制版 XrayR，个人自用）

> 分析日期：2026-10-16
> 后端：`D:\项目\XrayR`（定制版，`panelName = "Xboard"`，仅支持 Xboard 一种面板）
> 面板：`D:\项目\Xboard`（Laravel 12，v2board 分支）
> 说明：本文件只做「分析 + 列清单」，**未执行任何删改**。

---

## 0. 结论速览

- 本项目的 XrayR **只调用 3 个接口**（不是标准版的 6 个），`/status`、`/alive`、`/alivelist` **完全不会调用**。
- 依据：`panel/registry.go` 里 Xboard 适配器声明的能力只有
  `{Rules: true, TrafficReport: true, Shadowsocks2022KeyDerivation: true}`，
  `NodeStatusReport / OnlineUserReport / IllegalReport` 全部为 `false`；
  `api/newV2board/v2board.go` 里 `ReportNodeStatus` / `ReportNodeOnlineUsers` / `ReportIllegal` 三个方法**直接 `return nil`**（空实现）。
- 因此除了上一版清单外，**还能再删一整套「节点状态 / 在线设备」链路**（含 `DeviceStateService` 207 行）。
- 个人自用可删 `app/` 约 **65%~75%**，外加 `plugins-core/` 全部、20+ 张 DB 表、多个 composer 依赖。

---

## 1. XrayR 实际对接层（**必须保留，且只需这些**）

### 1.1 接口清单（3 个）

| 操作 | API 端点 | 方法 | XrayR 代码位置 |
|---|---|---|---|
| 拉节点配置 | `/api/v1/server/UniProxy/config` | GET | `GetNodeInfo()` |
| 拉用户列表 | `/api/v1/server/UniProxy/user` | GET | `GetUserList()` |
| 上报用户流量 | `/api/v1/server/UniProxy/push` | POST | `ReportUserTraffic()` |

- 请求统一带 query 参数：`node_id`、`node_type`、`token`。
- `config` 与 `user` 支持 **ETag / If-None-Match → 304**，XrayR 侧已实现，Xboard 侧已实现，**这条要保留**。
- `push` 的 body 结构：`{ "uid1": [upload, download], "uid2": [u, d] }`，与 `ServerService::processTraffic` 的解析逻辑一致。

### 1.2 能力矩阵（来自 `panel/registry.go` + `api/api.go`）

| 能力 | 是否启用 | 对应 Xboard 接口 |
|---|---|---|
| 审计规则 Rules | ✅ | 无独立接口，**复用 `config` 返回的 `routes` 字段** |
| 流量上报 TrafficReport | ✅ | `POST /UniProxy/push` |
| SS2022 密钥派生 | ✅ | `config` 的 `server_key` 字段 |
| 节点状态 NodeStatusReport | ❌ | ~~`POST /UniProxy/status`~~ **不调用** |
| 在线用户 OnlineUserReport | ❌ | ~~`POST /UniProxy/alive`~~ **不调用** |
| 非法记录 IllegalReport | ❌ | ~~无~~ **不调用** |

> **推论**：面板端的「节点负载状态」「在线设备/设备限制名单」对 XrayR **没有任何作用**。
> XrayR 的 `DeviceLimit` 是**本地配置项**（`u.DeviceLimit = c.DeviceLimit`，源码注释写着 `todo waiting v2board send configuration`），不从面板拉取。

### 1.3 必须保留的文件

```
app/Http/Controllers/V1/Server/UniProxyController.php   # 只保留 config() / user() / push()
app/Http/Middleware/Server.php                          # token / node_id / node_type 校验
app/Http/Routes/V1/ServerRoute.php                      # 只保留 UniProxy 组，且只留 3 条路由
app/Services/ServerService.php                          # 只保留 buildNodeConfig / getAvailableUsers
                                                        #   / processTraffic / touchNode / getRoutes / getServer
app/Services/UserService.php                            # trafficFetch（流量落库）
app/Models/{Server, User, ServerGroup, ServerRoute}.php
app/Utils/{CacheKey, Helper}.php
app/Http/Middleware/{ApplyRuntimeSettings, ForceJson, Language}.php   # api 全局组
```

### 1.4 `config` 接口被 XrayR 实际读取的字段

XrayR 的 `serverConfig`（`api/newV2board/model.go`）只解析这些：

```
server_port
base_config.push_interval / pull_interval   # 解析了但未被使用（见 §5.2）
routes[].{id, match, action, action_value}  # 审计规则 + DNS 规则
cipher / obfs / obfs_settings.{path,host} / server_key    # Shadowsocks
network / networkSettings.{path,host,headers,serviceName,header}   # V2ray（驼峰！）
network_settings.{...} / flow / tls / tls_settings.{server_port,dest,xver,server_name,private_key,short_id}   # Vless
host / server_name                          # Trojan
```

对应 Xboard 的 `ServerService::buildNodeConfig()`。**注意 `networkSettings` 是驼峰，`network_settings` 是下划线，两者含义不同 → 见 §5.1 的坑。**

---

## 2. 相比上一版，**新增可删**的部分

因为 XrayR 不调 `/status`、`/alive`、`/alivelist`：

| 删除目标 | 位置 | 说明 |
|---|---|---|
| `status()` / `alive()` / `alivelist()` 三个方法 | `V1/Server/UniProxyController.php` | 直接删，路由一并删 |
| 三条路由 | `V1/ServerRoute.php` | `status` / `alive` / `alivelist` |
| **`DeviceStateService.php`（207 行）** | `app/Services/` | 整个删除，仅被 alive/alivelist 与 WebSocket 使用 |
| `processAlive()` / `processStatus()` / `processOnline()` / `updateMetrics()` | `ServerService.php` | 约 90 行 |
| `load_status` / `metrics` / `online` / `online_conn` 访问器 | `Models/Server.php` | 后台「节点负载」展示用，可删 |
| `SERVER_*_LOAD_STATUS` / `*_METRICS` 缓存键 | `Utils/CacheKey.php` | 顺带清理 |
| `getMachineNodes()` / `machine()` 关系 | `ServerService.php` / `Models/Server.php` | XrayR 已移除 `MachineID`（见 §5.4） |

> ⚠️ 但后台管理界面（React dist）可能有「节点负载」图表，删了后端接口后该页面会空/报错。个人自用可忽略，或前端一并改。

---

## 3. 建议直接删除（个人自用完全用不到）

### 3.1 交易 / 商业化（约 2000+ 行）
| 模块 | 主要文件 |
|---|---|
| 订单 | `Services/OrderService.php`(496) · `V1/User/OrderController.php`(215) · `V2/Admin/OrderController.php`(252) · `Requests/User/OrderSave.php` · `Requests/Admin/Order*.php` · `Console/Commands/CheckOrder.php` · `Jobs/OrderHandleJob.php` · `Models/Order.php` |
| 支付 | `Services/PaymentService.php`(135) · `V1/Guest/PaymentController.php` · `V2/Admin/PaymentController.php` · `Contracts/PaymentInterface.php` · `Models/Payment.php` · **`plugins-core/` 全部支付插件** |
| 套餐购买 | `Services/PlanService.php`(194) —— ⚠️ **权限组 group 必须保留**，只砍「下单/购买/自动开通」 |
| 优惠券 | `Services/CouponService.php`(122) · `V1/User/CouponController.php` · `V2/Admin/CouponController.php` · `Models/Coupon.php` |
| 礼品卡 | `Services/GiftCardService.php`(353) · `Models/{GiftCardCode(259), GiftCardTemplate(253), GiftCardUsage(111)}` · `V1/User/GiftCardController.php`(193) · `V2/Admin/GiftCardController.php`(622) |
| 邀请返佣 | `V1/User/InviteController.php` · `Models/CommissionLog.php` · `Console/Commands/CheckCommission.php` · User 表 `commission_*` 字段（可留字段删逻辑） |

### 3.2 运营 / 客服
`Services/TicketService.php`(109) · `V1+V2/TicketController` · `Models/{Ticket, TicketMessage}` · `CheckTicket` · `V1+Admin/KnowledgeController` · `Models/Knowledge` · `V1+Admin/NoticeController` · `Models/Notice`

### 3.3 通知 / 外部集成
| 模块 | 文件 |
|---|---|
| 邮件 | `Services/MailService.php`(342) · `V2/Admin/MailTemplateController.php`(266) · `Models/{MailLog, MailTemplate}` · `Jobs/SendEmailJob.php` · `Console/Commands/SendRemindMail.php` · `resources/views/mail/*` |
| Telegram | `Services/TelegramService.php`(160) · `V1/Guest+User/TelegramController` · `Jobs/SendTelegramJob.php` · `plugins-core/Telegram/` · `CheckServer` 内 TG 告警 |
| 验证码 | `Services/CaptchaService.php`(111) —— 关掉注册后直接删 |

### 3.4 部署 / 运维（与 XrayR 无关的 Xboard 自有体系）
| 模块 | 文件 |
|---|---|
| machine 机器 | `V2/Admin/Server/MachineController.php`(215) · `V2/Server/{MachineController, ServerController}` · `Models/{ServerMachine, ServerMachineLoadHistory}` · `Services/{NodeRegistry(170), NodeSyncService(184)}` · `Jobs/NodeUserSyncJob.php` |
| WebSocket | `app/WebSocket/*` · `Console/Commands/NodeWebSocketServer.php` · `Middleware/ServerV2.php`（整个可删，XrayR 只用 `Middleware/Server.php`） |
| 自动更新 | `Services/UpdateService.php`(457) · `Console/Commands/{XboardUpdate, XboardRollback, MigrateFromV2b}.php` · `V2/Admin/UpdateController.php` |
| 数据库备份 | `Console/Commands/BackupDatabase.php` · 依赖 `spatie/db-dumper` |
| 统计报表 | `Services/StatisticalService.php`(378) · `V2/Admin/StatController.php`(508) · `V1/User/StatController.php` · `Console/Commands/XboardStatistics.php` · `Jobs/{StatServerJob, StatUserJob}` · `Models/{Stat, StatServer, StatUser}` |
| 系统状态 | `V2/Admin/SystemController.php` · 依赖 `linfo/linfo` · `Models/AdminAuditLog`（审计日志，可选留） |

### 3.5 插件系统（约 1500 行）
`Services/Plugin/PluginManager.php`(782) · `AbstractPlugin.php`(221) · `HookManager.php`(285) · `Middleware/InitializePlugins.php` · `V2/Admin/PluginController.php`(345) · `Providers/PluginServiceProvider.php` · `Models/Plugin.php` · `plugins-core/*`

> **⚠️ 高耦合**：`HookManager` 被 **25+ 文件**引用，含 XrayR 主链路里的
> `ServerService::getAvailableUsers()`（`HookManager::filter('server.users.get')`）。
> **稳妥做法**：保留插件骨架，只删 `plugins-core/` 里的业务插件。

### 3.6 多主题 / 前端
`Services/ThemeService.php`(424) · `V2/Admin/ThemeController.php`(150)
> `theme/Xboard/`（umi.js）与 `public/assets/admin/`（git 子模块 dist）都是**编译产物**，不是 PHP 源码，无法"精简源码"，只能整体保留或替换。

### 3.7 协议输出格式（订阅）
`app/Protocols/` 共 4858 行。按你实际用的客户端裁：

- **保留**：`General.php`(472，通用 base64) + 你要用的客户端格式（如 `Clash.php` / `ClashMeta.php` / `SingBox.php`）
- **可删**：`Loon`(389) · `QuantumultX`(260) · `Shadowrocket`(460) · `Stash`(587) · `Surfboard`(229) · `Surge`(319) · `Shadowsocks`(60)
- 同步改：`Providers/ProtocolServiceProvider.php` 与 flag 映射。

> 注意：这里删的是**给客户端用的订阅格式**，与 XrayR 无关，XrayR 的 `config` 接口不经过 `Protocols/`。

---

## 4. 可选保留

| 功能 | 文件 | 判断 |
|---|---|---|
| 流量重置 | `Services/TrafficResetService.php`(414) · `ResetTraffic.php`(288) · `Models/TrafficResetLog` | 需要"周期重置流量"就留 |
| 限速 / 倍率 / 时段倍率 | `Server` 表 `rate*` 字段 | 个人用可留可删（注意：XrayR 的 `SpeedLimit` 本地配置会**覆盖**面板下发值） |
| 多语言 | `resources/lang/*.json` | 只留 `zh-CN` |
| 队列 / Horizon | `config/horizon.php` · `laravel/horizon` | 砍订单/邮件/统计后队列基本无用，可改 sync 队列并删 Horizon |
| IP 归属 | `zoujingli/ip2region` | 个人可留（占用小） |

---

## 5. 已知对接坑（**改动前必读**）

### 5.1 ⚠️ VLESS + `network_settings` 字段名不匹配（最可能的真实故障）
- XrayR 的 `vless` 分支：`if c.EnableVless { s.NetworkSettings = s.VlessNetworkSettings }`
  → 即 **VLESS 节点会用 `network_settings`（下划线）覆盖驼峰字段**。
- Xboard 的 `buildNodeConfig()` 只下发 **`networkSettings`（驼峰）**，vless 分支**没有**补 `network_settings`。
- 后果：`EnableVless: true` 且节点用 `ws` / `httpupgrade` / `splithttp` 时，XrayR 读到空的 `network_settings`
  → `Path` / `Host` / `Headers` 全部丢失 → **连接失败**。
- **缓解**：在 Xboard 的 vless 分支额外下发一份 `'network_settings' => $protocolSettings['network_settings']`；
  或不用 VLESS（改用 Vmess），Vmess 走驼峰字段是正常的。

### 5.2 `base_config` 实际无效
`config` 返回的 `base_config.{push_interval,pull_interval}` 被 XrayR 解析进 `serverConfig` 后**没有被使用**，
同步节奏由 XrayR 侧的 `ControllerConfig.UpdatePeriodic`（默认 60s）控制。面板设置这两项不会改变 XrayR 行为。

### 5.3 REALITY 依赖 `tls_settings` 而非 `reality_settings`
- XrayR 只读 `tls_settings.{server_port,dest,xver,server_name,private_key,short_id}`。
- Xboard 在 `tls == 2` 时**把 `reality_settings` 的内容塞进 `tls_settings` 字段下发** → 恰好对得上。
- 但 `reality_settings` 里没有 `dest` / `xver` 字段，XrayR 会回退用 `server_name` 当 dest → 通常可用。
- 若要精确控制，面板侧需补 `dest` / `xver`。

### 5.4 machine 体系已被 XrayR 官方移除
`config/migrate.go` 明确处理：`ApiConfig.MachineID` 已废弃，会报
`Xboard now uses the stable UniProxy REST adapter; remove MachineID`。
→ 面板端的 `ServerMachine` / `/api/v2/server/handshake` / WebSocket 对 XrayR **完全无用**，可放心删。

### 5.5 其余已确认兼容
- ETag / 304：两侧都实现 ✓
- `push` 的 `{uid: [u,d]}` 结构：两侧一致 ✓
- SS2022：面板下发 `server_key`，XrayR 侧 `Shadowsocks2022KeyDerivation = true` ✓
  （面板 `Server::generateServerPassword()` 返回的 `serverKey:userKey` 是给**订阅**用的，XrayR 不读它，XrayR 自行从 uuid 派生 user key）

---

## 6. 依赖与数据库精简

### 6.1 composer 可删
`stripe/stripe-php` · `google/cloud-storage` · `google/recaptcha` · `symfony/mailgun-mailer` · `spatie/db-dumper` · `linfo/linfo` · `laravel/horizon` · `php-curl-class`（视用途）

**保留**：`laravel/framework` · `laravel/sanctum` · `laravel/octane` · `symfony/yaml` · `zoujingli/ip2region` · `bacon/bacon-qr-code` · `doctrine/dbal`

### 6.2 可删表
`v2_order` · `v2_payment` · `v2_coupon` · `v2_gift_card_code` · `v2_gift_card_template` · `v2_gift_card_usage` · `v2_invite_code` · `v2_ticket` · `v2_ticket_message` · `v2_knowledge` · `v2_notice` · `v2_commission_log` · `v2_mail_log` · `v2_mail_template` · `v2_plugins` · `v2_subscribe_templates` · `v2_server_machine` · `v2_server_machine_load_history` · `v2_stat` · `v2_stat_server` · `v2_stat_user` · `v2_server_log` · `v2_traffic_reset_log`(视需求) · `v2_admin_audit_log`(可选)

**保留核心表**：`v2_user` · `v2_server` · `v2_server_group` · `v2_server_route` · `v2_settings` · `v2_plan`（不用套餐可删，但牵连较多）

> **建议不动 `database/migrations/`**（历史迁移链，删了会破坏完整性）。只删 Model + 控制器 + 路由即可。

---

## 7. 高耦合风险点

1. `HookManager` / `PluginManager` 散布 25+ 文件 → 砍插件要全局替换调用点。
2. `ServerService::getAvailableUsers()` 内有 `HookManager::filter('server.users.get', ...)` → **XrayR `/user` 主链路**，必须处理。
3. `ClientController::subscribe()` 内有 `HookManager::call('client.subscribe.before')` → 必须处理。
4. `Support/AbstractProtocol.php` 依赖 HookManager → 砍插件要改协议基类。
5. `UserService::trafficFetch()` 引用 `TrafficFetchJob` / `StatServerJob` / `StatUserJob` → 砍统计要改**流量落库逻辑（XrayR push 的落点）**。
6. `Observers/{UserObserver, ServerObserver, ServerRouteObserver, PlanObserver}` 内有 `NodeUserSyncJob` / `TrafficResetService` 调用 → 砍 machine/reset 要改 Observer。
7. `routes/web.php` 硬依赖 `ThemeService` / `UpdateService`（首页渲染 + 版本号）→ 砍主题/更新要改首页路由。
8. `Providers/RouteServiceProvider.php` 用 `glob()` 自动加载 V1/V2 路由文件 → **删路由文件即自动生效**（好改）。
9. 后台前端是独立 dist 子模块 → 后端路由删了前端菜单仍在，需单独处理或忽略。

---

## 8. 建议的精简顺序

1. **先删 `plugins-core/*`**（支付插件）—— 零耦合，最安全。
2. **收窄 XrayR 接口层**：`UniProxyController` 只留 `config/user/push`，删 `status/alive/alivelist` + `DeviceStateService` + `ServerService` 的状态方法。
3. 删 `routes/` + 控制器 + Requests（order / payment / coupon / giftcard / ticket / knowledge / notice / telegram）。
4. 删对应 `Services` + `Models` + `Jobs` + `Console/Commands`。
5. 处理 `HookManager` 调用点（或保留插件骨架、只删业务插件）。
6. 删 machine / WebSocket / Update / Stat 体系。
7. 裁 `Protocols/` + 语言包 + composer 依赖。
8. 最后清 DB 表，跑回归。

**回归验证（只需这 3 条）**：
```
GET  /api/v1/server/UniProxy/config?node_id=1&node_type=vmess&token=xxx   → 200 + 正确 JSON
GET  /api/v1/server/UniProxy/user?node_id=1&node_type=vmess&token=xxx     → 200 + users[]
POST /api/v1/server/UniProxy/push?node_id=1&node_type=vmess&token=xxx     → 200
```
再用 XrayR 侧 `XrayR config check` + `XrayR doctor` 做启动前校验。

---

## 9. 精简后的目标形态

- **用户**：手动增删改、改流量/到期/限速、封禁、重置订阅 token
- **权限组**：分组控制可用节点（XrayR 靠 `group_ids` 过滤用户）
- **节点**：增删改、协议配置（vmess / vless / trojan / shadowsocks）、倍率、路由（审计规则）、分组
- **订阅**：多客户端格式生成（按需保留）、二维码
- **XrayR 对接**：`config` / `user` / `push` 三条链路 + ETag
- **后台**：用户 / 节点 / 分组 / 设置
