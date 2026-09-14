import { useCallback, useEffect, useRef, useState } from 'react'
import { environmentApi } from '../api'
import { useProjectQuery } from '../model/useProjectQuery'
import { environmentFormError, environmentPayload, GIB, MIB } from '../model/environmentForm'
import type { EnvironmentOptions, ProjectEnvironment } from '../model/environmentSchema'
import { errorMessage } from '../../../shared/api/client'
import { EnvironmentForm } from './EnvironmentForm'
import { EnvironmentStatusBadge } from './EnvironmentStatusBadge'
import { EnvironmentTerminal } from './EnvironmentTerminal'

type Props = {
  projectId: number
  environmentId: number
  archived: boolean
  options: EnvironmentOptions | null
  onClose: () => void
  onChanged: (message: string, deleted: boolean) => void
}

function dateLabel(value: string | null): string {
  if (!value) return 'Not observed yet'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Unavailable' : date.toLocaleString()
}

export function EnvironmentDetail(props: Props) {
  const { projectId, environmentId } = props
  const load = useCallback((signal: AbortSignal) => environmentApi.get(projectId, environmentId, signal), [projectId, environmentId])
  const { data, loading, error, reload } = useProjectQuery(load)
  useEffect(() => {
    if (!data || !['provisioning', 'starting', 'stopping'].includes(data.status)) return
    const timer = window.setTimeout(reload, 5000)
    return () => window.clearTimeout(timer)
  }, [data, reload])

  return <section className="grid min-w-0 gap-4 rounded-lg border border-gray-300 bg-white p-4 sm:p-6" aria-label="Environment details">
    {loading && <p role="status">Loading environment…</p>}
    {error && <div className="grid gap-3"><p role="alert" className="text-red-700">{error}</p><button onClick={reload} className="justify-self-start underline">Try again</button></div>}
    {data ? <EnvironmentContent key={data.id + ':' + data.updated_at} {...props} environment={data} reload={reload} /> :
      <button onClick={props.onClose} className="justify-self-start underline">Close details</button>}
  </section>
}

function EnvironmentContent({ projectId, archived, environment, options, onClose, onChanged, reload }: Props & { environment: ProjectEnvironment, reload: () => void }) {
  const [editing, setEditing] = useState(false)
  const [terminal, setTerminal] = useState(false)
  const [confirmStop, setConfirmStop] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const request = useRef<AbortController | null>(null)
  const heading = useRef<HTMLHeadingElement>(null)
  useEffect(() => () => request.current?.abort(), [])
  useEffect(() => { if (!editing && !confirming) heading.current?.focus() }, [editing, confirming])
  const limits = environment.resource_limits
  const network = environment.network_configuration

  async function start() {
    if (request.current) return
    const controller = new AbortController()
    request.current = controller
    setPending(true)
    setError(null)
    try {
      await environmentApi.start(projectId, environment.id, controller.signal)
      if (!controller.signal.aborted) onChanged('Environment start requested.', false)
    } catch (failure) {
      if (!controller.signal.aborted) setError(errorMessage(failure))
    } finally {
      if (request.current === controller) request.current = null
      if (!controller.signal.aborted) setPending(false)
    }
  }

  async function stop() {
    if (request.current) return
    const controller = new AbortController()
    request.current = controller
    setPending(true)
    setError(null)
    try {
      await environmentApi.stop(projectId, environment.id, controller.signal)
      if (!controller.signal.aborted) {
        setTerminal(false)
        onChanged('Environment stop requested. Files and installed tools are preserved.', false)
      }
    } catch (failure) {
      if (!controller.signal.aborted) setError(errorMessage(failure))
    } finally {
      if (request.current === controller) request.current = null
      if (!controller.signal.aborted) setPending(false)
    }
  }

  async function remove() {
    if (request.current) return
    const controller = new AbortController()
    request.current = controller
    setPending(true)
    setError(null)
    try {
      await environmentApi.remove(projectId, environment.id, controller.signal)
      if (!controller.signal.aborted) onChanged('Environment deleted.', true)
    } catch (failure) {
      if (!controller.signal.aborted) setError(errorMessage(environmentFormError(failure)))
    } finally {
      if (request.current === controller) request.current = null
      if (!controller.signal.aborted) setPending(false)
    }
  }

  if (editing && options) return <>
    <h3 className="text-lg font-semibold">Edit {environment.name}</h3>
    <EnvironmentForm options={options} environment={environment}
      onCancel={() => setEditing(false)}
      onSave={async (fields, signal) => {
        await environmentApi.update(projectId, environment.id, environmentPayload(fields, options, environment.capabilities.configure), signal)
        if (!signal.aborted) onChanged('Environment updated.', false)
      }} />
  </>

  return <>
    <div className="flex flex-wrap items-start justify-between gap-3">
      <h3 ref={heading} tabIndex={-1} className="min-w-0 break-words text-lg font-semibold">{environment.name}</h3>
      <EnvironmentStatusBadge status={environment.status} />
    </div>
    <p className="whitespace-pre-wrap break-words text-gray-600">{environment.description || 'No description.'}</p>
    <dl className="grid gap-4 text-sm sm:grid-cols-2">
      <div className="min-w-0 sm:col-span-2"><dt className="font-medium">Base image</dt><dd className="break-all">{environment.base_image}</dd></div>
      <div><dt className="font-medium">Requested state</dt><dd>{environment.desired_state}</dd></div>
      <div><dt className="font-medium">Runtime status</dt><dd>{environment.runtime_status ?? 'Not provisioned'}</dd></div>
      <div><dt className="font-medium">CPU cores</dt><dd>{limits?.cpus ?? 'Not configured'}</dd></div>
      <div><dt className="font-medium">Memory</dt><dd>{limits ? limits.memory_bytes / MIB + ' MiB' : 'Not configured'}</dd></div>
      <div><dt className="font-medium">Storage</dt><dd>{limits ? limits.storage_bytes / GIB + ' GiB' : 'Not configured'}</dd></div>
      <div><dt className="font-medium">Process limit</dt><dd>{limits?.pids ?? 'Not configured'}</dd></div>
      <div><dt className="font-medium">IP addressing</dt><dd>{network?.mode ?? 'Not configured'}</dd></div>
      <div className="min-w-0"><dt className="font-medium">DNS servers</dt><dd className="break-words">{network?.dns_servers.join(', ') || 'Environment defaults'}</dd></div>
      <div className="min-w-0 sm:col-span-2"><dt className="font-medium">Search domains</dt><dd className="break-words">{network?.search_domains.join(', ') || 'None'}</dd></div>
      <div><dt className="font-medium">Last observed</dt><dd>{dateLabel(environment.last_observed_at)}</dd></div>
      <div><dt className="font-medium">Updated</dt><dd>{dateLabel(environment.updated_at)}</dd></div>
    </dl>
    {terminal && environment.capabilities.shell && <EnvironmentTerminal projectId={projectId} environmentId={environment.id} />}
    {['running', 'ready'].includes(environment.status) && !environment.capabilities.shell && <p role="status" className="text-sm text-gray-600">The container is running. Terminal access is unavailable; check the gateway configuration and project status.</p>}
    {environment.status === 'stopped' && <p className="text-sm text-gray-600">Files and installed tools are preserved. Start the environment to resume working; previous processes have ended.</p>}
    {environment.status === 'error' && <p role="alert" className="text-sm text-red-700">An environment operation failed. Check the worker logs before retrying.</p>}
    {!archived && !environment.capabilities.update && <p className="text-sm text-gray-600">An environment operation is in progress. Details refresh automatically.</p>}
    {!archived && !environment.capabilities.delete && <p className="text-sm text-gray-600">Deletion requires runtime cleanup and is unavailable for this environment.</p>}
    {!confirming && <div className="flex flex-wrap gap-3">
      {environment.capabilities.shell && <button disabled={pending} onClick={() => setTerminal(!terminal)}
        className="rounded bg-gray-900 px-4 py-2 text-white disabled:opacity-40">{terminal ? 'Close terminal' : 'Open terminal'}</button>}
      {environment.capabilities.stop && <button disabled={pending} onClick={() => setConfirmStop(true)}
        className="rounded border border-amber-600 px-4 py-2 text-amber-900 disabled:opacity-40">Stop environment</button>}
      {!archived && environment.capabilities.start && <button disabled={pending} onClick={() => { void start() }}
        className="rounded bg-green-800 px-4 py-2 text-white disabled:opacity-40">{pending ? 'Requesting start…' : 'Start environment'}</button>}
      {!archived && environment.capabilities.update && <button disabled={!options} onClick={() => setEditing(true)}
        className="rounded bg-gray-900 px-4 py-2 text-white disabled:opacity-40">Edit environment</button>}
      {!archived && environment.capabilities.delete && <button onClick={() => setConfirming(true)}
        className="rounded border border-red-300 px-4 py-2 text-red-700">Delete environment</button>}
      <button onClick={reload} className="rounded border px-4 py-2">Reload details</button>
      <button onClick={onClose} className="rounded px-3 py-2 underline">Close details</button>
    </div>}
    {confirmStop && <div role="group" aria-label="Confirm environment stop" className="grid gap-3 rounded border border-amber-300 bg-amber-50 p-4">
      <p>Stopping ends all running commands and scans. Files and installed tools remain available when you start this environment again.</p>
      <div className="flex gap-3">
        <button disabled={pending} onClick={() => { void stop() }} className="rounded bg-amber-800 px-4 py-2 text-white disabled:opacity-40">{pending ? 'Requesting stop…' : 'Confirm stop'}</button>
        <button disabled={pending} onClick={() => setConfirmStop(false)} className="rounded border px-4 py-2">Cancel</button>
      </div>
    </div>}
    {confirming && <div className="grid gap-3 rounded border border-red-200 bg-red-50 p-4" role="group" aria-label="Confirm environment deletion">
      <p className="break-words">Permanently delete <strong>{environment.name}</strong>? This removes its saved configuration and cannot be undone.</p>
      <div className="flex flex-wrap gap-3">
        <button disabled={pending} onClick={() => { void remove() }} className="rounded bg-red-700 px-4 py-2 text-white disabled:opacity-40">
          {pending ? 'Deleting…' : 'Confirm deletion'}
        </button>
        <button disabled={pending} autoFocus onClick={() => { setConfirming(false); setError(null) }}
          className="rounded border bg-white px-4 py-2 disabled:opacity-40">Cancel</button>
      </div>
    </div>}
    {error && <p role="alert" className="text-red-700">{error}</p>}
  </>
}
