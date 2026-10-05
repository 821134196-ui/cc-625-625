import React, { useEffect, useState, useCallback, useRef } from 'react';
import { api } from './api';

const SCAN_TEXT = {
    accepted:       ['✓ 接收成功', 'accepted'],
    duplicate:      ['⚠ 重复扫码：该餐已接收，实收量不变', 'duplicate'],
    wrong_flight:   ['✗ 航班不符：该餐不属于本航班', 'error'],
    void_label:     ['✗ 标签已作废（改签/换座/取消后需重打）', 'error'],
    not_dispatched: ['✗ 该餐尚未出库，不能装机', 'error'],
    not_found:      ['✗ 条码不存在', 'error'],
};

export default function CrewLoading({ flights, resolutions, setError }) {
    const [flightId, setFlightId] = useState('');
    const [summary, setSummary] = useState(null);
    const [code, setCode] = useState('');
    const [scanMsg, setScanMsg] = useState(null);
    const [resolveForm, setResolveForm] = useState({});
    const inputRef = useRef(null);

    const load = useCallback((id = flightId) => {
        if (!id) return;
        api.get(`/flights/${id}/loading`).then(setSummary).catch((e) => setError(e.message));
    }, [flightId, setError]);

    useEffect(() => { if (flightId) load(flightId); }, [flightId, load]);

    const scan = () => {
        if (!code.trim()) return;
        api.post(`/flights/${flightId}/scan`, { meal_code: code.trim() })
            .then((s) => {
                setSummary(s);
                const [text, cls] = SCAN_TEXT[s.scan_result] || [s.scan_result, 'error'];
                setScanMsg({ text: `${text}（${s.scanned_code}${s.seat_no ? ` · 座位 ${s.seat_no}` : ''}）`, cls });
                setCode('');
                inputRef.current?.focus();
            })
            .catch((e) => setError(e.message));
    };

    const resolve = (caseId) => {
        const f = resolveForm[caseId] || {};
        api.post(`/shortages/${caseId}/resolve`, { resolution: f.resolution, note: f.note })
            .then(() => load())
            .catch((e) => setError(e.message));
    };

    const closeFlight = () => {
        api.post(`/flights/${flightId}/close`)
            .then(() => { setScanMsg({ text: '装机已关闭', cls: 'accepted' }); load(); })
            .catch((e) => setError(e.message));
    };

    return (
        <div>
            <div className="card">
                <h2>选择航班</h2>
                <select value={flightId} onChange={(e) => { setFlightId(e.target.value); setSummary(null); setScanMsg(null); }}>
                    <option value="">选择航班…</option>
                    {flights.map((f) => (
                        <option key={f.id} value={f.id}>{f.flight_no} {f.origin}→{f.destination} {f.departure_at}（{f.status}）</option>
                    ))}
                </select>
                {summary && <button className="btn" onClick={() => load()}>刷新</button>}
            </div>

            {summary && (
                <>
                    <div className="stats">
                        <div className="stat"><div className="num">{summary.expected_count}</div><div className="label">应收（已出库）</div></div>
                        <div className="stat ok"><div className="num">{summary.received_count}</div><div className="label">实收（已扫码）</div></div>
                        <div className={`stat ${summary.shortage_count > 0 ? 'warn' : ''}`}>
                            <div className="num">{summary.shortage_count}</div><div className="label">缺餐缺口</div>
                        </div>
                    </div>

                    <div className="card">
                        <h2>扫码装机</h2>
                        <div className="scan-row">
                            <input
                                ref={inputRef}
                                placeholder="扫描或输入餐食条码，如 M-XXXXXX"
                                value={code}
                                onChange={(e) => setCode(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && scan()}
                                autoFocus
                            />
                            <button className="btn primary" onClick={scan}>确认扫码</button>
                        </div>
                        {scanMsg && <div className={`scan-result ${scanMsg.cls}`}>{scanMsg.text}</div>}

                        <table>
                            <thead>
                                <tr><th>条码</th><th>座位</th><th>旅客</th><th>餐别</th><th>状态</th></tr>
                            </thead>
                            <tbody>
                                {summary.items.map((i) => (
                                    <tr key={i.meal_code}>
                                        <td><code>{i.meal_code}</code></td>
                                        <td>{i.seat_no}</td>
                                        <td>{i.passenger_name}</td>
                                        <td>{i.meal_type} {i.meal_type_name}
                                            {i.is_restricted && <span className="restricted-flag">受限</span>}
                                        </td>
                                        <td>{i.status === 'received'
                                            ? <span className="badge green">已装机</span>
                                            : <span className="badge amber">待接收</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="card">
                        <h2>缺餐缺口（须确认处理后方可关闭装机）</h2>
                        {summary.open_shortages.length === 0 && <p className="muted">当前无未处理缺口。</p>}
                        {summary.open_shortages.map((c) => (
                            <div key={c.id} className="card" style={{ background: '#fffbeb', marginBottom: 8 }}>
                                <strong>{c.order_no}</strong> · 座位 {c.seat_no} · {c.meal_type} {c.meal_type_name}
                                {c.is_restricted && <span className="restricted-flag">受限餐，不得以普通餐替代</span>}
                                <div style={{ marginTop: 8 }}>
                                    <select
                                        value={resolveForm[c.id]?.resolution || ''}
                                        onChange={(e) => setResolveForm({ ...resolveForm, [c.id]: { ...resolveForm[c.id], resolution: e.target.value } })}>
                                        <option value="">选择处理方式…</option>
                                        {Object.entries(resolutions).map(([key, label]) => (
                                            <option key={key} value={key}
                                                disabled={key === 'substitute_regular' && c.is_restricted}>
                                                {label}{key === 'substitute_regular' && c.is_restricted ? '（受限餐不可用）' : ''}
                                            </option>
                                        ))}
                                    </select>
                                    <input placeholder="备注"
                                        value={resolveForm[c.id]?.note || ''}
                                        onChange={(e) => setResolveForm({ ...resolveForm, [c.id]: { ...resolveForm[c.id], note: e.target.value } })} />
                                    <button className="btn primary"
                                        disabled={!resolveForm[c.id]?.resolution}
                                        onClick={() => resolve(c.id)}>确认处理</button>
                                </div>
                            </div>
                        ))}
                        <button className="btn primary" onClick={closeFlight}
                            disabled={summary.open_shortages.length > 0}>
                            关闭装机{summary.open_shortages.length > 0 ? `（还有 ${summary.open_shortages.length} 个缺口未处理）` : ''}
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
