import { useCallback, useEffect, useRef, useState } from 'react'
import { membershipApi } from '../api'
import { useProjectQuery } from '../model/useProjectQuery'
import type { Project } from '../model/projectSchema'
import { ApiError, errorMessage } from '../../../shared/api/client'
import { AddCollaboratorForm } from './AddCollaboratorForm'
import { CollaboratorRow } from './CollaboratorRow'

export function CollaboratorsSection({ project }: { project: Project }) {
  const [page, setPage] = useState(1)
  const load = useCallback((signal: AbortSignal) => membershipApi.list(project.id, page, signal), [project.id, page])
  const { data, error, loading, reload } = useProjectQuery(load)
  const [pending, setPending] = useState(false)
  const [mutationError, setMutationError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const request = useRef<AbortController | null>(null)
  useEffect(() => () => request.current?.abort(), [])
  const archived = project.status === 'archived'

  async function mutate(operation: (signal: AbortSignal) => Promise<unknown>, message: string, nextPage = page): Promise<boolean> {
    if (request.current) return false
    const controller = new AbortController()
    request.current = controller
    setPending(true)
    setMutationError(null)
    setNotice(null)
    try {
      await operation(controller.signal)
      if (controller.signal.aborted) return false
      setNotice(message)
      setPage(nextPage)
      reload()
      return true
    } catch (failure) {
      if (!controller.signal.aborted) {
        const validation = failure instanceof ApiError && failure.status === 422
          ? failure.errors.user_id?.[0] ?? failure.errors.access_level?.[0] : null
        setMutationError(validation ?? errorMessage(failure))
      }
      return false
    } finally {
      if (request.current === controller) request.current = null
      if (!controller.signal.aborted) setPending(false)
    }
  }

  return <section aria-labelledby="collaborators-title" className="grid gap-4 border-t pt-6">
    <h2 id="collaborators-title" className="text-xl font-semibold">Collaborators</h2>
    <p>You remain the project administrator. Both collaborator roles can currently read basic project details.</p>
    {archived && <p>Archived projects allow removal only. Reactivate the project to add users or change roles.</p>}
    {!archived && <AddCollaboratorForm projectId={project.id} disabled={pending || loading || !!error}
      onAdd={(userId, role) => mutate((signal) => membershipApi.add(project.id, userId, role, signal), 'Collaborator added.', 1)} />}
    {notice && <p role="status">{notice}</p>}
    {pending && <p role="status">Saving collaborator changes…</p>}
    {mutationError && <p role="alert" className="text-red-700">{mutationError}</p>}
    {loading && <p role="status">Loading collaborators…</p>}
    {error && <p role="alert" className="text-red-700">{error}</p>}
    {data && <>
      <p>{data.meta.total} collaborator(s)</p>
      {data.data.length === 0 ? <p>No collaborators on this page.</p> : <ul className="grid gap-3">
        {data.data.map((membership) => <CollaboratorRow key={membership.id} membership={membership} archived={archived} disabled={pending}
          onUpdate={(id, role) => mutate((signal) => membershipApi.update(project.id, id, role, signal), 'Collaborator role updated.')}
          onRemove={(id) => mutate((signal) => membershipApi.remove(project.id, id, signal), 'Collaborator removed.', data.data.length === 1 ? Math.max(1, page - 1) : page)} />)}
      </ul>}
      <nav aria-label="Collaborator pages" className="flex flex-wrap items-center gap-3">
        <button disabled={pending || page <= 1} onClick={() => setPage(page - 1)} className="rounded border px-3 py-2 disabled:opacity-40">Previous</button>
        <span>Page {data.meta.current_page} of {data.meta.last_page}</span>
        <button disabled={pending || page >= data.meta.last_page} onClick={() => setPage(page + 1)} className="rounded border px-3 py-2 disabled:opacity-40">Next</button>
      </nav>
    </>}
    <button disabled={pending || loading} onClick={reload} className="justify-self-start underline disabled:opacity-40">Reload collaborators</button>
  </section>
}
