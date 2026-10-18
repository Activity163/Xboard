# Xboard（精简版）

> 基于 [cedar2025/Xboard](https://github.com/cedar2025/Xboard) 的个人自用精简版本。
> **只保留 XrayR 对接与核心节点/用户管理**，去掉了所有商业与运营模块。

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![Laravel](https://img.shields.io/badge/Laravel-12-red.svg)
![License](https://img.shields.io/badge/License-MIT-yellow.svg)

</div>

---

## 这是什么

一个**个人自用**的代理节点管理面板。相比原版 Xboard，这里只保留了「一个人管几台机器几个用户」真正用得上的功能：

- 用户增删改（流量 / 到期 / 限速 / 封禁 / 重置订阅）
- 权限组（控制哪些用户能用哪些节点）
- 节点管理（**仅 vmess / vless / shadowsocks**，含 REALITY、multiplex、ECH、路由规则、倍率）
- 订阅分发（保留全部客户端格式）
- 流量统计与定时重置
- **XrayR 对接**（config / user / push 三条链路）
- 后台管理面板（React）

## 精简了什么

| 指标 | 原版 | 本版 |
|---|---|---|
| `app/` 代码行数 | 31,098 | **16,643**（−46%） |
| PHP 文件数 | 400+ | **209** |
| 节点协议 | 11 种 | **3 种** |
| XrayR 接口 | 6 个 | **3 个** |
| 控制器 | 40+ | **20** |

**已移除的模块**：

| 类别 | 移除内容 |
|---|---|
| 交易 | 订单 / 支付 / 优惠券 / 礼品卡 / 邀请返佣（**套餐只保留只读模板**，见「订阅分配」） |
| 运营 | 工单 / 知识库 / 公告 |
| 通知 | 邮件（含邮箱验证、邮件链接登录）/ Telegram 机器人 |
| 统计 | 收入统计 / 用户流量日志 / 节点负载历史 |
| 运维 | 在线更新 / 数据库备份 / 多主题切换 |
| 基础设施 | WebSocket 节点通道 / 机器节点集群（machine）/ 设备数限制 / 流量审计队列 / 设备状态上报 |
| 前端 | 用户 Web 前端（展示型主页）/ 后台侧边栏的对应菜单入口（见「后台管理」） |
| 协议 | trojan / hysteria / tuic / anytls / socks / naive / http / mieru |
| 依赖 | stripe / google-cloud-storage / workerman / spatie-db-dumper / ip2region / linfo 等 11 个 |

> 完整清单与逐批次变更见 [`docs/slimming-report.md`](docs/slimming-report.md)。

## 快速开始

部署全程在**服务器**上完成，不需要本地克隆仓库。

### Docker 部署（推荐）

**1. 服务器上拉取代码**

```bash
apt update && apt install -y git
git clone https://github.com/Activity163/Xboard.git
cd Xboard
```

**2. 拉取镜像**

仓库只保留**一份** `compose.image.yaml`，直接拉 GitHub Actions 预构建好的镜像
（`ghcr.io/activity163/xboard:latest`，amd64 / arm64），**服务器不现场编译**：

```bash
docker compose -f compose.image.yaml pull
```

> 文件名不是 `compose.yaml`，所以下面每条命令都会带上 `-f compose.image.yaml`。
> 嫌长可以 `alias dc='docker compose -f compose.image.yaml'`，之后 `dc up -d` 即可。
> 国内拉 `ghcr.io` 慢或报 `DNS: transient error`，见下方「部署踩坑 1」。

**3. 首次安装**

```bash
rm -f .env && touch .env
docker compose -f compose.image.yaml run --rm \
  -e ENABLE_SQLITE=true \
  -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=admin@example.com \
  xboard php artisan xboard:install
```

安装程序会自动：生成 `APP_KEY` → 建库导入 → **初始化 `server_token`** →
**创建默认订阅「章鱼哥专属」** → 创建管理员（并分配该订阅）→ 安装默认插件。

> `server_token` 是 XrayR 与面板通讯的密钥，不初始化的话节点会一直
> `422 Invalid token`（`v2_settings` 空表时 `admin_setting()` 返回 null）。
> 安装程序已自动生成，也可在后台「系统配置」里改。

> ⚠️ 两点务必注意：
> - **不要加 `-it`**。远程 SSH 无 TTY，会报 `cannot attach stdin to a TTY-enabled container`。
> - **`.env` 必须存在且为空**。安装程序要往这个文件里写配置，Docker 方式下不能删掉它。

**4. 启动**

```bash
docker compose -f compose.image.yaml up -d
docker compose -f compose.image.yaml logs -f xboard   # 观察启动日志
```

终端会打印**管理面板地址**（`http://<你的IP>:7001/<secure_path>`）与**管理员密码**，请立即保存。

> 后台路径**不落盘**，只在那一次输出里出现。忘了可以重算 ——
> 它等于 `crc32b(APP_KEY)`：
> ```bash
> docker exec xboard php -r 'echo hash("crc32b", config("app.key")), "\n";'
> ```

**5. 收尾**

编辑 `.env`，把 `APP_URL` 改成实际对外地址（HTTPS 域名），然后重启：

```bash
docker compose -f compose.image.yaml restart xboard
```

**容器内已含**：Octane（PHP 常驻）+ Horizon（队列）+ Redis，单容器开箱可用。工作进程数会按机器 CPU / 内存自动调优（`RESOURCE_PROFILE` 控制档位）。

### 反代：用外挂 Caddy（当前生产环境）

`compose.image.yaml` 里设了 `ENABLE_CADDY=false`，镜像**不启动内置 Caddy**，
Octane 直接绑 `0.0.0.0:7001`；再由宿主机上已有的独立 Caddy 容器反代：

```
浏览器 → nezha-caddy（nezha-dashboard_default 网络）→ xboard:7001
```

Caddyfile 里对应的一节：

```caddyfile
xboard.你的域名 {
	encode zstd gzip
	reverse_proxy xboard:7001 {
		header_up Host {host}
		header_up X-Forwarded-Proto {scheme}
	}
}
```

> 关键在于两个容器**在同一 Docker 网络**里，Caddy 才能用容器名 `xboard` 解析到 IP。
> `compose.image.yaml` 的 `networks` 段就是干这个的，`external: true` 表示复用既有网络而非新建。

**想改回镜像内置 Caddy**：删掉 `networks` 整段、把 `ENABLE_CADDY` 改成 `true`。
此时 Caddy 接管 7001，Octane 自动退到 `127.0.0.1:7002`，宿主机只管把域名指过来。


### 手动部署（不用 Docker）

```bash
git clone https://github.com/Activity163/Xboard.git && cd Xboard
composer install --no-dev
cp .env.example .env
# 编辑 .env 配置数据库与 Redis（APP_KEY 留空）
php artisan xboard:install
```

## 部署踩坑

实测中遇到的几个坑，按这个顺序排查：

**1. `docker compose pull` 卡住或报 `DNS: transient error`**

容器 DNS 不通（拉 `ghcr.io` 时最明显）。写 `/etc/docker/daemon.json`：

```json
{ "dns": ["223.5.5.5", "119.29.29.29", "8.8.8.8"] }
```

然后 `systemctl restart docker`。若宿主机 DNS 走透明代理（返回 198.18.x.x），同样需要这项。

> 若始终拉不下来，可以把镜像 `docker save` 到本地再 `docker load`，
> 或者临时把 `image:` 换成任意可达的镜像仓库地址。

**2. `xboard:install` 跑完没输出、`.env` 还是空的**

数据库里已有表。安装程序检测到数据会弹「是否清空数据库」，**非交互环境下默认选退出**，直接静默返回且不报错。重来一次：

```bash
rm -f .docker/.data/database.sqlite*
rm -f .env && touch .env
```

**3. 容器起来了但 Octane 反复重启，日志报 `MissingAppKeyException`**

`.env` 里没有 `APP_KEY`。手动写的 `.env` 不完整，必须走官方安装命令（它会自动生成 key 并写入 `INSTALLED` 标记）。

## 对接 XrayR

在节点上部署 [XrayR](https://github.com/XrayR-project/XrayR)，面板地址与 token 填：

```yaml
PanelType: "Xboard"
ApiConfig:
  ApiHost: "http://你的面板地址:7001"
  ApiKey: "在后台【系统设置】里配置的 server_token"
  NodeID: 1
  NodeType: vmess        # vmess / vless / shadowsocks
```

面板侧只需实现三个接口（`app/Http/Controllers/V1/Server/UniProxyController.php`）：

| 接口 | 作用 |
|---|---|
| `GET  /api/v1/server/UniProxy/config` | 下发节点配置（含 REALITY / multiplex / 路由规则） |
| `GET  /api/v1/server/UniProxy/user` | 下发该节点的可用用户列表 |
| `POST /api/v1/server/UniProxy/push` | 接收流量上报，走队列落库到 `v2_user.u` / `v2_user.d` |

两个接口都支持 `ETag` / `304`，XrayR 轮询时不会重复传输。

### 支持的协议

| 协议 | 说明 |
|---|---|
| `vmess` | 支持 ws / tcp / grpc 等传输方式、TLS、multiplex |
| `vless` | 额外支持 REALITY、flow（`xtls-rprx-vision`）、encryption |
| `shadowsocks` | 含 2022 系列（`2022-blake3-aes-128-gcm` / `-256-gcm`），支持 obfs 与 v2ray-plugin |

> Shadowsocks 2022 的用户密钥派生规则：面板 `base64(uuid[:16])` == XrayR `base64(password[:16])`，已实测一致。

## 订阅格式

订阅链接：`http://面板地址/s/<用户 token>`

按客户端 User-Agent 自动适配，保留全部格式：

`General` · `Clash` · `ClashMeta` · `SingBox` · `Surge` · `Surfboard` · `Loon` · `QuantumultX` · `Shadowrocket` · `Stash` · `Shadowsocks`

## 目录结构

```
app/
├── Console/Commands/   reset:traffic / reset:log / cleanup:online-status / xboard:install
├── Http/
│   ├── Controllers/
│   │   ├── V1/Server/  UniProxyController ← XrayR 对接（核心）
│   │   ├── V1/Client/  订阅下发
│   │   ├── V1/User/    用户自助
│   │   ├── V2/Admin/   后台管理 API
│   │   └── V1/Passport/ 登录注册
│   └── Routes/         V1 / V2 路由定义
├── Models/             User / Plan / Server / ServerGroup / ServerRoute / TrafficResetLog
├── Protocols/          订阅格式渲染（固定层，未做精简）
└── Services/
    ├── ServerService.php        节点配置与用户下发
    ├── UserService.php          用户与流量
    ├── TrafficResetService.php  流量重置（含重置方式常量）
    └── Plugin/                  插件钩子系统
.docker/                Dockerfile 配套（supervisor / caddy / entrypoint）
compose.image.yaml      唯一的部署文件（CI 预构建镜像 + 外挂 Caddy）
public/assets/admin/    后台管理面板（React dist）
docs/slimming-report.md 精简完成报告
```

## 后台管理

访问 `http://面板地址:7001/<secure_path>`（`secure_path` 在安装时打印，默认是 `crc32b(app.key)`）。

### 菜单（已按精简结果裁剪）

```
系统管理 → 系统配置
节点管理 → 节点管理 / 权限组管理 / 路由管理
用户管理 → 用户管理
```

已从侧边栏移除：**仪表盘** · 订阅管理（套餐/订单/优惠券/礼品卡）· 工单管理 · 服务器管理 ·
插件管理 · 主题配置 · 公告管理 · 支付配置 · 知识库管理。

访问 `/` 会自动重定向到「节点管理」。

裁剪方式是**直接修改 `public/assets/admin` 里的前端产物**（菜单数组 + 路由表），
工具脚本见 [`strip_admin_ui.py`](strip_admin_ui.py)（默认 dry-run，`--apply` 才写盘）：

```bash
python strip_admin_ui.py            # 预览将要做的变更
python strip_admin_ui.py --apply    # 执行（自动备份 .bak-menu-removal）
```

> 前端源码不在本仓库（上游独立维护），所以走 dist 级修改。若将来替换 dist，
> 需重新跑一次该脚本。

### 接口兜底

已删模块的接口由 `StubController` 统一接管，返回 `success` + 空数据
（`app/Http/Controllers/V2/Admin/StubController.php`，路由挂在
`app/Http/Routes/V2/AdminRoute.php` 末尾）。按 action 名自动判断返回 `[]` / `{}` / `true`。

**为什么菜单删了还需要兜底**：节点管理页（保留）会以 60 秒间隔轮询
`/server/machine/fetch` 来填充「所属服务器」下拉框。若该接口 404，后台会持续弹
「The route xxx could not be found」。因此 stub 是必需的安全网，不能一起删掉。

## 订阅分配

订阅管理页已经删了，但「给用户分配订阅」这个动作保留了下来 —— 因为它是**用户能连上节点的唯一途径**。

### 原理：`plan_id` 只是个入口，真正生效的是这三个字段

XrayR 拉取用户时只看 `ServerService::getAvailableUsers()` 的四条条件：

```php
whereIn('group_id', $node->group_ids)   // 权限组要包含该节点
whereRaw('u + d < transfer_enable')      // 没跑超流量
where(expired_at >= now() OR expired_at IS NULL)  // 没过期
where('banned', 0)
```

`plan_id` **完全不参与**。套餐（`v2_plan`）的作用只是提供一组默认值，
分配时被抄到用户行上：

| 套餐字段 | 落到用户字段 |
|---|---|
| `group_id` | `v2_user.group_id` |
| `transfer_enable` | `v2_user.transfer_enable` |
| `speed_limit` / `device_limit` | 同名字段 |
| （套餐不设到期时间） | `v2_user.expired_at = NULL`（永久） |

这段逻辑在 `app/Observers/UserObserver.php` 的 `applyPlan()` 里，
`plan_id` 变更时自动触发，admin / staff / 生成用户三条路径都覆盖。

> ⚠️ `expired_at = 0` 和 `NULL` 不是一回事：`NULL` 是永久有效，`0` 会被判定为**已过期**，
> 用户永远不会被下发到节点。分配套餐会自动清成 `NULL`。

### 怎么用

后台 **用户管理 → 编辑用户 → 订阅** 下拉选择套餐并保存即可。
「订阅」下拉的选项来自 `GET plan/fetch`（`app/Http/Controllers/V2/Admin/PlanController.php`，只读）。

### 默认套餐

`php artisan xboard:install` 会幂等创建一条默认订阅，并分配给管理员：

| 字段 | 值 |
|---|---|
| 名称 | 章鱼哥专属 |
| 流量 | 100 TB（`109951162777600` 字节） |
| 权限组 | 库里第一个权限组（没有则创建「默认分组」） |
| 重置方式 | `2` 不重置 |
| 到期时间 | 无（永久） |

想加别的套餐直接往 `v2_plan` 插行即可，字段参考 `app/Models/Plan.php` 的注释。

### 新建节点自动进订阅

`Server\ManageController@save` 在**创建**节点时，若 `group_ids` 为空会自动填入**全部现有权限组**，
避免新节点建完对谁都不可见。编辑时若手动清空则尊重你的选择。

## 定时任务

需要常驻 `schedule:run`（Docker 镜像里 Octane 的 tick 已接管）：

| 任务 | 频率 | 作用 |
|---|---|---|
| `reset:traffic` | 每分钟 | 按 `next_reset_at` 重置到期用户的流量 |
| `reset:log` | 每天 | 清理 3 个月前的审计日志 |
| `cleanup:online-status` | 每 5 分钟 | 清理 Redis 过期后残留的在线计数 |

队列 `traffic_fetch` 需要有 worker 消费（Docker 里由 Horizon 负责）：

```bash
php artisan horizon          # 或
php artisan queue:work redis --queue=traffic_fetch
```

## 环境要求

- PHP ≥ 8.2（需 `redis` / `sqlite3` 或 `pdo_mysql` / `bcmath` / `pcntl` 扩展）
- Redis（缓存 + 队列）
- SQLite / MySQL / PostgreSQL 任选

## 回滚到完整版

精简过程按批次提交，每步可独立回退。**提交哈希见 `git log --oneline`**（当前 HEAD 共 20 个提交）：

```bash
git log --oneline                      # 查看批次
git checkout <批次commit> -- <路径>     # 恢复单个文件
git checkout d9ede6f -- .              # 全量恢复到精简前基线
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

> ⚠️ 仓库历史曾用 `git filter-repo` 重写过（移除了 `.workbuddy-ai` 目录），
> **重写前的旧哈希已全部失效**，请以本表与 `git log` 输出为准。

## 许可

MIT，同上游 [cedar2025/Xboard](https://github.com/cedar2025/Xboard)。

---

> 本仓库为个人自用精简版，**不提供技术支持**，上游功能说明请参考官方仓库。