import React, { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../api.js'

const RESULT_STYLE = {
  accepted: ['ok', '✓ 已接收'],
  duplicate: ['warn', '↺ 重复扫码'],
  wrong_flight: ['err', '✕ 航班不符'],
  not_found: ['err', '✕ 编号不存在'],
  voided: ['err', '✕ 餐食已作废'],
}

const STATE_ZH = { loaded: '已装机', ready: '待装机', awaiting_production: '待制作' }

export default function LoadingPage({ flights, notify, flightLabel }) {
  const [flightId, setFlightId] = useState('')
  const [summary, setSummary] = useState(null)
  const [code, setCode] = useState('')
  const [lastScan, setLastScan] = useState(null)
  const [ackForm, setAckForm] = useState({ handler: '', note: '' })
  const inputRef = useRef(null)

  const reload = useCallback(async () => {
    if (!flightId) { setSummary(null); return }
    setSummary(await api.get(`/api/flights/${flightId}/loading`))
  }, [flightId])

  useEffect(() => {
    reload().catch((e) => notify(e.message, 'err'))
  }, [reload])

  useEffect(() => {
    inputRef.current?.focus()
  }, [flightId])

  const scan = async (e) => {
    e.preventDefault()
    if (!code.trim() || !flightId) return
    try {
      const res = await api.post('/api/scan', { code: code.trim(), flight_id: Number(flightId) })
      setLastScan(res)
    } catch (err) {
      // 422 的业务结果（错航班/作废/不存在）也作为扫码回执展示
      setLastScan(err.payload || { result: 'not_found', message: err.message })
    }
    setCode('')
    await reload()
    inputRef.current?.focus()
  }

  const ack = async (e) => {
    e.preventDefault()
    try {
      await api.post(`/api/flights/${flightId}/shortage-ack`, ackForm)
      setAckForm({ handler: '', note: '' })
      await reload()
      notify('缺口处理已确认并留痕')
    } catch (err) {
      notify(err.message, 'err')
    }
  }

  const flight = flights.find((f) => String(f.id) === String(flightId))

  return (
    <div className="page-grid">
      <section className="card wide">
        <div className="card-head">
          <h2>装机核对</h2>
          <select value={flightId} onChange={(e) => { setFlightId(e.target.value); setLastScan(null) }}>
            <option value="">选择航班</option>
            {flights.map((f) => <option key={f.id} value={f.id}>{flightLabel(f)}</option>)}
          </select>
        </div>

        {summary && (
          <>
            <div className="counters">
              <div className="counter"><span>应收</span><b>{summary.expected}</b></div>
              <div className="counter ok"><span>实收</span><b>{summary.received}</b></div>
              <div className={`counter ${summary.shortage > 0 ? 'bad' : ''}`}>
                <span>缺口</span><b>{summary.shortage}</b>
              </div>
            </div>

            <form className="scan-box" onSubmit={scan}>
              <input
                ref={inputRef}
                value={code}
                onChange={(e) => setCode(e.target.value.toUpperCase())}
                placeholder="扫描或输入餐食编号，如 ML-000001"
                autoComplete="off"
              />
              <button className="btn primary" type="submit">接收</button>
            </form>

            {lastScan && (
              <div className={`scan-result ${RESULT_STYLE[lastScan.result]?.[0] || 'err'}`}>
                <b>{RESULT_STYLE[lastScan.result]?.[1] || lastScan.result}</b>
                <span>{lastScan.message}</span>
              </div>
            )}

            {summary.shortage > 0 && (
              <div className="shortage-box">
                <b>缺餐 {summary.shortage} 份</b> — 请核实配餐交付并确认处理方案。
                <form className="ack-form" onSubmit={ack}>
                  <input required placeholder="确认人（如 乘务长-王莉）" value={ackForm.handler}
                    onChange={(e) => setAckForm({ ...ackForm, handler: e.target.value })} />
                  <input required placeholder="处理方案（如 落地后补送旅客）" value={ackForm.note}
                    onChange={(e) => setAckForm({ ...ackForm, note: e.target.value })} />
                  <button className="btn danger" type="submit">确认处理</button>
                </form>
              </div>
            )}

            {summary.acks.length > 0 && (
              <div className="acks">
                <h3>缺口处理记录</h3>
                {summary.acks.map((a) => (
                  <div key={a.id} className="ack-row">
                    <span className="tag warn">缺 {a.missing_count}</span>
                    <b>{a.handler}</b><span>{a.note}</span><time>{a.created_at}</time>
                  </div>
                ))}
              </div>
            )}

            <table className="table">
              <thead><tr><th>座位</th><th>旅客</th><th>餐食类别</th><th>餐食编号</th><th>状态</th></tr></thead>
              <tbody>
                {summary.items.map((it) => (
                  <tr key={it.ref} className={it.state === 'loaded' ? 'row-loaded' : ''}>
                    <td><b>{it.seat}</b></td>
                    <td>{it.passenger}</td>
                    <td>{it.meal_type} {it.meal_type_zh}{it.restricted && <span className="tag danger">受限</span>}</td>
                    <td className="mono">{it.meal_code || '—'}</td>
                    <td><span className={`pill ls-${it.state}`}>{STATE_ZH[it.state]}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}
        {!summary && <p className="empty">请选择航班开始装机核对（{flight ? '' : '航班动态来自本地模拟接口'}）</p>}
      </section>
    </div>
  )
}
