import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { projectFormSchema, projectTextLimits, projectTypes, projectTypeLabels, type ProjectFields } from '../model/projectSchema'
import { setApiFormErrors } from '../../../shared/api/formErrors'

type Props = {
  initialValues?: ProjectFields
  onSave: (fields: ProjectFields, signal: AbortSignal) => Promise<void>
}

export function ProjectForm({ initialValues, onSave }: Props) {
  const request = useRef<AbortController | null>(null)
  useEffect(() => () => request.current?.abort(), [])
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<ProjectFields>({
    resolver: zodResolver(projectFormSchema),
    mode: 'onBlur',
    reValidateMode: 'onChange',
    defaultValues: initialValues ?? { name: '', description: '', type: 'personal', status: 'inactive' },
  })

  async function save(fields: ProjectFields) {
    if (request.current && !request.current.signal.aborted) return
    const controller = new AbortController()
    request.current = controller
    try { await onSave(fields, controller.signal) } catch (failure) {
      if (!controller.signal.aborted) setApiFormErrors(failure, setError, ['name', 'description', 'type', 'status'])
    } finally {
      if (request.current === controller) request.current = null
    }
  }

  return <form onSubmit={(event) => { void handleSubmit(save)(event) }} noValidate className="grid max-w-xl gap-4">
    <fieldset disabled={isSubmitting} className="grid gap-4 disabled:opacity-60">
      <legend className="sr-only">Project details</legend>
      <div>
        <label htmlFor="project-name" className="block font-medium">Name</label>
        <input id="project-name" className="w-full rounded border bg-white p-2" required minLength={1} maxLength={projectTextLimits.name}
          aria-invalid={!!errors.name} aria-describedby={'project-name-hint' + (errors.name ? ' project-name-error' : '')} {...register('name')} />
        <p id="project-name-hint" className="text-sm text-gray-600">Required. 1–{projectTextLimits.name} characters; cannot be only spaces.</p>
        {errors.name && <p id="project-name-error" className="text-red-700">{errors.name.message}</p>}
      </div>
      <div>
        <label htmlFor="project-description" className="block font-medium">Description (optional)</label>
        <textarea id="project-description" rows={5} maxLength={projectTextLimits.description} className="w-full rounded border bg-white p-2"
          aria-invalid={!!errors.description} aria-describedby={'project-description-hint' + (errors.description ? ' project-description-error' : '')} {...register('description')} />
        <p id="project-description-hint" className="text-sm text-gray-600">Optional. Up to {projectTextLimits.description.toLocaleString('en-US')} characters.</p>
        {errors.description && <p id="project-description-error" className="text-red-700">{errors.description.message}</p>}
      </div>
      <div>
        <label htmlFor="project-type" className="block font-medium">Type</label>
        <select id="project-type" className="w-full rounded border bg-white p-2" aria-invalid={!!errors.type}
          aria-describedby={errors.type ? 'project-type-error' : undefined} {...register('type')}>
          {projectTypes.map((type) => <option key={type} value={type}>{projectTypeLabels[type]}</option>)}
        </select>
        {errors.type && <p id="project-type-error" className="text-red-700">{errors.type.message}</p>}
      </div>
      <div>
        <label htmlFor="project-status" className="block font-medium">Status</label>
        <select id="project-status" className="w-full rounded border bg-white p-2" aria-invalid={!!errors.status}
          aria-describedby={errors.status ? 'project-status-error' : undefined} {...register('status')}>
          <option value="inactive">Inactive</option><option value="active">Active</option>
        </select>
        {errors.status && <p id="project-status-error" className="text-red-700">{errors.status.message}</p>}
      </div>
      <button type="submit" className="rounded bg-gray-900 px-4 py-2 text-white">{isSubmitting ? 'Saving…' : 'Save project'}</button>
    </fieldset>
    {errors.root?.server && <p role="alert" className="text-red-700">{errors.root.server.message}</p>}
  </form>
}
