# 航班特殊餐食确认与装机核对系统

旅客申请特殊餐后，**客服、配餐员、机组沿同一份记录**依次确认，确保餐食最终送到正确的航班和座位。

- 后端：PHP 8.4 + Laravel 13 + Eloquent + SQLite
- 前端：React 18 + Vite（构建产物由 Laravel 直接托管）
- 航班动态与扫码枪均为本地模拟接口

## 一键启动

```bash
./start.sh            # 默认 8000 端口
./start.sh --port 9000
```

脚本自动完成：定位/下载便携 PHP → Composer 依赖 → 前端构建 → 生成密钥 → SQLite 迁移与演示数据 → 启动服务。
打开 http://127.0.0.1:8000 即可在「客服确认 / 配餐制作 / 装机核对」三个视图间切换。

## 业务链路

```
旅客申请(requested)
  → 客服确认类别(confirmed，自动生成制作任务)
  → 配餐员制作并贴标(meals.prepared，餐食编号 ML-XXXXXX)
  → 机组装机扫码(loaded，scan_events 留痕)
```

每一步都写入 `order_events`，订单详情页可见全程留痕。

## 关键规则

| 规则 | 实现 |
| --- | --- |
| 改签/换座后餐食跟随订单 | 未装机餐食与制作任务同步改属新航班/座位，标签版本 +1 需重贴 |
| 旧航班不能继续出库 | 扫码校验餐食当前归属航班，不符返回 `wrong_flight` |
| 取消释放待制作任务 | 任务置 `cancelled`，已备餐置 `voided` 且扫码拒绝 |
| 受限餐不得用普通餐替代 | 过敏/医疗/宗教类只能替代为其他受限类别，否则 422 |
| 重复扫码只计一份 | 幂等返回 `duplicate`，实收量不增加 |
| 少餐缺口须确认处理 | 装机页显示应收/实收/缺口，缺口需填写处理方案确认留痕 |

## 验证测试

```bash
.runtime/php artisan test     # 或系统 php artisan test
```

`tests/Feature/MealFlowTest.php` 覆盖：全链路、改签后标签/任务归属一致且旧航班拒绝出库、换座同步、受限餐替代拦截、重复扫码幂等且缺口保留、取消释放任务、缺口确认、已装机锁定等 11 个用例。

## 主要接口（本地模拟）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/flights` | 航班动态（模拟） |
| POST | `/api/orders` | 旅客申请特殊餐 |
| POST | `/api/orders/{id}/confirm` | 客服确认类别，生成制作任务 |
| POST | `/api/orders/{id}/rebook` | 改签/换座（未装机餐食跟随） |
| POST | `/api/orders/{id}/cancel` | 取消并释放待制作任务 |
| POST | `/api/orders/{id}/substitute` | 餐食替代（受限类拦截普通餐） |
| GET | `/api/tasks?flight_id=` | 配餐任务列表 |
| POST | `/api/tasks/{id}/produce` | 制作完成并贴标，返回餐食编号 |
| POST | `/api/scan` | 扫码枪（模拟）：`{code, flight_id}` |
| GET | `/api/flights/{id}/loading` | 装机页：应收/实收/缺口/明细 |
| POST | `/api/flights/{id}/shortage-ack` | 少餐缺口确认处理 |
