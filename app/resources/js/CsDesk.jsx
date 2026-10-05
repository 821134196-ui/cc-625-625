import React, { useEffect, useState, useCallback } from 'react';
import { api } from './api';

const STATUS_LABEL = {
    pending_cs: ['待客服确认', 'amber'],
    confirmed: ['已确认', 'blue'],
    loaded: ['已装机', 'green'],
    cancelled: ['已取消', 'gray'],
};

export default function CsDesk({ flights, mealTypes, setError }) {
    const [orders, setOrders] = useState([]);
    const [form, setForm] = useState({ passenger_name: '', pnr: '', flight_id: '', seat_no: '', meal_type: 'CHML', notes: '' });
    const [confirmType, setConfirmType] = useState({});
    const [rebookFlight, setRebookFlight] = useState({});
    const [reseatSeat, setReseatSeat] = useState({});

    const load = useCallback(() => {
        api.get('/orders').then(setOrders).catch((e) => setError(e.message));
    }, [setError]);
    useEffect(load, [load]);

    const act = (p) => p.then(load).catch((e) => setError(e.message));

    const create = () => act(api.post('/orders', { ...form, flight_id: Number(form.flight_id) })
        .then(() => setForm({ ...form, passenger_name: '', pnr: '', seat_no: '' })));

    return (
        <div>
            <div className="card">
                <h2>录入特殊餐申请</h2>
                <label className="field">旅客 <input value={form.passenger_name} onChange={(e) => setForm({ ...form, passenger_name: e.target.value })} /></label>
                <label className="field">PNR <input value={form.pnr} onChange={(e) => setForm({ ...form, pnr: e.target.value })} style={{ width: 90 }} /></label>
                <label className="field">航班
                    <select value={form.flight_id} onChange={(e) => setForm({ ...form, flight_id: e.target.value })}>
                        <option value="">选择</option>
                        {flights.map((f) => <option key={f.id} value={f.id}>{f.flight_no} {f.origin}→{f.destination}</option>)}
                    </select>
                </label>
                <label className="field">座位 <input value={form.seat_no} onChange={(e) => setForm({ ...form, seat_no: e.target.value })} style={{ width: 70 }} /></label>
                <label className="field">申请类别
                    <select value={form.meal_type} onChange={(e) => setForm({ ...form, meal_type: e.target.value })}>
                        {Object.entries(mealTypes).map(([code, t]) => (
                            <option key={code} value={code}>{code} {t.name}{t.restricted ? '（受限）' : ''}</option>
                        ))}
                    </select>
                </label>
                <button className="btn primary" disabled={!form.passenger_name || !form.pnr || !form.flight_id || !form.seat_no} onClick={create}>提交申请</button>
            </div>

            <div className="card">
                <h2>申请单列表 <button className="btn" onClick={load}>刷新</button></h2>
                <table>
                    <thead>
                        <tr><th>单号</th><th>旅客</th><th>航班</th><th>座位</th><th>餐别</th><th>状态</th><th>操作</th></tr>
                    </thead>
                    <tbody>
                        {orders.map((o) => {
                            const [label, color] = STATUS_LABEL[o.status] || [o.status, 'gray'];
                            return (
                                <tr key={o.id}>
                                    <td>{o.order_no}</td>
                                    <td>{o.passenger_name}<br /><span className="muted">{o.pnr}</span></td>
                                    <td>{o.flight_no}</td>
                                    <td>{o.seat_no}</td>
                                    <td>
                                        {o.meal_type} {o.meal_type_name}
                                        {o.is_restricted && <span className="restricted-flag">受限·不可替代</span>}
                                    </td>
                                    <td><span className={`badge ${color}`}>{label}</span></td>
                                    <td>
                                        {o.status === 'pending_cs' && (
                                            <>
                                                <select value={confirmType[o.id] || o.meal_type}
                                                    onChange={(e) => setConfirmType({ ...confirmType, [o.id]: e.target.value })}>
                                                    {Object.entries(mealTypes).map(([code, t]) => (
                                                        <option key={code} value={code}>{code} {t.name}</option>
                                                    ))}
                                                </select>
                                                <button className="btn primary"
                                                    onClick={() => act(api.post(`/orders/${o.id}/confirm`, { meal_type: confirmType[o.id] || o.meal_type }))}>
                                                    确认类别
                                                </button>
                                            </>
                                        )}
                                        {(o.status === 'pending_cs' || o.status === 'confirmed') && (
                                            <>
                                                <select value={rebookFlight[o.id] || ''}
                                                    onChange={(e) => setRebookFlight({ ...rebookFlight, [o.id]: e.target.value })}>
                                                    <option value="">改签航班…</option>
                                                    {flights.filter((f) => f.id !== o.flight_id).map((f) => (
                                                        <option key={f.id} value={f.id}>{f.flight_no}</option>
                                                    ))}
                                                </select>
                                                <button className="btn" disabled={!rebookFlight[o.id]}
                                                    onClick={() => act(api.post(`/orders/${o.id}/rebook`, { flight_id: Number(rebookFlight[o.id]) }))}>
                                                    改签
                                                </button>
                                                <input placeholder="新座位" style={{ width: 60 }}
                                                    value={reseatSeat[o.id] || ''}
                                                    onChange={(e) => setReseatSeat({ ...reseatSeat, [o.id]: e.target.value })} />
                                                <button className="btn" disabled={!reseatSeat[o.id]}
                                                    onClick={() => act(api.post(`/orders/${o.id}/reseat`, { seat_no: reseatSeat[o.id] }))}>
                                                    换座
                                                </button>
                                                <button className="btn danger"
                                                    onClick={() => act(api.post(`/orders/${o.id}/cancel`))}>
                                                    取消申请
                                                </button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
