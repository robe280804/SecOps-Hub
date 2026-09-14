import { useEffect, useState } from 'react'
import { environmentApi } from '../api'
import { errorMessage } from '../../../shared/api/client'

export function EnvironmentTerminal({ projectId, environmentId }: { projectId: number, environmentId: number }) {
  const [attempt, setAttempt] = useState(0)
  return <TerminalConnection key={projectId + ':' + environmentId + ':' + attempt}
    projectId={projectId} environmentId={environmentId} onReconnect={() => setAttempt((value) => value + 1)} />
}

function TerminalConnection({ projectId, environmentId, onReconnect }: {
  projectId: number, environmentId: number, onReconnect: () => void,
}) {
  const [session, setSession] = useState<{ url: string, expires_at: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [expired, setExpired] = useState(false)
  useEffect(() => {
    const controller = new AbortController()
    void environmentApi.terminal(projectId, environmentId, controller.signal)
      .then((value) => { if (!controller.signal.aborted) setSession(value) })
      .catch((failure) => { if (!controller.signal.aborted) setError(errorMessage(failure)) })
    return () => controller.abort()
  }, [projectId, environmentId])
  useEffect(() => {
    if (!session) return
    const timer = window.setTimeout(() => setExpired(true), Math.max(0, Date.parse(session.expires_at) - Date.now()))
    return () => window.clearTimeout(timer)
  }, [session])
  return <section aria-label="Environment terminal" className="grid min-w-0 gap-3">
    <p className="text-sm text-gray-600">Closing the terminal keeps the shell running. Stopping the environment ends its processes. Save working files in /workspace.</p>
    {!session && !error && <p role="status">Connecting to the environment…</p>}
    {error && <p role="alert" className="text-red-700">{error}</p>}
    {expired && <p role="status">Terminal access expired. Reconnect to resume your shell.</p>}
    {session && !expired && <iframe src={session.url} title="Interactive environment shell"
      referrerPolicy="same-origin" allow="clipboard-read; clipboard-write"
      className="h-[32rem] w-full rounded border border-gray-700 bg-gray-950" />}
    <button type="button" onClick={onReconnect}
      className="justify-self-start rounded border px-4 py-2">Reconnect terminal</button>
  </section>
}
