#!/usr/bin/env bash
# 航班特殊餐食确认与装机核对系统 —— 一键启动
# 用法: ./start.sh [--port 8000]
set -euo pipefail
cd "$(dirname "$0")"

PORT="${1:-8000}"
[ "${1:-}" = "--port" ] && PORT="${2:-8000}"

# ---- 定位 PHP（优先系统 PHP，其次本地便携运行时，最后自动下载） ----
PHP=""
if command -v php >/dev/null 2>&1; then
  PHP="php"
elif [ -x .runtime/php ]; then
  PHP=".runtime/php"
else
  echo "未找到 PHP，正在下载便携运行时（static-php 8.4）..."
  mkdir -p .runtime
  curl -fsSL -o .runtime/php.tar.gz \
    "https://dl.static-php.dev/static-php-cli/bulk/php-8.4.12-cli-linux-x86_64.tar.gz"
  tar -xzf .runtime/php.tar.gz -C .runtime
  rm .runtime/php.tar.gz
  chmod +x .runtime/php
  PHP=".runtime/php"
fi
echo "使用 PHP: $($PHP -r 'echo PHP_VERSION;')"

# ---- Composer 依赖 ----
if [ ! -d vendor ]; then
  if [ ! -f .runtime/composer.phar ]; then
    echo "下载 Composer..."
    curl -fsSL -o .runtime/composer.phar \
      "https://getcomposer.org/download/latest-stable/composer.phar"
  fi
  $PHP .runtime/composer.phar install --no-interaction --prefer-dist
fi

# ---- 前端构建 ----
if [ ! -f public/spa/assets/app.js ]; then
  if command -v npm >/dev/null 2>&1; then
    [ -d node_modules ] || npm install --no-audit --no-fund
    npm run build
  else
    echo "警告: 未找到 npm，跳过前端构建（public/spa 缺失时页面不可用）"
  fi
fi

# ---- 环境 & 数据库 ----
[ -f .env ] || cp .env.example .env
$PHP artisan key:generate --force --quiet
mkdir -p database
touch database/database.sqlite
if [ -s database/database.sqlite ]; then
  # 已有数据：仅执行新增迁移，保留业务数据
  $PHP artisan migrate --force --quiet
else
  # 首次启动：建表并写入演示数据
  $PHP artisan migrate:fresh --seed --force --quiet
fi

echo ""
echo "  ✈  系统已启动:  http://127.0.0.1:${PORT}"
echo "     客服确认 / 配餐制作 / 装机核对 三个视图在同一页面切换"
echo "     运行验证测试:  $PHP artisan test"
echo ""
exec $PHP artisan serve --host=0.0.0.0 --port="${PORT}"
