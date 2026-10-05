import React, { useCallback, useEffect, useState } from 'react'
import { api } from '../api.js'
import { Modal } from './ServicePage.jsx'

export default function KitchenPage({ flights, notify, flightLabel }) {
  const [flightId, setFlightId] = useState('')
  const [tasks, setTasks] = useState([])
  const [label, setLabel] = useState(null)

  const reload = useCallback(async () => {
    const url = flightId ? `/api/tasks?flight_id=${flightId}` : '/api/tasks'
    setTasks(await api.get(url))
  }, [flightId])

  useEffect(() => {
    reload().catch((e) => notify(e.message, 'err'))
  }, [reload])

  const produce = async (task) => {
    try {
      const meal = await api.post(`/api/tasks/${task.id}/produce`)
      await reload()
      setLabel(meal)
    } catch (e) {
      notify(e.message, 'err')
    }
  }

  const pending = tasks.filter((t) => t.status === 'pending')
  const done = tasks.filter((t) => t.status === 'done')
  const cancelled = tasks.filter((t) => t.status === 'cancelled')

  return (
    <div className="page-grid">
      <section className="card wide">
        <div className="card-head">
          <h2>待制作任务（{pending.length}）</h2>
          <select value={flightId} onChange={(e) => setFlightId(e.target.value)}>
            <option value="">全部航班</option>
            {flights.map((f) => <option key={f.id} value={f.id}>{flightLabel(f)}</option>)}
          </select>
        </div>
        {pending.length === 0 && <p className="empty">当前没有待制作任务</p>}
        {pending.length > 0 && (
          <table className="table">
            <thead><tr><th>航班</th><th>座位</th><th>餐食类别</th><th>旅客</th><th>备注</th><th>操作</th></tr></thead>
            <tbody>
              {pending.map((t) => (
                <tr key={t.id}>
                  <td>{t.flight?.flight_no}</td>
                  <td><b>{t.seat}</b></td>
                  <td>{t.meal_type?.code} {t.meal_type?.name_zh}</td>
                  <td>{t.order?.passenger_name}</td>
                  <td className="muted">{t.order?.note || '—'}</td>
                  <td><button className="btn primary" onClick={() => produce(t)}>制作并贴标</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section className="card wide">
        <h2>已贴标餐食（{done.length}）</h2>
        {done.length === 0 && <p className="empty">尚无已贴标餐食</p>}
        {done.length > 0 && (
          <table className="table">
            <thead><tr><th>航班</th><th>座位</th><th>餐食类别</th><th>旅客</th><th>标签</th></tr></thead>
            <tbody>
              {done.map((t) => (
                <tr key={t.id}>
                  <td>{t.flight?.flight_no}</td>
                  <td><b>{t.seat}</b></td>
                  <td>{t.meal_type?.code} {t.meal_type?.name_zh}</td>
                  <td>{t.order?.passenger_name}</td>
                  <td>
                    {t.order?.meal?.code
                      ? <span className="mono">{t.order.meal.code}{t.order.meal.label_version > 1 && <span className="tag warn">v{t.order.meal.label_version} 需重贴</span>}</span>
                      : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {cancelled.length > 0 && <p className="muted small">已取消释放的任务 {cancelled.length} 项（不再制作）。</p>}
      </section>

      {label && (
        <Modal title="标签已打印" onClose={() => setLabel(null)}>
          <div className="label-card">
            <div className="label-code">{label.code}</div>
            <div className="label-row"><span>航班</span><b>{label.flight?.flight_no}</b></div>
            <div className="label-row"><span>座位</span><b>{label.seat}</b></div>
            <div className="label-row"><span>类别</span><b>{label.meal_type?.code} {label.meal_type?.name_zh}</b></div>
            <div className="label-row"><span>旅客</span><b>{label.order?.passenger_name}</b></div>
            <div className="label-version">标签版本 v{label.label_version}</div>
          </div>
          <p className="muted">请将此标签贴于餐盒，机组装机时扫码接收。</p>
        </Modal>
      )}
    </div>
  )
}
