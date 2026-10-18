#!/bin/sh
set -e

# Resolve the binding scheme based on whether the embedded Caddy is enabled.
#
# When ENABLE_CADDY=true (default), Caddy owns the public port (7001) and
# dispatches traffic internally; Octane binds to localhost only so it cannot
# be reached from outside the container.
#
# When ENABLE_CADDY=false (e.g. an external reverse proxy), Octane takes the
# public port directly to keep behaviour identical to the pre-Caddy releases.
if [ "${ENABLE_CADDY}" = "true" ]; then
    : "${OCTANE_HOST:=127.0.0.1}"
    : "${OCTANE_PORT:=7002}"
    : "${CADDY_LISTEN_PORT:=7001}"
else
    : "${OCTANE_HOST:=0.0.0.0}"
    : "${OCTANE_PORT:=7001}"
fi
export OCTANE_HOST OCTANE_PORT CADDY_LISTEN_PORT
export OCTANE_INTERNAL_PORT="${OCTANE_PORT}"

# ---------------------------------------------------------------------------
# Auto-tune worker counts based on the host (CPU + memory).
#
# Heuristic: each PHP worker (Octane/Horizon) costs ~80 MiB. After reserving
# ~300 MiB for the always-on processes (caddy/redis/masters), divide
# the remaining budget across roles.  Any user-set ENV wins.
# ---------------------------------------------------------------------------
detect_cpus() {
    if [ -r /sys/fs/cgroup/cpu.max ]; then
        # cgroup v2: "<quota> <period>" or "max <period>"
        read -r q p < /sys/fs/cgroup/cpu.max 2>/dev/null
        if [ "$q" != "max" ] && [ -n "$q" ] && [ -n "$p" ] && [ "$p" -gt 0 ]; then
            echo $(( (q + p - 1) / p ))
            return
        fi
    fi
    nproc 2>/dev/null || echo 1
}

detect_mem_mib() {
    if [ -r /sys/fs/cgroup/memory.max ]; then
        m=$(cat /sys/fs/cgroup/memory.max 2>/dev/null)
        if [ "$m" != "max" ] && [ -n "$m" ]; then
            echo $(( m / 1024 / 1024 ))
            return
        fi
    fi
    # No cgroup limit: avoid over-provisioning on big hosts. Cap the assumed
    # budget to MEM_FALLBACK_MIB (default 1024) unless the user opts out by
    # setting it explicitly. Use whichever is smaller of MemAvailable and cap.
    avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 1024)
    cap=${MEM_FALLBACK_MIB:-1024}
    [ "$avail" -lt "$cap" ] && echo "$avail" || echo "$cap"
}

CPUS=$(detect_cpus)
MEM_MIB=$(detect_mem_mib)

# Resource profile presets. RESOURCE_PROFILE selects ratios for the budget split:
#   minimal     - smallest possible footprint (~250-350 MiB), single octane worker,
#                 horizon capped to 1/1/1. Suitable for VPS with <=512 MiB RAM.
#   balanced    - default; ~80 MiB per worker, octane gets 25% of slots.
#   performance - larger reserves for opcache/caches, more aggressive horizon caps.
#   auto        - same as balanced.
: "${RESOURCE_PROFILE:=auto}"
case "$RESOURCE_PROFILE" in
    minimal)     RESERVED_MIB=200; SLOT_MIB=100; OCT_NUM=1; OCT_DEN=1; OCT_FORCE=1; auto_horizon_mem=128; auto_octane_gc=64 ;;
    performance) RESERVED_MIB=400; SLOT_MIB=70;  OCT_NUM=1; OCT_DEN=3; OCT_FORCE=0; auto_horizon_mem=384; auto_octane_gc=256 ;;
    balanced|auto|*) RESERVED_MIB=300; SLOT_MIB=80;  OCT_NUM=1; OCT_DEN=4; OCT_FORCE=0; auto_horizon_mem=256; auto_octane_gc=128 ;;
esac

BUDGET=$(( MEM_MIB - RESERVED_MIB ))
[ "$BUDGET" -lt "$SLOT_MIB" ] && BUDGET=$SLOT_MIB
SLOTS=$(( BUDGET / SLOT_MIB ))

clamp() { v=$1; lo=$2; hi=$3; [ "$v" -lt "$lo" ] && v=$lo; [ "$v" -gt "$hi" ] && v=$hi; echo "$v"; }

if [ "$OCT_FORCE" = "1" ]; then
    auto_octane=1
    auto_horizon=1
else
    auto_octane=$(clamp $(( (SLOTS * OCT_NUM) / OCT_DEN )) 1 "$CPUS")
    # 精简版只有一个队列（traffic_fetch），不需要按业务分池，
    # 剩余预算给 1-2 个消费进程就够，再多也是空跑占内存。
    auto_horizon=$(clamp $(( SLOTS - auto_octane )) 1 2)
fi

# User-set ENV always wins.
: "${OCTANE_WORKERS:=$auto_octane}"
# 必须 >= 1。别想着设 0 来省内存 —— 实测 Swoole 6.3.0RC1 把 0 当成
# "未设置"，会退化成 task_worker_num = worker_num（2 核就是 2 个 task worker，
# 反而比设 1 多占 ~64 MiB）。设 1 才是真的只起 1 个。
: "${OCTANE_TASK_WORKERS:=1}"
: "${OCTANE_MAX_REQUESTS:=500}"
: "${OCTANE_GARBAGE_MB:=$auto_octane_gc}"
: "${OCTANE_MAX_EXECUTION_TIME:=60}"
: "${HORIZON_MAX_PROCESSES:=$auto_horizon}"
: "${HORIZON_WORKER_MEMORY_MB:=$auto_horizon_mem}"
: "${HORIZON_WORKER_MAX_TIME:=0}"
: "${HORIZON_WORKER_MAX_JOBS:=0}"

export OCTANE_WORKERS OCTANE_TASK_WORKERS OCTANE_MAX_REQUESTS \
       OCTANE_GARBAGE_MB OCTANE_MAX_EXECUTION_TIME \
       HORIZON_MAX_PROCESSES \
       HORIZON_WORKER_MEMORY_MB HORIZON_WORKER_MAX_TIME HORIZON_WORKER_MAX_JOBS \
       RESOURCE_PROFILE

echo "[entrypoint] Auto-tune (profile=${RESOURCE_PROFILE}): cpus=${CPUS} mem=${MEM_MIB}MiB slots=${SLOTS} -> octane=${OCTANE_WORKERS} horizon=${HORIZON_MAX_PROCESSES} horizon_worker_mem=${HORIZON_WORKER_MEMORY_MB}MB"
echo "[entrypoint] Horizon runs a single supervisor (queues: traffic_fetch, default); it scales 1..${HORIZON_MAX_PROCESSES} on demand."

echo "[entrypoint] Starting services (caddy=${ENABLE_CADDY} web=${ENABLE_WEB} horizon=${ENABLE_HORIZON})..."
# Drop stale Octane/WorkerMan state files so the new master does not signal
# PIDs left over from a previous container run (causes Swoole kill EPERM).
rm -f /www/storage/logs/octane-server-state.json 2>/dev/null || true
chown -R www:www /www 2>/dev/null || true
chown redis:redis /data 2>/dev/null || true
exec "$@"
