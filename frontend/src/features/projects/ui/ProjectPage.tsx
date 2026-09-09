import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { projectApi } from '../api'
import { useProjectQuery } from '../model/useProjectQuery'
import { projectTypeLabels, type Project } from '../model/projectSchema'
import { useAuth } from '../../auth/model/AuthContext'
import { errorMessage } from '../../../shared/api/client'
import { ProjectForm } from './ProjectForm'

export function ProjectPage() {
  const { projectId } = useParams()
  const id = Number(projectId)
  if (!Number.isSafeInteger(id) || id <= 0) return <main className="p-6"><h1>Project not found</h1><Link to="/projects">Back to projects</Link></main>
  return <ProjectDetail key={id} id={id} />
}

function ProjectDetail({ id }: { id: number }) {
  const load = useCallback((signal: AbortSignal) => projectApi.get(id, signal), [id])
  const { data, error, loading, reload } = useProjectQuery(load)
  return <main className="mx-auto grid max-w-3xl gap-4 p-6">
    <Link to="/projects" className="text-blue-700 underline">Back to projects</Link>
    {loading && <p role="status">Loading project…</p>}
    {error && <div><p role="alert" className="text-red-700">{error}</p><button className="underline" onClick={reload}>Try again</button></div>}
    {data && <ProjectContent project={data} reload={reload} />}
  </main>
}

function ProjectContent({ project, reload }: { project: Project, reload: () => void }) {
  const { user } = useAuth()
  const owner = user?.id === project.user_id
  const [editing, setEditing] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const request = useRef<AbortController | null>(null)
  useEffect(() => () => request.current?.abort(), [])

  async function changeStatus(status: 'archived' | 'inactive' | 'active') {
    if (request.current) return
    const controller = new AbortController()
    request.current = controller
    setPending(true)
    setError(null)
    try {
      if (status === 'archived') await projectApi.archive(project.id, controller.signal)
      else await projectApi.reactivate(project.id, status, controller.signal)
      if (!controller.signal.aborted) reload()
    } catch (failure) {
      if (!controller.signal.aborted) setError(errorMessage(failure))
    } finally {
      request.current = null
      if (!controller.signal.aborted) setPending(false)
    }
  }

  return <>
    <h1 className="break-words text-2xl font-semibold">{project.name}</h1>
    <dl className="grid gap-2 rounded border bg-white p-4">
      <div><dt className="font-semibold">Description</dt><dd className="whitespace-pre-wrap break-words">{project.description || 'No description'}</dd></div>
      <div><dt className="font-semibold">Type</dt><dd>{projectTypeLabels[project.type]}</dd></div>
      <div><dt className="font-semibold">Status</dt><dd>{project.status}</dd></div>
      <div><dt className="font-semibold">Access</dt><dd>{owner ? 'Owner (project administrator)' : 'Read-only project details'}</dd></div>
      <div><dt className="font-semibold">Created</dt><dd>{project.created_at}</dd></div>
      <div><dt className="font-semibold">Updated</dt><dd>{project.updated_at}</dd></div>
    </dl>
    {!owner && <p>Only the project owner can edit or archive this project.</p>}
    {owner && project.status === 'archived' && <section className="grid gap-3" aria-label="Reactivate project">
      <p>This project is archived and read-only. Reactivate it before editing.</p>
      <div className="flex flex-wrap gap-3">
        <button disabled={pending} onClick={() => changeStatus('inactive')} className="rounded border px-4 py-2 disabled:opacity-40">Reactivate as inactive</button>
        <button disabled={pending} onClick={() => changeStatus('active')} className="rounded border px-4 py-2 disabled:opacity-40">Reactivate as active</button>
      </div>
    </section>}
    {owner && project.status !== 'archived' && <>
      {!editing && !confirming && <div className="flex gap-3">
        <button onClick={() => setEditing(true)} className="rounded border px-4 py-2">Edit project</button>
        <button onClick={() => setConfirming(true)} className="rounded border px-4 py-2">Archive project</button>
      </div>}
      {editing && <section className="grid gap-3" aria-label="Edit project">
        <h2 className="text-xl font-semibold">Edit project</h2>
        <ProjectForm initialValues={{ name: project.name, description: project.description ?? '', type: project.type, status: project.status }}
          onSave={async (fields, signal) => {
            await projectApi.update(project.id, fields, signal)
            if (!signal.aborted) reload()
          }} />
        <button onClick={() => setEditing(false)} className="justify-self-start underline">Cancel editing</button>
      </section>}
      {confirming && <section className="grid gap-3 rounded border p-4" aria-label="Confirm archiving">
        <p>Archive this project? Its data and collaborators will be kept. You can reactivate it later.</p>
        <div className="flex gap-3">
          <button disabled={pending} onClick={() => changeStatus('archived')} className="rounded bg-gray-900 px-4 py-2 text-white disabled:opacity-40">Confirm archive</button>
          <button disabled={pending} onClick={() => setConfirming(false)} className="underline">Cancel</button>
        </div>
      </section>}
    </>}
    {pending && <p role="status">Saving project status…</p>}
    {error && <p role="alert" className="text-red-700">{error}</p>}
    <button disabled={pending} onClick={reload} className="justify-self-start underline disabled:opacity-40">Reload project</button>
  </>
}
