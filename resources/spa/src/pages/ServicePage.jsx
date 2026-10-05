import React, { useState } from 'react'
import { api } from '../api.js'
import { Pill } from '../App.jsx'

const RESTRICT_ZH = { allergy: '过敏', medical: '医疗', religious: '宗教', preference: '偏好', regular: '普通' }

export default function ServicePage({ flights, mealTypes, orders, reload, notify, flightLabel }) {
  const [form, setForm] = useState({ passenger_name: '', pnr: '', flight_id: '', seat: '', meal_type_code: 'VGML', note: '' })
  const [detail, setDetail] = useState(null)
  const [rebookFor, setRebookFor] = useState(null)
  const [confirmFor, setConfirmFor] = useState(null)
  const [subFor, setSubFor] = useState(null)

  const run = (fn, okMsg) => async (...args) => {
    try {
      await fn(...args)
      await reload()
      notify(okMsg)
      setDetail(null); setRebookFor(null); setConfirmFor(null); setSubFor(null)
    } catch (e) {
      notify(e.message, 'err')
    }
  }

  const apply = async (e) => {
    e.preventDefault()
    try {
      await api.post('/api/orders', { ...form, flight_id: Number(form.flight_id) })
      setForm({ passenger_name: '', pnr: '', flight_id: '', seat: '', meal_type_code: 'VGML', note: '' })
      await reload()
      notify('特殊餐申请已登记')
    } catch (err) {
      notify(err.message, 'err')
    }
  }

  const openDetail = async (order) => {
    try {
      setDetail(await api.get(`/api/orders/${order.id}`))
    } catch (e) {
      notify(e.message, 'err')
    }
  }

  const typeOf = (o) => mealTypes.find((t) => t.id === o.meal_type_id)
  const restricted = (o) => {
    const t = typeOf(o)
    return t && ['allergy', 'medical', 'religious'].includes(t.restriction_class)
  }

  return (
    <div className="page-grid">
      <section className="card">
        <h2>登记特殊餐申请</h2>
        <form onSubmit={apply} className="form">
          <label>旅客姓名
            <input required value={form.passenger_name} onChange={(e) => setForm({ ...form, passenger_name: e.target.value })} placeholder="张伟" />
          </label>
          <label>订座记录 PNR
            <input required value={form.pnr} onChange={(e) => setForm({ ...form, pnr: e.target.value.toUpperCase() })} placeholder="K8X2QD" maxLength={6} />
          </label>
          <label>航班
            <select required value={form.flight_id} onChange={(e) => setForm({ ...form, flight_id: e.target.value })}>
              <option value="">选择航班</option>
              {flights.map((f) => <option key={f.id} value={f.id}>{flightLabel(f)}</option>)}
            </select>
          </label>
          <label>座位
            <input required value={form.seat} onChange={(e) => setForm({ ...form, seat: e.target.value.toUpperCase() })} placeholder="12A" maxLength={4} />
          </label>
          <label>申请餐食类别
            <select value={form.meal_type_code} onChange={(e) => setForm({ ...form, meal_type_code: e.target.value })}>
              {mealTypes.filter((t) => t.code !== 'REGULAR').map((t) => (
                <option key={t.code} value={t.code}>{t.code} {t.name_zh}（{RESTRICT_ZH[t.restriction_class]}）</option>
              ))}
            </select>
          </label>
          <label>备注（过敏源 / 医嘱）
            <input value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} placeholder="花生严重过敏" />
          </label>
          <button className="btn primary" type="submit">登记申请</button>
        </form>
      </section>

      <section className="card wide">
        <h2>特殊餐申请记录（{orders.length}）</h2>
        <table className="table">
          <thead>
            <tr><th>编号</th><th>旅客</th><th>航班 / 座位</th><th>餐食类别</th><th>状态</th><th>餐食编号</th><th>操作</th></tr>
          </thead>
          <tbody>
            {orders.map((o) => (
              <tr key={o.id}>
                <td className="mono">{o.ref}</td>
                <td>{o.passenger_name}<div className="muted small">{o.pnr}</div></td>
                <td>{o.flight?.flight_no} <b>{o.seat}</b></td>
                <td>
                  {o.meal_type ? `${o.meal_type.code} ${o.meal_type.name_zh}` : o.requested_type_code}
                  {restricted(o) && <span className="tag danger">受限</span>}
                </td>
                <td><Pill status={o.status} /></td>
                <td className="mono">{o.meal?.code || '—'}{o.meal?.label_version > 1 && <span className="tag warn">v{o.meal.label_version} 需重贴</span>}</td>
                <td className="actions">
                  <button className="btn ghost" onClick={() => openDetail(o)}>记录</button>
                  {o.status === 'requested' && (
                    <button className="btn primary" onClick={() => setConfirmFor(o)}>确认类别</button>
                  )}
                  {['confirmed', 'ready'].includes(o.status) && (
                    <>
                      <button className="btn" onClick={() => setRebookFor(o)}>改签/换座</button>
                      <button className="btn" onClick={() => setSubFor(o)}>替代</button>
                    </>
                  )}
                  {!['loaded', 'cancelled'].includes(o.status) && (
                    <button className="btn danger" onClick={run(() => api.post(`/api/orders/${o.id}/cancel`), '已取消并释放待制作任务')}>取消</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      {confirmFor && (
        <Modal title={`确认餐食类别 · ${confirmFor.ref}`} onClose={() => setConfirmFor(null)}>
          <ConfirmForm
            order={confirmFor}
            mealTypes={mealTypes}
            onSubmit={run(async (code) => {
              await api.post(`/api/orders/${confirmFor.id}/confirm`, { meal_type_code: code })
            }, '类别已确认，制作任务已生成')}
          />
        </Modal>
      )}

      {rebookFor && (
        <Modal title={`改签 / 换座 · ${rebookFor.ref}`} onClose={() => setRebookFor(null)}>
          <RebookForm
            order={rebookFor}
            flights={flights}
            flightLabel={flightLabel}
            onSubmit={run(async (flightId, seat) => {
              await api.post(`/api/orders/${rebookFor.id}/rebook`, { flight_id: flightId, seat })
            }, '已改签：未装机餐食与任务同步跟随，旧航班停止出库')}
          />
        </Modal>
      )}

      {subFor && (
        <Modal title={`餐食替代 · ${subFor.ref}`} onClose={() => setSubFor(null)}>
          <SubstituteForm
            order={subFor}
            mealTypes={mealTypes}
            onSubmit={run(async (code) => {
              await api.post(`/api/orders/${subFor.id}/substitute`, { meal_type_code: code })
            }, '餐食类别已变更')}
          />
        </Modal>
      )}

      {detail && (
        <Modal title={`同一份记录 · ${detail.ref}`} onClose={() => setDetail(null)} wide>
          <div className="detail-head">
            <div><b>{detail.passenger_name}</b>（{detail.pnr}）</div>
            <div>{detail.flight?.flight_no} {detail.flight?.origin}→{detail.flight?.destination} 座位 <b>{detail.seat}</b></div>
            <div>{detail.meal_type ? `${detail.meal_type.code} ${detail.meal_type.name_zh}` : detail.requested_type_code} <Pill status={detail.status} /></div>
            {detail.meal && <div className="mono">餐食编号 {detail.meal.code}（标签 v{detail.meal.label_version}）</div>}
            {detail.note && <div className="muted">备注：{detail.note}</div>}
          </div>
          <h3>全程留痕</h3>
          <ol className="timeline">
            {detail.events.map((ev) => (
              <li key={ev.id}>
                <span className={`actor a-${ev.actor}`}>{({ passenger: '旅客', cs: '客服', kitchen: '配餐', crew: '机组', system: '系统' })[ev.actor]}</span>
                <span className="detail-text">{ev.detail}</span>
                <time>{ev.created_at}</time>
              </li>
            ))}
          </ol>
        </Modal>
      )}
    </div>
  )
}

function ConfirmForm({ order, mealTypes, onSubmit }) {
  const [code, setCode] = useState(order.requested_type_code)
  return (
    <form className="form" onSubmit={(e) => { e.preventDefault(); onSubmit(code) }}>
      <p className="muted">旅客申请：{order.requested_type_code}，请核对后确认最终类别，确认后生成配餐制作任务。</p>
      <label>最终餐食类别
        <select value={code} onChange={(e) => setCode(e.target.value)}>
          {mealTypes.filter((t) => t.code !== 'REGULAR').map((t) => (
            <option key={t.code} value={t.code}>{t.code} {t.name_zh}（{RESTRICT_ZH[t.restriction_class]}）</option>
          ))}
        </select>
      </label>
      <button className="btn primary" type="submit">确认并生成制作任务</button>
    </form>
  )
}

function RebookForm({ order, flights, flightLabel, onSubmit }) {
  const [flightId, setFlightId] = useState(order.flight_id)
  const [seat, setSeat] = useState(order.seat)
  return (
    <form className="form" onSubmit={(e) => { e.preventDefault(); onSubmit(Number(flightId), seat) }}>
      <p className="muted">当前 {order.flight?.flight_no} 座位 {order.seat}。改签后未装机餐食跟随订单调整，标签版本 +1 需重贴，旧航班不能继续出库。</p>
      <label>新航班
        <select value={flightId} onChange={(e) => setFlightId(e.target.value)}>
          {flights.map((f) => <option key={f.id} value={f.id}>{flightLabel(f)}</option>)}
        </select>
      </label>
      <label>新座位
        <input required value={seat} onChange={(e) => setSeat(e.target.value.toUpperCase())} maxLength={4} />
      </label>
      <button className="btn primary" type="submit">确认改签</button>
    </form>
  )
}

function SubstituteForm({ order, mealTypes, onSubmit }) {
  const [code, setCode] = useState(order.meal_type?.code)
  const current = mealTypes.find((t) => t.id === order.meal_type_id)
  const isRestricted = current && ['allergy', 'medical', 'religious'].includes(current.restriction_class)
  return (
    <form className="form" onSubmit={(e) => { e.preventDefault(); onSubmit(code) }}>
      {isRestricted && (
        <p className="warn-box">该申请为{current.name_zh}（{RESTRICT_ZH[current.restriction_class]}类），不得擅自用普通餐替代，仅可替换为其他受限类别餐。</p>
      )}
      <label>替代为
        <select value={code} onChange={(e) => setCode(e.target.value)}>
          {mealTypes.map((t) => (
            <option key={t.code} value={t.code}>{t.code} {t.name_zh}（{RESTRICT_ZH[t.restriction_class]}）</option>
          ))}
        </select>
      </label>
      <button className="btn primary" type="submit">提交替代</button>
    </form>
  )
}

export function Modal({ title, onClose, children, wide }) {
  return (
    <div className="modal-mask" onClick={onClose}>
      <div className={wide ? 'modal wide' : 'modal'} onClick={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <h3>{title}</h3>
          <button className="btn ghost" onClick={onClose}>✕</button>
        </div>
        {children}
      </div>
    </div>
  )
}
