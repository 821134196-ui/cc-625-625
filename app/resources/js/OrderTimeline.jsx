import React, { useEffect, useState, useCallback } from 'react';
import { api } from './api';

const ROLE_LABEL = { cs: '客服', catering: '配餐员', crew: '机组', system: '系统' };
const EVENT_LABEL = {
    order_created: '创建申请',
    cs_confirmed: '确认餐食类别',
    task_created: '生成配餐任务',
    task_started: '开始制作',
    label_printed: '打印标签',
    label_voided: '标签作废',
    dispatched: '出库',
    received_onboard: '装机接收',
    rebooked: '改签航班',
    reseated: '更换座位',
    task_reset: '任务跟随调整',
    task_released: '任务释放',
    order_cancelled: '取消申请',
    shortage_resolved: '缺餐处理确认',
    substitution_rejected: '替代被拒绝',
};

export default function OrderTimeline({ setError }) {
    const [orders, setOrders] = useState([]);
    const [selected, setSelected] = useState(null);

    const load = useCallback(() => {
        api.get('/orders').then(setOrders).catch((e) => setError(e.message));
    }, [setError]);
    useEffect(load, [load]);

    const open = (id) => {
        api.get(`/orders/${id}`).then(setSelected).catch((e) => setError(e.message));
    };

    return (
        <div className="section-flex">
            <div className="card" style={{ flex: 1 }}>
                <h2>订单列表 <button className="btn" onClick={load}>刷新</button></h2>
                <table>
                    <thead><tr><th>单号</th><th>旅客</th><th>航班</th><th>座位</th><th>餐别</th><th>状态</th></tr></thead>
                    <tbody>
                        {orders.map((o) => (
                            <tr key={o.id} onClick={() => open(o.id)} style={{ cursor: 'pointer' }}>
                                <td>{o.order_no}</td>
                                <td>{o.passenger_name}</td>
                                <td>{o.flight_no}</td>
                                <td>{o.seat_no}</td>
                                <td>{o.meal_type} {o.meal_type_name}
                                    {o.is_restricted && <span className="restricted-flag">受限</span>}
                                </td>
                                <td><span className="badge blue">{o.status}</span></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {selected && (
                <div className="card" style={{ flex: 1 }}>
                    <h2>{selected.order_no} 确认链</h2>
                    <p className="muted">
                        {selected.passenger_name} · {selected.flight_no} · 座位 {selected.seat_no} ·
                        {' '}{selected.meal_type} {selected.meal_type_name}
                        {selected.is_restricted && ' · 受限餐'}
                    </p>
                    {selected.items.length > 0 && (
                        <>
                            <h3>标签历史</h3>
                            <table>
                                <thead><tr><th>条码</th><th>版本</th><th>座位</th><th>状态</th></tr></thead>
                                <tbody>
                                    {selected.items.map((i) => (
                                        <tr key={i.meal_code}>
                                            <td><code>{i.meal_code}</code></td>
                                            <td>v{i.label_version}</td>
                                            <td>{i.seat_no}</td>
                                            <td>{i.status === 'void'
                                                ? <span className="badge red">已作废</span>
                                                : <span className="badge green">{i.status}</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </>
                    )}
                    <h3>事件时间线</h3>
                    <ul className="timeline">
                        {selected.events.map((e, idx) => (
                            <li key={idx}>
                                <span className="role">{ROLE_LABEL[e.actor_role] || e.actor_role}</span>
                                {EVENT_LABEL[e.event] || e.event}
                                <span className="at">{e.at}</span>
                                {e.payload && Object.keys(e.payload).length > 0 && (
                                    <div className="payload">{JSON.stringify(e.payload)}</div>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
