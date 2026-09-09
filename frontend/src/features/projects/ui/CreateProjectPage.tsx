import { Link, useNavigate } from 'react-router-dom'
import { projectApi } from '../api'
import { ProjectForm } from './ProjectForm'

export function CreateProjectPage() {
  const navigate = useNavigate()
  return <main className="mx-auto grid max-w-3xl gap-4 p-6">
    <Link to="/projects" className="text-blue-700 underline">Back to projects</Link>
    <h1 className="text-2xl font-semibold">Create project</h1>
    <ProjectForm onSave={async (fields, signal) => {
      const project = await projectApi.create(fields, signal)
      if (!signal.aborted) navigate('/projects/' + project.id, { replace: true })
    }} />
  </main>
}
