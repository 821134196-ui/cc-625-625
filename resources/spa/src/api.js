const headers = { 'Content-Type': 'application/json', Accept: 'application/json' }

async function request(method, url, body) {
  const res = await fetch(url, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const first = data.errors ? Object.values(data.errors)[0][0] : data.message
    const err = new Error(first || `请求失败（${res.status}）`)
    err.payload = data // 扫码等业务回执（wrong_flight / voided / not_found）
    throw err
  }
  return data
}

export const api = {
  get: (url) => request('GET', url),
  post: (url, body) => request('POST', url, body),
}
