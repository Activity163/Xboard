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

### Docker（推荐）

```bash
# 0. 生成 compose 文件（仓库只提供 sample，compose.yaml 被 gitignore）
cp compose.sample.yaml compose.yaml

# 1. 构建镜像（使用本地代码）
docker compose build

# 2. 首次安装（会生成 APP_KEY、导入数据库、创建管理员）
rm -f .env && touch .env
docker compose run --rm \
  -e ENABLE_SQLITE=true \
  -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=admin@example.com \
  xboard php artisan xboard:install

# 3. 启动
docker compose up -d
```

安装完成后终端会打印**管理面板地址**与**管理员密码**，务必保存。

- 访问：`http://SERVER_IP:7001/<secure_path>`
- 容器内包含：Octane（PHP 常驻）+ Horizon（队列）+ Redis + Caddy

> ⚠️ 安装命令**不要加 `-it`**（远程 SSH 无 TTY 会报 `cannot attach stdin to a TTY-enabled container`）。

**三种 compose 模板**（按部署环境选）：

| 文件 | 适用场景 |
|---|---|
| `compose.sample.yaml` | 默认，bridge 网络，宿主机 `127.0.0.1:7001` 对外 |
| `compose.1panel.sample.yaml` | 1Panel 用户，额外接入 `1panel-network` 以访问其 MySQL/Redis |
| `compose.host.sample.yaml` | 宿主机跑 nginx/openresty，需要 host 网络 |

### 手动部署

```bash
composer install --no-dev
cp .env.example .env
php artisan key:generate
# 编辑 .env 配置数据库与 Redis
php artisan migrate --force
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
public/assets/admin/    后台管理面板（React dist）
docs/slimming-report.md 精简完成报告
```

## 后台管理

访问 `http://面板地址:7001/<secure_path>`（`secure_path` 在安装时打印，默认是 `crc32b(app.key)`）。

**可用页面**：节点管理 · 用户管理 · 权限组 · 路由 · 系统设置 · 系统状态 · 插件 · 流量重置

**菜单里点开 404 的页面**：订单 / 支付 / 套餐 / 优惠券 / 礼品卡 / 工单 / 知识库 / 公告 / 统计 / 主题 / 邮件模板 —— 这些是前端 dist 里写死的入口，后端 API 已随模块一起移除。

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

精简过程按批次提交，每步可独立回退：

```bash
git log --oneline                      # 查看批次
git checkout <批次commit> -- <路径>     # 恢复单个文件
git checkout f193bfd -- .              # 全量恢复到精简前
```

| 提交 | 内容 |
|---|---|
| `f193bfd` | 精简前基线快照 |
| `63baf1d` `9763568` `4c79057` | 支付插件 / XrayR 接口层 / 流量链路 |
| `d1a3cd1` `8e4e275` `ecaad9d` `c84b5e7` | 交易 / 运营 / 通知 / 统计与前端 |
| `941cba5` `69341d8` `3985a40` | 路由 / 协议收窄 / 依赖收尾 |
| `b5263bc` `7e459c0` | Docker 修复 / composer.lock |

## 许可

MIT，同上游 [cedar2025/Xboard](https://github.com/cedar2025/Xboard)。

---

> 本仓库为个人自用精简版，**不提供技术支持**，上游功能说明请参考官方仓库。