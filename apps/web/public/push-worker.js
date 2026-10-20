/*
 * Shows the notifications the Tresso server sends this browser by Web Push (the payload of PushMessage.php: id, kind,
 * title, body, path, silent), also while no Tresso tab is open, and opens what a clicked one is about: in an open tab
 * (which routes there itself, see src/platform/webPush.ts), else in a new one.
 */
self.addEventListener('push', (event) => {
  let payload = null
  try {
    payload = event.data?.json() ?? null
  } catch {
    payload = null
  }
  if (payload === null || typeof payload.title !== 'string' || payload.title === '') {
    return
  }
  const path = typeof payload.path === 'string' && payload.path.startsWith('/') ? payload.path : '/'
  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: typeof payload.body === 'string' ? payload.body : '',
      tag: typeof payload.id === 'string' ? payload.id : undefined,
      silent: payload.silent === true,
      data: { path },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const path = typeof event.notification.data?.path === 'string' ? event.notification.data.path : '/'
  event.waitUntil(
    (async () => {
      const tabs = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      const tab = tabs.find((client) => new URL(client.url).origin === self.location.origin)
      if (tab === undefined) {
        await self.clients.openWindow(new URL(path, self.location.origin).href)
        return
      }
      await tab.focus()
      tab.postMessage({ type: 'tresso:open', path })
    })(),
  )
})
