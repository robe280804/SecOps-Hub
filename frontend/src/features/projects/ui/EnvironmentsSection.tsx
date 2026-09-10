import { useCallback, useEffect, useRef, useState } from 'react'
import { environmentApi } from '../api'
import { useProjectQuery } from '../model/useProjectQuery'
import { environmentPayload, MIB } from '../model/environmentForm'
import type { Project } from '../model/projectSchema'
import { EnvironmentForm } from './EnvironmentForm'
import { EnvironmentDetail } from './EnvironmentDetail'
import { EnvironmentStatusBadge } from './EnvironmentStatusBadge'

export function EnvironmentsSection({ project }: { project: Project }) {
  const [page, setPage] = useState(1)
  const [panel, setPanel] = useState<'create' | number | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const heading = useRef<HTMLHeadingElement>(null)
  const load = useCallback((signal: AbortSignal) => environmentApi.list(project.id, page, signal), [project.id, page])
  const loadOptions = useCallback((signal: AbortSignal) => environmentApi.options(project.id, signal), [project.id])
  const query = useProjectQuery(load)
  const optionsQuery = useProjectQuery(loadOptions)
  const options = optionsQuery.data
  const archived = project.status === 'archived'
  const total = query.data?.meta.total
  const quotaReached = options !== null && total !== undefined && total >= options.max_per_project
  const canCreate = !archived && options?.can_create && options.approved_images.length > 0 && !quotaReached && total !== undefined
  useEffect(() => { if (notice) heading.current?.focus() }, [notice])

  function reload() {
    query.reload()
    optionsQuery.reload()
  }
  function changed(message: string, deleted = false, selectedId?: number) {
    setPanel(selectedId ?? null)
    setNotice(message)
    setPage(deleted && query.data?.data.length === 1 ? Math.max(1, page - 1) : page)
    reload()
  }
  function close() {
    setPanel(null)
    heading.current?.focus()
  }

  return <section aria-labelledby="environments-title" className="grid min-w-0 gap-4 border-t pt-6">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 ref={heading} id="environments-title" tabIndex={-1} className="text-xl font-semibold">Environments</h2>
        <p className="text-sm text-gray-600">{total !== undefined ? total + (options ? ' of ' + options.max_per_project : '') + ' environments' : 'Project workspaces and configuration'}</p>
      </div>
      <button disabled={!canCreate || panel !== null} onClick={() => { setNotice(null); setPanel('create') }}
        className="rounded bg-gray-900 px-4 py-2 font-medium text-white disabled:opacity-40">Create environment</button>
    </div>
    <p className="text-sm text-gray-600">Configure your project workspaces here. New environments are saved without starting a container.</p>
    {archived && <p className="rounded border border-amber-200 bg-amber-50 p-3 text-sm">This project is archived. Reactivate it to create, edit or delete environments.</p>}
    {!archived && options?.approved_images.length === 0 && <p className="rounded border border-amber-200 bg-amber-50 p-3 text-sm">
      No base images have been approved. Ask the platform administrator to approve an image before creating an environment.
    </p>}
    {!archived && quotaReached && <p className="text-sm text-gray-600">This project has reached its environment limit. Delete an unused, unprovisioned environment or contact the administrator.</p>}
    <div role="status" aria-live="polite">{notice && <p className="rounded bg-green-50 p-3 text-green-800">{notice}</p>}</div>
    {optionsQuery.error && <div className="flex flex-wrap items-center gap-3"><p role="alert" className="text-red-700">Unable to load environment settings. {optionsQuery.error}</p>
      <button disabled={panel !== null} onClick={optionsQuery.reload} className="underline disabled:opacity-40">Retry settings</button></div>}
    {panel === 'create' && options && <section aria-label="Create environment" className="grid gap-4 rounded-lg border border-gray-300 bg-white p-4 sm:p-6">
      <h3 className="text-lg font-semibold">Create environment</h3>
      <EnvironmentForm options={options} onCancel={close}
        onSave={async (fields, signal) => {
          const created = await environmentApi.create(project.id, environmentPayload(fields, options, true), signal)
          if (!signal.aborted) changed('Environment created. Its configuration is saved; no container has been started.', false, created.id)
        }} />
    </section>}
    {typeof panel === 'number' && <EnvironmentDetail key={panel} projectId={project.id} environmentId={panel} archived={archived}
      options={options} onClose={close} onChanged={changed} />}
    {(query.loading || optionsQuery.loading) && <p role="status">Loading environments…</p>}
    {query.error && <p role="alert" className="text-red-700">{query.error}</p>}
    {query.data && <>
      {query.data.data.length === 0 ? <div className="rounded-lg border border-dashed border-gray-300 p-6 text-center">
        <p className="font-medium">{total === 0 ? 'No environments yet' : 'No environments on this page'}</p>
        <p className="text-sm text-gray-600">{total === 0 ? 'Create an environment to save its image, network and resource settings.' : 'Go to the previous page or reload the list.'}</p>
      </div> : <ul className="grid gap-3">
        {query.data.data.map((environment) => <li key={environment.id} className="grid min-w-0 gap-3 rounded-lg border bg-white p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <h3 className="min-w-0 break-words font-semibold">{environment.name}</h3>
            <EnvironmentStatusBadge status={environment.status} />
          </div>
          {environment.description && <p className="line-clamp-2 break-words text-sm text-gray-600">{environment.description}</p>}
          <p className="break-all text-sm text-gray-600">{environment.base_image}</p>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-gray-600">{environment.resource_limits ?
              environment.resource_limits.cpus + ' CPU · ' + environment.resource_limits.memory_bytes / MIB + ' MiB memory' : 'Resource limits not configured'}</p>
            <button disabled={panel !== null} onClick={() => { setNotice(null); setPanel(environment.id) }}
              aria-label={'View environment ' + environment.name} className="rounded border px-3 py-2 text-sm disabled:opacity-40">View details</button>
          </div>
        </li>)}
      </ul>}
      <nav aria-label="Environment pages" className="flex flex-wrap items-center gap-3">
        <button disabled={panel !== null || page <= 1} onClick={() => setPage(page - 1)} className="rounded border px-3 py-2 disabled:opacity-40">Previous</button>
        <span className="text-sm">Page {query.data.meta.current_page} of {query.data.meta.last_page}</span>
        <button disabled={panel !== null || page >= query.data.meta.last_page} onClick={() => setPage(page + 1)} className="rounded border px-3 py-2 disabled:opacity-40">Next</button>
      </nav>
    </>}
    <button disabled={panel !== null || query.loading || optionsQuery.loading} onClick={reload}
      className="justify-self-start rounded px-2 py-1 underline disabled:opacity-40">Reload environments</button>
  </section>
}
