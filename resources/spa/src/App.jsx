import React, { useCallback, useEffect, useState } from 'react'
import { api } from './api.js'
import ServicePage from './pages/ServicePage.jsx'
import KitchenPage from './pages/KitchenPage.jsx'
import LoadingPage from './pages/LoadingPage.jsx'

const TABS = [
  { key: 'service', label: '客服确认' },
  { key: 'kitchen', label: '配餐制作' },
  { key: 'loading', label: '装机核对' },
]

export default function App() {
  const [tab, setTab] = useState('service')
  const [flights, setFlights] = useState([])
  const [mealTypes, setMealTypes] = useState([])
  const [orders, setOrders] = useState([])
  const [toast, setToast] = useState(null)

  const notify = (message, kind = 'ok') => {
    setToast({ message, kind, ts: Date.now() })
  }

  const reload = useCallback(async () => {
    const [f, t, o] = await Promise.all([
      api.get('/api/flights'),
      api.get('/api/meal-types'),
      api.get('/api/orders'),
    ])
    setFlights(f)
    setMealTypes(t)
    setOrders(o)
  }, [])

  useEffect(() => {
    reload().catch((e) => notify(e.message, 'err'))
  }, [reload])

  useEffect(() => {
    if (!toast) return
    const t = setTimeout(() => setToast(null), 3600)
    return () => clearTimeout(t)
  }, [toast])

  const flightLabel = (f) => `${f.flight_no} ${f.origin}→${f.destination} ${f.dep_time}`

  return (
    <div className="app">
      <header className="topbar">
        <div className="brand">
          <span className="brand-mark">✈</span>
          <div>
            <h1>特殊餐食确认与装机核对</h1>
            <p>客服 · 配餐 · 机组 共用同一份记录</p>
          </div>
        </div>
        <nav className="tabs">
          {TABS.map((t) => (
            <button
              key={t.key}
              className={tab === t.key ? 'tab active' : 'tab'}
              onClick={() => setTab(t.key)}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </header>

      <main>
        {tab === 'service' && (
          <ServicePage
            flights={flights}
            mealTypes={mealTypes}
            orders={orders}
            reload={reload}
            notify={notify}
            flightLabel={flightLabel}
          />
        )}
        {tab === 'kitchen' && (
          <KitchenPage flights={flights} notify={notify} flightLabel={flightLabel} />
        )}
        {tab === 'loading' && (
          <LoadingPage flights={flights} notify={notify} flightLabel={flightLabel} />
        )}
      </main>

      {toast && <div className={`toast ${toast.kind}`}>{toast.message}</div>}
    </div>
  )
}

export const STATUS_ZH = {
  requested: '待确认',
  confirmed: '已确认',
  ready: '已贴标',
  loaded: '已装机',
  cancelled: '已取消',
}

export function Pill({ status }) {
  return <span className={`pill st-${status}`}>{STATUS_ZH[status] || status}</span>
}
