import React, { useEffect, useState, useCallback } from 'react';
import { api } from './api';

const TASK_STATUS = {
    pending: ['待制作', 'amber'],
    in_progress: ['制作中', 'blue'],
    labeled: ['已贴标', 'purple'],
    done: ['已出库', 'green'],
    cancelled: ['已取消', 'gray'],
};

export default function CateringStation({ flights, setError }) {
    const [tasks, setTasks] = useState([]);
    const [flightFilter, setFlightFilter] = useState('');
    const [label, setLabel] = useState(null);

    const load = useCallback(() => {
        const q = flightFilter ? `?flight_id=${flightFilter}` : '';
        api.get(`/catering/tasks${q}`).then(setTasks).catch((e) => setError(e.message));
    }, [flightFilter, setError]);
    useEffect(load, [load]);

    const act = (p) => p.then(load).catch((e) => setError(e.message));

    const printLabel = (taskId) => {
        api.post(`/catering/tasks/${taskId}/label`)
            .then((l) => { setLabel(l); load(); })
            .catch((e) => setError(e.message));
    };

    return (
        <div>
            <div className="card">
                <h2>配餐任务</h2>
                <label className="field">按航班过滤
                    <select value={flightFilter} onChange={(e) => setFlightFilter(e.target.value)}>
                        <option value="">全部</option>
                        {flights.map((f) => <option key={f.id} value={f.id}>{f.flight_no}</option>)}
                    </select>
                </label>
                <button className="btn" onClick={load}>刷新</button>
                <table>
                    <thead>
                        <tr><th>单号</th><th>旅客</th><th>航班</th><th>座位</th><th>餐别</th><th>任务状态</th><th>标签版本</th><th>当前条码</th><th>操作</th></tr>
                    </thead>
                    <tbody>
                        {tasks.map((t) => {
                            const [label_, color] = TASK_STATUS[t.status] || [t.status, 'gray'];
                            return (
                                <tr key={t.id}>
                                    <td>{t.order_no}</td>
                                    <td>{t.passenger_name}</td>
                                    <td>{t.flight_no}</td>
                                    <td>{t.seat_no}</td>
                                    <td>{t.meal_type} {t.meal_type_name}
                                        {t.is_restricted && <span className="restricted-flag">受限</span>}
                                    </td>
                                    <td><span className={`badge ${color}`}>{label_}</span></td>
                                    <td>v{t.label_version}</td>
                                    <td>{t.active_item ? <code>{t.active_item.meal_code}</code> : <span className="muted">未打印</span>}</td>
                                    <td>
                                        {t.status === 'pending' && (
                                            <button className="btn primary" onClick={() => act(api.post(`/catering/tasks/${t.id}/start`))}>开始制作</button>
                                        )}
                                        {(t.status === 'pending' || t.status === 'in_progress' || t.status === 'labeled') && (
                                            <button className="btn" onClick={() => printLabel(t.id)}>
                                                {t.label_version > 0 ? '重打标签' : '打印标签'}
                                            </button>
                                        )}
                                        {t.status === 'labeled' && t.active_item && (
                                            <button className="btn primary"
                                                onClick={() => act(api.post(`/catering/items/${t.active_item.id}/dispatch`))}>
                                                出库
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {label && (
                <div className="card">
                    <h2>标签预览（打印贴于餐盒）</h2>
                    <div className="label-preview">
                        <div className="code">{label.meal_code}</div>
                        <div className="row">航班 {label.flight_no} · 座位 {label.seat_no} · 标签 v{label.label_version}</div>
                        <div className="row">{label.meal_type} {label.meal_type_name} · {label.passenger_name}</div>
                        {label.is_restricted && <div className="row warn">⚠ 过敏/医疗/宗教受限餐 — 不得以普通餐替代</div>}
                    </div>
                    <p className="muted">改签/换座后旧标签自动作废，需重新打印；旧条码扫码将被拒绝。</p>
                </div>
            )}
        </div>
    );
}
