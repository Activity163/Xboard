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

## 四、回归验证（Debian 12 测试机）

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

## 七、验证方式

精简后的回归验证在**独立的临时虚拟机**上完成，避免影响生产环境：

- 环境：PHP 8.2 + SQLite + Redis（`php artisan serve` 单进程模式）
- 覆盖：XrayR 三接口（config / user / push）、后台管理 API、订阅分发、定时任务
- 重点验证项：三种协议（vmess / vless+REALITY / shadowsocks-2022）的配置下发正确性、
  `push` 后流量落库数值一致性、已删除接口返回 404
- 结果：全部通过，日志零错误

复现验证环境：

```bash
# 任选一台干净的 Linux 机器
composer install --no-dev
cp .env.example .env && php artisan key:generate
php artisan migrate --force
php artisan xboard:install        # 或 Docker 方式，见 README

php artisan serve --host=127.0.0.1 --port=8000   # 面板 + XrayR 接口
php artisan queue:work redis --queue=traffic_fetch
```

> 具体的测试主机地址、账号与 token 不在此记录，属本地环境信息。

---

## 八、后续追加：恢复后台管理面板（2026-10-17）

用户反馈需要后台管理界面，已恢复。

| 项 | Commit | 说明 |
|---|---|---|
| 恢复面板 | `2bab01a` | 恢复 `admin.blade.php` + 后台入口路由；拉取 `cedar2025/xboard-admin-dist`（16MB）到 `public/assets/admin`；移除 `.gitmodules`（dist 直接纳入仓库，git 压缩后 6.5MB） |
| 修复残留 | `8510d7a` | 修复恢复后暴露的 plan 残留引用 |

**恢复后暴露并修掉的问题**：

1. **`user/fetch` 报 500** —— `V2AdminUserController` 仍在用 `->with('plan')` 关系（已删）。已移除 plan 预加载、CSV 导出的「订阅计划」列与 `plan_id` 入参。
2. **`destroy` 调用已删关系** —— `orders()` / `stat()` / `tickets()` 已删，改为只清理 `codes()` 与 `trafficResetLogs()`。
3. **⚠️ `reset:traffic` 静默失效（最隐蔽）** —— `ResetTraffic` 有 3 处 `whereNotNull('plan_id')`，Plan 删除后该字段恒为 NULL，**定时重置任务永远匹配不到用户，而且不报任何错**。已移除。
4. `UserService::getUnAvailbaleUsers()` 条件恒真且无调用者 → 删除。
5. `ClearUser` 的无效 `plan_id` 条件 → 移除。

**后台可用范围**：节点管理 / 用户管理 / 分组 / 路由 / 系统设置 / 系统状态 / 插件 / 流量重置。

**已删模块对应页面点开会 404**：订单 / 支付 / 套餐 / 优惠券 / 礼品卡 / 工单 / 知识库 / 公告 / 统计 / 主题 / 邮件模板 —— 后端 API 已不存在，前端菜单残留不影响核心功能。

**验证**：后台登录返回 `is_admin:true`；12 个后台 GET 接口全 200；XrayR 三接口 200；`reset:traffic` 正常执行；清空日志复测零错误。

**入口地址**：`http://<域名>/<secure_path>`。


---

## 九、Docker 部署修复与验证（2026-10-17）

用户习惯用 Docker 镜像安装，检查后发现 **Docker 这条线原本完全不可用**。

### 9.1 致命问题：构建的是原版，不是精简版

| 位置 | 原问题 | 修复 |
|---|---|---|
| `Dockerfile` | `rm -rf ./*` 后 `git clone` 上游 master + `git submodule` —— 构建出的镜像里是**原版 Xboard**，精简代码完全没被使用 | 改为 `COPY . /www`（.dockerignore 排除 .git/.github 等） |
| `compose*.yaml` | `image: ghcr.io/cedar2025/xboard:latest`（官方镜像，同样是原版） | 改为 `build: .` + `image: xboard-slim:latest` |

> 如果没发现这个问题，用户 `docker compose up -d` 拉起来的会是**几百 MB 的原版全功能面板**，跟精简工作毫无关系。

> **后来演进**：为了让服务器免编译，镜像改由 GitHub Actions 构建并推到
> `ghcr.io/activity163/xboard:latest`，compose 里的 `build: .` 也就不再需要。
> 最终只保留一份 `compose.image.yaml`（详见 9.6）。

### 9.2 已删模块的容器引用（会报错或空转）

| 位置 | 问题 | 修复 |
|---|---|---|
| `supervisord.conf` | `[program:ws-server]` 执行 `artisan ws-server`，命令已删 → **无限重启失败** | 移除该 program |
| `entrypoint.sh` | 每次启动调用 `artisan xboard:update`，命令已删 → 打印警告 | 移除该调用及 `redis_reachable()` 辅助函数 |
| `entrypoint.sh` | `WS_HOST` / `WS_PORT` 变量 | 移除 |
| `Caddyfile` | `/ws` 反代到 `127.0.0.1:8076`，无服务 → 502 | 移除该路由 |
| `Dockerfile` | `ENABLE_WS_SERVER=true` | 移除 |
| `compose.split.sample.yaml` + `Caddyfile.split` | split 模式依赖 ws-server 容器 | 删除这两个文件 |
| `init.sh` / `update.sh` | `git submodule` → 无子模块，报错 | 移除 |
| `update.sh` | `git reset --hard` 会覆盖本地精简代码；`xboard:update` 命令已删 | 移除两者 |

### 9.3 composer.lock 重新生成

原 lock 仍是精简前的（446KB），本地 `composer install` 会拉回已移除的包。从 Docker 构建产物中提取干净版本：

- **446KB → 365KB**（净减 2226 行）
- `.dockerignore` 移除 `composer.lock` 排除项，使构建直接使用锁定版本

### 9.4 实测验证（Debian 12 / Docker 29.9.1 / Compose v5.6.0）

```
docker build                    → 成功（xboard-slim:latest，562MB / 126MB）
容器内 xboard:install           → 成功（SQLite + 内置 Redis）
supervisor 进程                 → octane / horizon / redis / caddy 全部 RUNNING（无 ws-server）
后台页面 /<secure_path>         → 200
后台静态资源 js/css/locales     → 200
后台 API（6 项）                → 全 200
XrayR config/user/push          → 全 200
push 后流量落库                 → u=4096 d=8192（与推送值一致，Horizon 正常消费队列）
清空日志复测                    → 零错误
容器内存占用                    → 798 MiB（含 octane + horizon + redis + caddy）
```

### 9.5 部署注意事项（实测踩坑）

1. **Docker 必须配置 DNS**，否则镜像构建时 apk 报 `DNS: transient error`：
   ```json
   // /etc/docker/daemon.json
   { "dns": ["223.5.5.5", "119.29.29.29", "8.8.8.8"] }
   ```
   然后 `systemctl restart docker`。若宿主机 DNS 走透明代理（返回 198.18.x.x），同样需要此项。

2. **`xboard:install` 在检测到已有数据时会静默退出**。若 `.docker/.data/database.sqlite` 里已有表，`configureSqlite()` 会弹 `confirm('是否清空数据库')`，默认选项是**退出安装**，非交互环境下就直接返回、不报错。重装前先删：
   ```bash
   rm -f .docker/.data/database.sqlite*
   rm -f .env && touch .env
   ```

3. **安装必须用官方命令**（会自动生成 APP_KEY 与 INSTALLED 标记），手动写 .env 会导致 octane 因缺 APP_KEY 无限重启：
   ```bash
   docker compose run --rm -e ENABLE_SQLITE=true -e ENABLE_REDIS=true \
     -e ADMIN_ACCOUNT=admin@example.com xboard php artisan xboard:install
   ```
   注意加 `--rm` 且**不要加 `-it`**（远程 SSH 无 TTY 时会报
   `cannot attach stdin to a TTY-enabled container`）。

### 9.6 镜像构建与启动

> **2026-10-18 更新**：compose 文件已收敛为**一份** `compose.image.yaml`
> （`compose.yaml` / `compose.sample.yaml` / `compose.host.sample.yaml` /
> `compose.1panel.sample.yaml` 全部删除）。镜像改由 GitHub Actions 预构建，
> 服务器只拉不编译，因此下面的 `build` 步骤已不再需要。

```bash
# 拉取 CI 预构建镜像（不在服务器编译）
docker compose -f compose.image.yaml pull

# 首次安装
rm -f .env && touch .env
docker compose -f compose.image.yaml run --rm \
  -e ENABLE_SQLITE=true -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=admin@example.com xboard php artisan xboard:install

# 启动
docker compose -f compose.image.yaml up -d

# 查看状态
docker compose -f compose.image.yaml ps
docker compose -f compose.image.yaml logs -f xboard
```

> 生产环境用**外挂 Caddy**（独立容器）反代，`compose.image.yaml` 里已设
> `ENABLE_CADDY=false` 并接入 `nezha-dashboard_default` external 网络。

