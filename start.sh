#!/usr/bin/env bash
# 特殊餐食确认与装机核对系统 —— 一键启动
# 用法: ./start.sh [端口]   (默认 8000)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
APP="$ROOT/app"
PORT="${1:-8000}"

# PHP: 优先系统 php，否则用仓库内置的静态 PHP
if command -v php >/dev/null 2>&1; then
    PHP=php
elif [ -x "$ROOT/.tools/php" ]; then
    PHP="$ROOT/.tools/php"
else
    echo "未找到 PHP。请安装 PHP 8.3+ 或将静态 PHP 放到 .tools/php" >&2
    exit 1
fi
echo "使用 PHP: $($PHP -v | head -1)"

cd "$APP"

# 依赖（已装则跳过）
if [ ! -d vendor ]; then
    echo "安装 Composer 依赖…"
    $PHP "$ROOT/.tools/composer.phar" install --no-interaction --quiet
fi
if [ ! -f .env ]; then
    cp .env.example .env
    $PHP artisan key:generate --quiet
fi
if [ ! -d node_modules ]; then
    echo "安装 npm 依赖…"
    npm install --ignore-scripts --silent
fi
if [ ! -f public/build/manifest.json ]; then
    echo "构建前端…"
    npm run build
fi

# 数据库：全新迁移 + 演示数据
echo "初始化 SQLite 数据库…"
$PHP artisan migrate:fresh --seed

echo ""
echo "✔ 系统已启动: http://localhost:$PORT"
echo "  演示条码见上方 seed 输出（也可在 配餐站 重新打印标签获取）"
echo ""
exec $PHP artisan serve --host=0.0.0.0 --port="$PORT"
