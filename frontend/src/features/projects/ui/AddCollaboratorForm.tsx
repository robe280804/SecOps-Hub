import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { membershipApi } from '../api'
import { collaboratorFormSchema, type CollaboratorFields, type CollaboratorUser, type AccessLevel } from '../model/membershipSchema'
import { setApiFormErrors } from '../../../shared/api/formErrors'

type Props = {
  projectId: number
  disabled: boolean
  onAdd: (userId: number, accessLevel: AccessLevel) => Promise<boolean>
}

export function AddCollaboratorForm({ projectId, disabled, onAdd }: Props) {
  const [candidate, setCandidate] = useState<CollaboratorUser | null>(null)
  const [searched, setSearched] = useState(false)
  const request = useRef<AbortController | null>(null)
  useEffect(() => () => request.current?.abort(), [])
  const { register, handleSubmit, getValues, reset, setError, formState: { errors, isSubmitting } } = useForm<CollaboratorFields>({
    resolver: zodResolver(collaboratorFormSchema),
    defaultValues: { email: '', access_level: 'viewer' },
    mode: 'onBlur',
  })

  async function find(fields: CollaboratorFields) {
    if (request.current) return
    const controller = new AbortController()
    request.current = controller
    setCandidate(null)
    setSearched(false)
    try {
      const users = await membershipApi.lookup(projectId, fields.email, controller.signal)
      if (!controller.signal.aborted) {
        setCandidate(users[0] ?? null)
        setSearched(true)
      }
    } catch (failure) {
      if (!controller.signal.aborted) setApiFormErrors(failure, setError, ['email'])
    } finally {
      if (request.current === controller) request.current = null
    }
  }

  async function add() {
    if (!candidate) return
    if (await onAdd(candidate.id, getValues('access_level'))) {
      setCandidate(null)
      setSearched(false)
      reset()
    }
  }

  return <form onSubmit={(event) => { void handleSubmit(find)(event) }} noValidate className="grid gap-3 rounded border p-4">
    <h3 className="font-semibold">Add an existing user</h3>
    <fieldset disabled={disabled || isSubmitting} className="grid gap-3 disabled:opacity-60">
      <legend className="sr-only">Find a collaborator</legend>
      <div>
        <label htmlFor="collaborator-email" className="block font-medium">Exact email address</label>
        <input id="collaborator-email" type="email" required maxLength={255} className="w-full rounded border bg-white p-2"
          aria-invalid={!!errors.email} aria-describedby={'collaborator-email-hint' + (errors.email ? ' collaborator-email-error' : '')}
          {...register('email', { onChange: () => { setCandidate(null); setSearched(false) } })} />
        <p id="collaborator-email-hint" className="text-sm text-gray-600">Enter the complete email address. Up to 255 characters; 10 lookups per minute.</p>
        {errors.email && <p id="collaborator-email-error" className="text-red-700">{errors.email.message}</p>}
      </div>
      <button type="submit" className="justify-self-start rounded border px-3 py-2">{isSubmitting ? 'Looking up…' : 'Find user'}</button>
      {searched && !candidate && <p role="status">No eligible user found. The account may not exist, may own this project, or may already be a collaborator.</p>}
      {candidate && <div className="grid gap-3">
        <p>{candidate.name} ({candidate.email})</p>
        <div>
          <label htmlFor="collaborator-role" className="block font-medium">Project role</label>
          <select id="collaborator-role" className="rounded border bg-white p-2" {...register('access_level')}>
            <option value="viewer">Viewer</option><option value="contributor">Contributor</option>
          </select>
        </div>
        <button type="button" onClick={add} className="justify-self-start rounded bg-gray-900 px-3 py-2 text-white">Add collaborator</button>
      </div>}
    </fieldset>
    {errors.root?.server && <p role="alert" className="text-red-700">{errors.root.server.message}</p>}
  </form>
}
