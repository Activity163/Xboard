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
| 交易 | 订单 / 支付 / 套餐 / 优惠券 / 礼品卡 / 邀请返佣 |
| 运营 | 工单 / 知识库 / 公告 |
| 通知 | 邮件（含邮箱验证、邮件链接登录）/ Telegram 机器人 |
| 统计 | 收入统计 / 用户流量日志 / 节点负载历史 |
| 运维 | 在线更新 / 数据库备份 / 多主题切换 |
| 基础设施 | WebSocket 节点通道 / 机器节点集群（machine）/ 设备数限制 / 流量审计队列 / 设备状态上报 |
| 前端 | 用户 Web 前端（展示型主页） |
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

**2. 构建镜像**

```bash
docker compose build
```

> `compose.yaml` 已随仓库提供，无需拷贝样例文件。
> 若构建时卡在 apk 下载并报 `DNS: transient error`，见下方「部署踩坑 1」。
>
> **不想现场编译**（国内拉 apk / composer 慢）可直接用 CI 预构建镜像，
> 跳过 `build` 这一步：
>
> ```bash
> docker compose -f compose.image.yaml up -d     # 安装步骤同上，把 -f 带上即可
> ```
>
> 镜像地址 `ghcr.io/activity163/xboard:latest`，每次 push master 由 GitHub Actions 自动构建（amd64 / arm64）。

**3. 首次安装**

```bash
rm -f .env && touch .env
docker compose run --rm \
  -e ENABLE_SQLITE=true \
  -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=admin@example.com \
  xboard php artisan xboard:install
```

安装程序会自动：生成 `APP_KEY` → 建库导入 → 创建管理员 → 安装默认插件。

> ⚠️ 两点务必注意：
> - **不要加 `-it`**。远程 SSH 无 TTY，会报 `cannot attach stdin to a TTY-enabled container`。
> - **`.env` 必须存在且为空**。安装程序要往这个文件里写配置，Docker 方式下不能删掉它。

**4. 启动**

```bash
docker compose up -d
docker compose logs -f xboard      # 观察启动日志
```

终端会打印**管理面板地址**（`http://<你的IP>:7001/<secure_path>`）与**管理员密码**，请立即保存。

**5. 收尾**

编辑 `.env`，把 `APP_URL` 改成实际对外地址（HTTPS 域名），然后重启：

```bash
docker compose restart xboard
```

**容器内已含**：Octane（PHP 常驻）+ Horizon（队列）+ Redis + Caddy，单容器开箱可用。工作进程数会按机器 CPU / 内存自动调优（`RESOURCE_PROFILE` 控制档位）。

### 其他网络模式

仓库同时提供两份按环境定制的模板，直接用 `-f` 指定即可，**同样不需要拷贝成 `compose.yaml`**：

| 命令 | 适用场景 |
|---|---|
| `docker compose up -d` | 默认。源码现场构建，bridge 网络，宿主机 `127.0.0.1:7001` 对外 |
| `docker compose -f compose.image.yaml up -d` | 用 CI 预构建镜像，不现场编译（最快） |
| `docker compose -f compose.host.sample.yaml up -d` | 宿主机自带 nginx/openresty，需要 host 网络 |
| `docker compose -f compose.1panel.sample.yaml up -d` | 1Panel 用户，额外接入 `1panel-network` 以访问其 MySQL/Redis |

> 用 `-f` 时如果 `.env` 在仓库根目录，Compose 仍会自动读取，无需额外参数。
> 除 `compose.image.yaml` 外，其余模板都是 `build: .`，首次启动前需要先 `docker compose -f <文件> build`。

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

**1. Docker 构建报 `DNS: transient error`**

容器 DNS 不通。写 `/etc/docker/daemon.json`：

```json
{ "dns": ["223.5.5.5", "119.29.29.29", "8.8.8.8"] }
```

然后 `systemctl restart docker`。

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
├── Models/             User / Server / ServerGroup / ServerRoute / TrafficResetLog
├── Protocols/          订阅格式渲染（固定层，未做精简）
└── Services/
    ├── ServerService.php        节点配置与用户下发
    ├── UserService.php          用户与流量
    ├── TrafficResetService.php  流量重置（含重置方式常量）
    └── Plugin/                  插件钩子系统
.docker/                Dockerfile 配套（supervisor / caddy / entrypoint）
compose.yaml            默认部署文件（服务器 clone 后可直接用）
compose.image.yaml      用 CI 预构建镜像部署（不现场编译）
public/assets/admin/    后台管理面板（React dist）
docs/slimming-report.md 精简完成报告
```

## 后台管理

访问 `http://面板地址:7001/<secure_path>`（`secure_path` 在安装时打印，默认是 `crc32b(app.key)`）。

**可用页面**：节点管理 · 用户管理 · 权限组 · 路由 · 系统设置 · 系统状态 · 插件 · 流量重置

**已移除模块的菜单入口**：订单 / 支付 / 套餐 / 优惠券 / 礼品卡 / 工单 / 知识库 / 公告 / 统计 / 主题 / 邮件模板 / 机器节点集群 —— 这些是前端 dist（编译自上游完整版）里写死的入口，后端模块已删。

这些接口**由 `StubController` 统一接管**，返回 `success` + 空数据，所以：

- 不会弹「The route xxx could not be found」报错
- 其中 `server/machine/fetch` 会被前端以 60 秒间隔**轮询**，若返回 404 会导致后台持续报错——这是必须兜底的原因
- 对应页面点进去是空列表，属于预期表现

实现见 `app/Http/Controllers/V2/Admin/StubController.php`（按 action 名自动判断返回 `[]` / `{}` / `true`），路由挂在 `app/Http/Routes/V2/AdminRoute.php` 末尾。

> 为什么不直接重建前端？前端源码不在本仓库（上游独立维护），重建需 clone 上游 + 改 90 处引用 + 编译，且上游一更新就冲突。stub 方案零改动前端、不影响升级。

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