import { useCallback } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { projectApi } from '../api'
import { useProjectQuery } from '../model/useProjectQuery'
import { projectTypeLabels } from '../model/projectSchema'
import { useAuth } from '../../auth/model/AuthContext'

export function ProjectsPage() {
  const [params, setParams] = useSearchParams()
  const requestedPage = Number(params.get('page') ?? 1)
  const page = Number.isSafeInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1
  return <ProjectList key={page} page={page} onPage={(next) => setParams({ page: String(next) })} />
}

function ProjectList({ page, onPage }: { page: number, onPage: (page: number) => void }) {
  const { user } = useAuth()
  const load = useCallback((signal: AbortSignal) => projectApi.list(page, signal), [page])
  const { data, error, loading, reload } = useProjectQuery(load)

  return <main className="mx-auto grid max-w-5xl gap-4 p-6">
    <header className="flex flex-wrap items-center justify-between gap-4">
      <h1 className="text-2xl font-semibold">Projects</h1>
      <Link to="/projects/new" className="rounded bg-gray-900 px-4 py-2 text-white">Create project</Link>
    </header>
    {loading && <p role="status">Loading projects…</p>}
    {error && <div><p role="alert" className="text-red-700">{error}</p><button className="underline" onClick={reload}>Try again</button></div>}
    {data && <>
      <p>{data.meta.total} project(s) you own or collaborate on.</p>
      {data.data.length === 0 ? <p>No projects on this page.</p> : <div className="overflow-x-auto">
        <table className="w-full border-collapse bg-white text-left">
          <caption className="sr-only">Your projects</caption>
          <thead><tr>{['Name', 'Type', 'Status', 'Access', 'Action'].map((label) => <th scope="col" key={label} className="border-b p-3">{label}</th>)}</tr></thead>
          <tbody>{data.data.map((project) => <tr key={project.id}>
            <td className="max-w-xs break-words border-b p-3">{project.name}</td>
            <td className="border-b p-3">{projectTypeLabels[project.type]}</td>
            <td className="border-b p-3">{project.status}</td>
            <td className="border-b p-3">{project.user_id === user?.id ? 'Owner' : 'Read-only'}</td>
            <td className="border-b p-3"><Link to={'/projects/' + project.id} className="text-blue-700 underline" aria-label={'View ' + project.name}>View</Link></td>
          </tr>)}</tbody>
        </table>
      </div>}
      <nav aria-label="Project pages" className="flex flex-wrap items-center gap-4">
        <button disabled={page <= 1} onClick={() => onPage(page - 1)} className="rounded border px-3 py-2 disabled:opacity-40">Previous</button>
        <span>Page {data.meta.current_page} of {data.meta.last_page}</span>
        <button disabled={page >= data.meta.last_page} onClick={() => onPage(page + 1)} className="rounded border px-3 py-2 disabled:opacity-40">Next</button>
      </nav>
    </>}
  </main>
}
