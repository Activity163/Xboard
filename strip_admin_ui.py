# -*- coding: utf-8 -*-
"""从后台前端 dist 中移除已删模块的菜单项与路由。

背景：public/assets/admin 的前端产物编译自上游完整版，含大量已精简模块的
入口。这些入口点进去是空页/报错，故直接从 dist 中外科手术式移除。

策略：字符串级替换。所有目标片段都先校验唯一性（count==1），
避免误伤；默认 dry-run，加 --apply 才写盘（会先备份 .bak-menu-removal）。

用法：
    python strip_admin_ui.py            # dry-run，只打印将要做的变更
    python strip_admin_ui.py --apply    # 实际执行

菜单（Vlt 数组）删除：
  - 顶层 dashboard（仪表盘）
  - system-management.sub 中：plugin-management / theme-config /
    notice-management / payment-config / knowledge-management
  - 顶层 subscription-management（订阅管理，整组）
  - user-management.sub 中：ticket-management（工单管理）
  - node-management.sub 中：machine-management（服务器管理）

路由（zQe 数组）：
  - 根 index:!0 由仪表盘改为重定向 → /server/manage
    （直接删会让 / 无匹配导致白屏）
  - config 子树中删除：payment / plugin / plugin/menu-demo / plugin/crud-demo /
    theme / notice / knowledge
  - finance 整棵子树
  - user 子树中：ticket
  - server 子树中：machine

保留：config/system、server/{manage,group,route}、user/manage、
      traffic-reset-logs、plugins/:pluginCode/*

注意：节点管理页（/server/manage，保留）会以 60s 间隔轮询
`/server/machine/fetch` 来填充"所属服务器"下拉框，因此后端
StubController 的 server/machine 降级路由必须保留，否则会重新报错。
"""
import re
import shutil
import sys

args = [a for a in sys.argv[1:] if not a.startswith('--')]
DRY = '--apply' not in sys.argv
F = args[0] if args else 'public/assets/admin/assets/index-CEIYH7i8.js'

s = open(F, encoding='utf-8', errors='replace').read()
orig_len = len(s)
edits = []  # (描述, 原文)


def cut(text, desc):
    """校验 text 在 s 中唯一后登记删除。"""
    n = s.count(text)
    if n != 1:
        print(f'  ✗ 跳过 [{desc}]：匹配 {n} 次（要求恰好 1 次）')
        return False
    edits.append((desc, text))
    print(f'  ✓ [{desc}] 长度 {len(text)}')
    return True


print('=== 1. 菜单项（Vlt）===')
# 仪表盘（整项，含前导逗号由后处理解决；这里先删项本身）
cut('{id:"dashboard",title:"nav:dashboard",label:"",href:"/",icon:Q.jsx(tf,{size:18})},', 'menu: 仪表盘')

# 系统管理下的 5 项
for mid, title in [
    ('plugin-management', 'nav:pluginManagement'),
    ('theme-config', 'nav:themeConfig'),
    ('notice-management', 'nav:noticeManagement'),
    ('payment-config', 'nav:paymentConfig'),
    ('knowledge-management', 'nav:knowledgeManagement'),
]:
    # 这些项在数组中间，后面跟逗号
    for suffix in (',', ''):
        pat = f'{{id:"{mid}",title:"{title}",label:"",href:"/config/{mid.replace("-management","").replace("-config","")}",icon:Q.jsx('
        m = re.search(re.escape(pat) + r'[A-Za-z_$][A-Za-z0-9_$]*,\{size:18\}\)\}' + re.escape(suffix), s)
        if m:
            cut(m.group(0), f'menu: {mid}')
            break
    else:
        print(f'  ✗ 未定位 menu: {mid}')

# 订阅管理整组（顶层项）—— 边界：从 {id:"subscription-management" 到 gift-card 项的 }]} 结束
m = re.search(
    r'\{id:"subscription-management",title:"nav:subscriptionManagement",label:"",href:"",icon:Q\.jsx\([A-Za-z_$][A-Za-z0-9_$]*,\{size:18\}\),sub:\[.*?gift-card-management.*?\]\},',
    s,
    re.S,
)
if m:
    cut(m.group(0), 'menu: 订阅管理（整组）')
else:
    print('  ✗ 未定位 订阅管理整组')

# 工单管理
m = re.search(r',\{id:"ticket-management",title:"nav:ticketManagement",label:"",href:"/user/ticket",icon:Q\.jsx\([A-Za-z_$][A-Za-z0-9_$]*,\{size:18\}\)\}', s)
if m:
    cut(m.group(0), 'menu: 工单管理')
else:
    print('  ✗ 未定位 工单管理')

# 服务器管理（machine 集群，后端已删）
m = re.search(r'\{id:"machine-management",title:"nav:machineManagement",label:"",href:"/server/machine",icon:Q\.jsx\([A-Za-z_$][A-Za-z0-9_$]*,\{size:18\}\)\},', s)
if m:
    cut(m.group(0), 'menu: 服务器管理')
else:
    print('  ✗ 未定位 服务器管理')

print()
print('=== 2. 路由项（zQe）===')
# 仪表盘：根布局的 index:!0 子项 —— 不删除，改为重定向到节点管理
# （直接删会让 / 无匹配 → 白屏；改为 Navigate 更友好）
m = re.search(
    r'\{index:!0,lazy:async\(\)=>\(\{Component:\(await xp\(async\(\)=>\{const\{default:e\}=await Promise\.resolve\(\)\.then\(\(\)=>\$Gt\);return\{default:e\}\},void 0,import\.meta\.url\)\)\.default\}\)\}',
    s,
)
if m:
    # 用 Navigate 替换：重定向到 /server/manage
    redirect_route = '{index:!0,element:Q.jsx(jg,{to:"/server/manage",replace:!0})}'
    edits.append(('REDIRECT: / → /server/manage', m.group(0), redirect_route))
    print(f'  ↻ [仪表盘路由] 改为重定向 → /server/manage（原长 {len(m.group(0))}）')
else:
    print('  ✗ 未定位 仪表盘路由')

# config 下要删的子路由（每项形如 {path:"xxx",lazy:...},）
for p in ['payment', 'plugin', 'plugin/menu-demo', 'plugin/crud-demo', 'theme', 'notice', 'knowledge']:
    # 匹配 {path:"xxx",lazy:...} 后跟逗号或 ]
    m = re.search(r'\{path:"' + re.escape(p) + r'",lazy:async\(\)=>\(\{Component:\(await xp\(async\(\)=>\{const\{default:e\}=await Promise\.resolve\(\)\.then\(\(\)=>[A-Za-z_$][A-Za-z0-9_$]*\);return\{default:e\}\},void 0,import\.meta\.url\)\)\.default\}\)\},?', s)
    if m:
        cut(m.group(0), f'route: config/{p}')
    else:
        print(f'  ✗ 未定位 route: config/{p}')

# finance 整棵子树
m = re.search(r'\{path:"finance",errorElement:Q\.jsx\(Df,\{\}\),children:\[.*?\]\},', s, re.S)
if m:
    cut(m.group(0), 'route: finance（整棵）')
else:
    print('  ✗ 未定位 finance 子树')

# user 下 ticket
m = re.search(r',\{path:"ticket",lazy:async\(\)=>\(\{Component:\(await xp\(async\(\)=>\{const\{default:e\}=await Promise\.resolve\(\)\.then\(\(\)=>x9t\);return\{default:e\}\},void 0,import\.meta\.url\)\)\.default\}\)\}', s)
if m:
    cut(m.group(0), 'route: user/ticket')
else:
    print('  ✗ 未定位 user/ticket')

# server 下 machine
m = re.search(r'\{path:"machine",lazy:async\(\)=>\(\{Component:\(await xp\(async\(\)=>\{const\{default:e\}=await Promise\.resolve\(\)\.then\(\(\)=>B3t\);return\{default:e\}\},void 0,import\.meta\.url\)\)\.default\}\)\},', s)
if m:
    cut(m.group(0), 'route: server/machine')
else:
    print('  ✗ 未定位 server/machine')

print()
print(f'=== 结果：共 {len(edits)} 处变更 ===')
if DRY:
    print('（dry-run，未写盘。加 --apply 执行）')
    sys.exit(0)

for item in edits:
    if len(item) == 3:
        desc, old, new = item
        s = s.replace(old, new, 1)
    else:
        desc, old = item
        s = s.replace(old, '', 1)

print(f'原始 {orig_len} 字节 → 现在 {len(s)} 字节（变化 {len(s) - orig_len:+d}）')
shutil.copy2(F, F + '.bak-menu-removal')
with open(F, 'w', encoding='utf-8', newline='') as f:
    f.write(s)
print(f'已写回 {F}，备份 {F}.bak-menu-removal')
