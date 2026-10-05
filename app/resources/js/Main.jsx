import React, { useEffect, useState, useCallback } from 'react';
import { api } from './api';
import CsDesk from './CsDesk';
import CateringStation from './CateringStation';
import CrewLoading from './CrewLoading';
import OrderTimeline from './OrderTimeline';

const TABS = [
    ['cs', '客服台'],
    ['catering', '配餐站'],
    ['crew', '机组装机'],
    ['orders', '订单记录'],
];

export default function App() {
    const [tab, setTab] = useState('cs');
    const [flights, setFlights] = useState([]);
    const [mealTypes, setMealTypes] = useState({});
    const [resolutions, setResolutions] = useState({});
    const [error, setError] = useState('');

    const loadFlights = useCallback(() => {
        api.get('/mock/flights').then(setFlights).catch((e) => setError(e.message));
    }, []);

    useEffect(() => {
        loadFlights();
        api.get('/meta/meal-types').then((m) => {
            setMealTypes(m.types);
            setResolutions(m.shortage_resolutions);
        }).catch((e) => setError(e.message));
    }, [loadFlights]);

    const ctx = { flights, mealTypes, resolutions, reloadFlights: loadFlights, setError };

    return (
        <div>
            <header className="app-header">
                <h1>特殊餐食确认与装机核对</h1>
                <span className="sub">客服确认 → 配餐贴标 → 机组扫码装机 · 同一份记录全程跟踪</span>
            </header>
            <nav className="tabs">
                {TABS.map(([key, label]) => (
                    <button key={key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>
                        {label}
                    </button>
                ))}
            </nav>
            {error && <div className="error-box" onClick={() => setError('')}>{error}（点击关闭）</div>}
            {tab === 'cs' && <CsDesk {...ctx} />}
            {tab === 'catering' && <CateringStation {...ctx} />}
            {tab === 'crew' && <CrewLoading {...ctx} />}
            {tab === 'orders' && <OrderTimeline {...ctx} />}
        </div>
    );
}
