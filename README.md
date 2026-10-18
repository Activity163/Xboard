# Xboard 精简版

> 基于 [cedar2025/Xboard](https://github.com/cedar2025/Xboard) 的**个人自用**版本。
> 只保留 XrayR 对接与节点 / 用户管理，删掉了全部商业与运营模块。

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![Laravel](https://img.shields.io/badge/Laravel-12-red.svg)
![License](https://img.shields.io/badge/License-MIT-yellow.svg)

</div>

---

## 目录

- [为什么精简](#为什么精简)
- [部署](#部署)
- [装完之后](#装完之后)
- [内部机制](#内部机制)
- [运行时](#运行时)
- [目录结构](#目录结构)
- [踩坑](#踩坑)
- [回滚](#回滚)

## 为什么精简

上游 Xboard 是一套完整的机场系统：套餐、订单、支付、优惠券、工单、返佣、多主题、插件市场。
自用场景下一个都用不上，反而带来三个负担：

1. **攻击面** —— 支付回调、优惠券核销这些接口全都要跟着修漏洞
2. **部署成本** —— 依赖多、镜像大、首次配置要填一堆运营参数
3. **认知成本** —— 只想加个节点，得先搞懂套餐和订单的关系

这里只留「一个人管几台机器几个用户」真正需要的部分。

### 规模对比

| 指标 | 原版 | 本版 |
|---|---|---|
| `app/` 代码行数 | 31,098 | **16,864**（−46%） |
| PHP 文件数 | 400+ | **131** |
| 节点协议 | 11 种 | **3 种** |
| XrayR 接口 | 6 个 | **3 个** |
| 控制器 | 40+ | **22** |

### 保留

| 模块 | 说明 |
|---|---|
| **XrayR 对接** | `config` / `user` / `push` 三条链路，支持 ETag / 304 |
| 节点管理 | vmess / vless（REALITY、flow、encryption）/ shadowsocks（含 2022 系列） |
| 权限组 | 控制哪些用户能用哪些节点 |
| 路由管理 | 节点级分流规则 |
| 用户管理 | 流量 / 到期 / 限速 / 设备数 / 封禁 / 重置订阅 |
| 订阅下发 | `/s/{token}`，按 UA 适配 **11 种**客户端格式 |
| 后台面板 | React dist（菜单已裁剪，见[后台](#后台菜单)） |
| 定时任务 | 流量重置 / 日志清理 / 在线状态清理 |

### 移除

| 类别 | 内容 |
|---|---|
| 交易 | 订单 · 支付 · 优惠券 · 礼品卡 · 邀请返佣 |
| 运营 | 工单 · 知识库 · 公告 |
| 通知 | 邮件（含邮箱验证、邮件链接登录）· Telegram 机器人 |
| 统计 | 收入统计 · 用户流量日志 · 节点负载历史 |
| 运维 | 在线更新 · 数据库备份 · 多主题切换 |
| 基础设施 | WebSocket 节点通道 · 机器节点集群 · 流量审计队列 · 设备状态上报 |
| 协议 | trojan · hysteria · tuic · anytls · socks · naive · http · mieru |
| 依赖 | stripe · google-cloud-storage · workerman · spatie-db-dumper · ip2region · linfo 等 |

> 逐批次的精简过程见 [`docs/slimming-report.md`](docs/slimming-report.md)。

---

## 部署

全程在**服务器**上完成，不需要本地克隆。容器镜像由 GitHub Actions 预构建
（`ghcr.io/activity163/xboard:latest`，amd64 / arm64），服务器不现场编译。

### 1. 拉代码

```bash
apt update && apt install -y git
git clone https://github.com/Activity163/Xboard.git
cd Xboard
```

### 2. 安装

```bash
touch .env
docker compose -f compose.image.yaml run --rm \
  -e ENABLE_SQLITE=true \
  -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=admin@example.com \
  xboard php artisan xboard:install
```

安装程序会依次完成：生成 `APP_KEY` → 建表 → 初始化 `server_token` →
创建默认订阅 → 创建管理员（并分配该订阅）→ 装默认插件。

**输出里有两样东西只打印一次，当场记下来**：管理员密码、后台路径。

> ⚠️ **不要加 `-it`**。远程 SSH 没有 TTY，会报
> `cannot attach stdin to a TTY-enabled container`。

> ⚠️ **`.env` 必须存在且为空**。安装程序要往这个文件写配置，Docker 方式下不能删掉它。

### 3. 启动

```bash
docker compose -f compose.image.yaml up -d
docker compose -f compose.image.yaml logs -f xboard
```

看到这三行说明起来了：

```
octane_00   entered RUNNING state
horizon_00  entered RUNNING state
redis_00    entered RUNNING state
```

同时会打印一行自动调优结果，可用来确认进程数：

```
[entrypoint] Auto-tune (profile=balanced): cpus=2 mem=1024MiB slots=9 -> octane=2 horizon=2 horizon_worker_mem=256MB
```

### 4. 配置对外地址

```bash
sed -i 's#^APP_URL=.*#APP_URL=https://你的域名#' .env
docker compose -f compose.image.yaml restart xboard
```

`APP_URL` 会影响订阅链接、回调地址，必须是对外可访问的地址。

### 反向代理

仓库自带的 `compose.image.yaml` 用的是**外挂 Caddy** 方案，适合宿主机上已经有
Caddy / Nginx 的情况：

```
浏览器 → 宿主机 Caddy → xboard:7001
```

它的关键配置是 `ENABLE_CADDY=false` + 接入外部 Docker 网络：

```yaml
networks:
  - nezha-dashboard_default   # 改成你自己的 external 网络名
environment:
  - ENABLE_CADDY=false        # Octane 直接绑 0.0.0.0:7001
```

外挂 Caddy 侧：

```caddyfile
你的域名 {
	encode zstd gzip
	reverse_proxy xboard:7001 {
		header_up Host {host}
		header_up X-Forwarded-Proto {scheme}
	}
}
```

> 两个容器必须在同一 Docker 网络里，Caddy 才能用容器名 `xboard` 解析。
> `external: true` 表示复用既有网络而不是新建。

**想用镜像内置 Caddy**（单机最简单）：删掉 `networks` 整段，`ENABLE_CADDY` 改 `true`。
此时 Caddy 占 7001，Octane 自动退到 `127.0.0.1:7002`，宿主机只需把域名指过来。

**想直接 IP:端口访问**：删掉 `networks` 段，保持 `ENABLE_CADDY=false`，
然后访问 `http://服务器IP:7001`。

---

## 装完之后

> 下面几段查询命令按 **SQLite 部署**写（`/www/.docker/.data/database.sqlite`）。
> 用 MySQL / PostgreSQL 的话，把 `docker exec ... php -r` 换成对应客户端即可，SQL 本身通用。

### 找回后台路径

后台路径**不落盘**，只在那一次安装输出里出现。它等于 `crc32b(APP_KEY)`，随时可重算：

```bash
docker exec xboard php /www/artisan tinker --execute='echo hash("crc32b", config("app.key"));'
```

> 别用 `php -r`，那个入口不加载 Laravel，`config()` 会报 undefined function。

访问 `https://你的域名/<这串哈希>`。

### 加节点

后台 **节点管理 → 节点管理 → 添加节点**。

新建节点时如果没勾权限组，会自动加入**全部现有权限组** —— 否则节点建完对任何用户
都不可见，XrayR 拉不到用户。编辑时手动清空则尊重你的选择。

### 分配订阅

后台 **用户管理 → 编辑用户 → 订阅**，下拉选「章鱼哥专属」并保存。

安装时已经把这个订阅分配给管理员了，正常情况**不用再动**。
原理见[内部机制 · 订阅只是模板](#订阅plan只是模板)。

### 对接 XrayR

节点服务器上部署 [XrayR](https://github.com/XrayR-project/XrayR)，配置：

```yaml
PanelType: "Xboard"
ApiConfig:
  ApiHost: "https://你的域名"
  ApiKey: "后台【系统配置】里的通讯密钥（server_token）"
  NodeID: 1
  NodeType: vmess        # vmess / vless / shadowsocks
```

`ApiKey` 就是安装时自动生成的那个 `server_token`。忘了可以查：

```bash
docker exec xboard php -r '
$d = new PDO("sqlite:/www/.docker/.data/database.sqlite");
foreach ($d->query("select value from v2_settings where name=\"server_token\"") as $r) echo $r["value"], "\n";'
```

面板侧实现三个接口（`app/Http/Controllers/V1/Server/UniProxyController.php`）：

| 接口 | 作用 |
|---|---|
| `GET /api/v1/server/UniProxy/config` | 下发节点配置（REALITY / multiplex / 路由规则） |
| `GET /api/v1/server/UniProxy/user` | 下发该节点的可用用户列表 |
| `POST /api/v1/server/UniProxy/push` | 接收流量上报，入队后落到 `v2_user.u` / `.d` |

前两个支持 `ETag` / `304`，XrayR 轮询时不会重复传输。

### 订阅链接

`https://你的域名/s/<用户 token>`，token 在用户编辑页能看到，或：

```bash
docker exec xboard php -r '
$d = new PDO("sqlite:/www/.docker/.data/database.sqlite");
foreach ($d->query("select email, token from v2_user") as $r) echo $r["email"], " -> /s/", $r["token"], "\n";'
```

按 UA 自动适配格式：`General` · `Clash` · `ClashMeta` · `SingBox` · `Surge` ·
`Surfboard` · `Loon` · `QuantumultX` · `Shadowrocket` · `Stash` · `Shadowsocks`。

---

## 内部机制

读懂这几条，出问题基本能自己定位。

### 用户能不能连上节点，只看四个字段

`ServerService::getAvailableUsers()` 是 XrayR `user` 接口的唯一数据源：

```php
whereIn('group_id', $node->group_ids)                    // 权限组要包含该节点
whereRaw('u + d < transfer_enable')                      // 没跑超流量
where(fn ($q) => $q->where('expired_at', '>=', time())
                    ->orWhere('expired_at', null))       // 没过期
where('banned', 0)                                       // 没被封
```

**`plan_id` 一次都不出现。** 四个条件任一不满足，节点就看不到这个人，
表现为「面板里有用户、XrayR 日志也正常、但客户端就是连不上」。

### `expired_at`：`0` 和 `NULL` 不是一回事

| 值 | 含义 | 能否连上 |
|---|---|---|
| `NULL` | 永久有效（`orWhere(... , null)` 会转成 `IS NULL`） | ✅ |
| `0` | **已过期**（`0 >= time()` 为假，且不是 NULL） | ❌ |
| 未来时间戳 | 到期前有效 | ✅ |

新建用户如果这个字段是 `0`，会被判定为已过期。分配订阅会自动清成 `NULL`。

### 订阅（plan）只是模板

订阅管理页已经删了，但「分配订阅」这个动作保留 —— 它是用户拿到 `group_id` 的**唯一途径**
（用户表单里没有 `group_id` 字段，`UserUpdate` 也不收它）。

`v2_plan` 提供的是一组默认值，分配时被抄到用户行上：

| 套餐字段 | 落到用户字段 |
|---|---|
| `group_id` | `v2_user.group_id` |
| `transfer_enable` | `v2_user.transfer_enable` |
| `speed_limit` / `device_limit` | 同名字段 |
| （套餐自身不带到期时间概念） | `v2_user.expired_at = NULL` |

逻辑在 `app/Observers/UserObserver.php` 的 `applyPlan()`，`plan_id` 变更时自动触发。

安装时幂等创建一条默认订阅：

| 字段 | 值 |
|---|---|
| 名称 | 章鱼哥专属 |
| 流量 | 100 TB（`109951162777600` 字节） |
| 权限组 | 库里第一个权限组（没有则建「默认分组」） |
| 重置方式 | `2` = 不重置 |
| 到期 | 无 |

想加新套餐直接往 `v2_plan` 插行，字段参考 `app/Models/Plan.php` 的注释。

> ⚠️ `plan.reset_traffic_method` 目前**只是标记**。
> `TrafficResetService` 读的是全局设置 `reset_traffic_method`，
> 真正让流量不重置的是 `expired_at = NULL`（该方法里 `expired_at === null` 直接返回）。

### 为什么留着 `StubController`

前端 dist 编译自上游完整版，硬编码了约 90 个**已删模块**的接口。
其中 `/server/machine/fetch` 在**节点管理页**（保留页）被以 60 秒间隔轮询。

所以 `StubController` + `AdminRoute.php` 末尾的通配路由必须保留，否则后台每 60 秒刷一次 404。

- 列表类 action → `[]`
- 写操作类 → `true`
- 其余 → `{}`

> ⚠️ 路由定义**不能同路径写两条**，Laravel 后者会覆盖前者。
> 具体路由（如 `plan/fetch`）必须写在通配 stub **之前**。

### 后台菜单

侧边栏只留三组，其余入口在构建镜像时就从前端 dist 里摘掉了：

```
系统管理 → 系统配置
节点管理 → 节点管理 / 权限组管理 / 路由管理
用户管理 → 用户管理
```

原来有但已移除：仪表盘 · 订阅管理 · 工单管理 · 服务器管理 · 插件管理 ·
主题配置 · 公告管理 · 支付配置 · 知识库管理。

访问 `/` 会自动重定向到「节点管理」。

### 前端是产物级手术，不是源码重建

`public/assets/admin/` 是上游编译好的 dist（混淆单文件 `index-*.js`），
**前端源码不在本仓库**。改菜单只能对 dist 做外科手术：

```bash
python strip_admin_ui.py            # dry-run，预览变更
python strip_admin_ui.py --apply    # 执行，自动备份 .bak-menu-removal
```

脚本原理（两个变量名是混淆后的固定值，换 dist 可能要重新确认）：

| 变量 | 含义 |
|---|---|
| `Vlt` | 菜单数组 |
| `zQe` | 路由树（`const HQe=(zQe=[...`） |

改菜单时**必须同时删路由**，否则直接敲 URL 还能进。
根 `index:!0`（默认落地页）必须改成 `<Navigate>` 重定向，直接删会白屏。
每次替换前都做 `count == 1` 唯一性断言。

---

## 运行时

### 三个常驻进程

由 supervisord 管理（`.docker/supervisor/supervisord.conf`），缺一不可：

| 进程 | 作用 | 关掉会怎样 |
|---|---|---|
| `octane:start` | HTTP 服务（PHP 常驻）+ 定时任务调度 | 站点 502，调度也停 |
| `horizon` | 队列消费（流量落库） | 流量不上报，用户流量永远 0 |
| `redis-server` | 缓存 + 队列 backend | 全局报错 |

检查：

```bash
docker exec xboard ps -o args | grep -oE 'octane:start|horizon|redis-server' | sort -u
```

> `ps` 是 BusyBox 版，**不支持** `aux --sort`，只能用 `ps -o args`。

启动后内存占用约 **350–400 MiB**（2 核 / 2 GB 机器，`RESOURCE_PROFILE=balanced`）。

### 定时任务

**由 Octane 的 tick 驱动，不要再挂 `schedule:work` 或配 cron。**

`app/Providers/OctaneServiceProvider.php` 里注册了一个 30 秒的 Swoole 定时器：

```php
Octane::tick('scheduler', function () {
    $lock = Cache::lock('scheduler-lock', 30);
    if ($lock->get()) {
        try { Artisan::call('schedule:run'); } finally { $lock->release(); }
    }
})->seconds(30);
```

所以定时任务是**进程内**跑的，`ps` 里看不到独立的调度进程 —— 别据此判断它没工作。
真要验证，抓 Redis 命令流看有没有拿锁：

```bash
timeout 36 docker exec xboard redis-cli -s /data/redis.sock MONITOR 2>&1 \
  | grep -i 'scheduler-lock' | head
```

| 任务 | 频率 | 作用 |
|---|---|---|
| `reset:traffic` | 每分钟 | 按 `next_reset_at` 重置到期用户的流量 |
| `reset:log` | 每天 | 清理 3 个月前的审计日志 |
| `cleanup:online-status` | 每 5 分钟 | 清理 Redis 过期后残留的在线计数 |

```bash
docker exec xboard php /www/artisan schedule:list
```

> 三条任务都带 `onOneServer()` + `withoutOverlapping()`，多个 Octane worker
> 同时触发也只会执行一次（走 Redis 锁）。
> 非 Docker 部署没有 Octane 时，才需要自己配 cron 或 `php artisan schedule:work`。

### 改代码后怎么生效

**PHP 代码和前端 dist 都在镜像里**，只有 `.env` / `.docker/.data` / `storage/logs` /
`storage/theme` / `plugins` 是挂载的宿主机目录。

三条路，按场景选：

```bash
# 1. 临时调试：塞进容器，重启即生效（重启后丢失）
docker cp app/xxx.php xboard:/www/app/xxx.php
docker exec xboard php /www/artisan optimize:clear
docker compose -f compose.image.yaml restart xboard

# 2. 正式发布：推到 master，GitHub Actions 构建新镜像（约 3-6 分钟）
git push origin master

# 3. 服务器拉新镜像（数据不会丢，全是 bind mount）
docker compose -f compose.image.yaml pull && docker compose -f compose.image.yaml up -d
```

> 改了 `.docker/` 或 `Dockerfile` 会让构建缓存失效，单次构建会慢到 6-10 分钟。

---

## 目录结构

```
app/
├── Console/Commands/     xboard:install / reset:traffic / reset:log / cleanup:online-status
├── Http/
│   ├── Controllers/
│   │   ├── V1/Server/    UniProxyController ← XrayR 对接（核心）
│   │   ├── V1/Client/    订阅下发
│   │   ├── V1/Passport/  登录注册
│   │   ├── V1/User/      用户自助
│   │   ├── V2/Admin/     后台管理 API + StubController（降级路由）
│   │   └── V2/Client/    App 配置
│   ├── Middleware/       Server（XrayR 鉴权）· Admin · User · Client
│   ├── Requests/         Admin / Passport / User / Staff 四套表单校验
│   └── Routes/           V1 / V2 路由定义
├── Models/               User / Plan / Server / ServerGroup / ServerRoute /
│                         TrafficResetLog / Setting / Plugin / AdminAuditLog
├── Observers/            UserObserver ← applyPlan() 在这里
├── Protocols/            订阅格式渲染（11 种，动态扫描加载，勿删）
├── Services/
│   ├── ServerService.php        节点配置与用户下发
│   ├── TrafficResetService.php  流量重置
│   ├── AuthService.php          会话与鉴权
│   └── Plugin/                  插件钩子系统
├── Support/              Setting（带 Redis 缓存）· ProtocolManager
└── Jobs/                 TrafficFetchJob（流量落库）

.docker/                  Dockerfile 配套（supervisor / caddy / entrypoint）
compose.image.yaml        唯一的部署文件
public/assets/admin/      后台面板 dist（前端源码不在此仓库）
docs/slimming-report.md   精简过程记录
strip_admin_ui.py         后台菜单裁剪工具
```

---

## 踩坑

**1. `docker compose pull` 卡住 / 报 `DNS: transient error`**

容器 DNS 不通（拉 `ghcr.io` 最明显）。写 `/etc/docker/daemon.json`：

```json
{ "dns": ["223.5.5.5", "119.29.29.29", "8.8.8.8"] }
```

然后 `systemctl restart docker`。宿主机 DNS 走透明代理（返回 `198.18.x.x`）时同样需要。

**2. `xboard:install` 跑完没输出、`.env` 还是空的**

数据库里已有表。安装程序检测到数据会问「是否清空」，**非交互环境下默认选退出**，
静默返回且不报错。重来：

```bash
rm -f .docker/.data/database.sqlite*
rm -f .env && touch .env
```

**3. 容器起来但 Octane 反复重启，日志报 `MissingAppKeyException`**

`.env` 里没有 `APP_KEY`。手动写的 `.env` 不完整，必须走官方安装命令
（它会生成 key 并写入 `INSTALLED` 标记）。

**4. 刚 `up -d` 完立刻访问是 404 / 打不开**

Octane 还没就绪。等 30-60 秒。`docker logs` 里出现
`octane_00 entered RUNNING state` 才是真正可用。

**5. XrayR 一直 `422 Invalid token`**

`v2_settings` 里没有 `server_token`，`admin_setting()` 返回 `null`，
而中间件是 `$value !== admin_setting('server_token')` —— 任何 token 都不等于 `null`。
手工补：

```bash
T=$(openssl rand -hex 24)
docker exec -e T="$T" xboard php -r '
$d = new PDO("sqlite:/www/.docker/.data/database.sqlite");
$d->prepare("insert into v2_settings (name,value,created_at,updated_at) values (?,?,?,?)")
  ->execute(["server_token", getenv("T"), time(), time()]);'
docker exec xboard php /www/artisan cache:clear
```

**6. 用户显示正常但客户端连不上**

按[四个字段](#用户能不能连上节点只看四个字段)逐条对。最常见是 `group_id = NULL`
（节点 `group_ids` 是 `["1"]`，匹配不上）或 `expired_at = 0`。

```bash
docker exec xboard php -r '
$d = new PDO("sqlite:/www/.docker/.data/database.sqlite");
foreach ($d->query("select id,email,plan_id,group_id,expired_at,transfer_enable from v2_user") as $r)
  echo implode("|", [$r["id"],$r["email"],$r["plan_id"],$r["group_id"],$r["expired_at"],$r["transfer_enable"]]), "\n";'
```

---

## 环境要求

- PHP ≥ 8.2（`redis` / `sqlite3` 或 `pdo_mysql` / `bcmath` / `pcntl`）
- Redis（缓存 + 队列）
- SQLite / MySQL / PostgreSQL 任选

Docker 部署这些都已在镜像里，无需宿主机安装。

---

## 回滚

精简过程按批次提交，每步可独立回退：

```bash
git log --oneline                        # 查看批次
git checkout <commit> -- <路径>          # 恢复单个文件
git checkout d9ede6f -- .                # 全量恢复到精简前基线
```

| 提交 | 内容 |
|---|---|
| `d9ede6f` | **baseline：精简前基线快照**（完整版全量代码） |
| `1df2bdc` | 批次 1：删除 plugins-core 支付插件与 PaymentInterface |
| `44db83d` | 批次 2：收窄 XrayR 接口层 |
| `ad293ad` | 批次 3：核心链路解耦（流量落库路径收敛） |
| `6aa90e3` | 批次 4a：删除交易类模块 |
| `149bac2` | 批次 4b：删除运营类模块 |
| `c08bda1` | 批次 4c：删除通知类模块 |
| `7149395` | 批次 4d：删除统计 / 更新 / 备份 / 多主题 |
| `a393c81` | 批次 4e：路由清理与收尾 |
| `72abdc7` | 批次 5：协议收窄 11 → 3 |
| `7fbd082` | 批次 6：依赖与配置收尾 |

> ⚠️ 仓库历史曾用 `git filter-repo` 重写过（移除 `.workbuddy-ai`），
> 重写前的旧哈希已失效，以本表与 `git log` 为准。

---

## 许可

MIT，同上游 [cedar2025/Xboard](https://github.com/cedar2025/Xboard)。

> 个人自用版本，不提供技术支持。上游功能请参考官方仓库。
