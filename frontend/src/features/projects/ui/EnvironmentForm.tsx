import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useId, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import type { EnvironmentOptions, ProjectEnvironment } from '../model/environmentSchema'
import {
  createEnvironmentFormSchema, environmentFormError, environmentFormFields, environmentInitialValues,
  MIB, GIB, type EnvironmentFields,
} from '../model/environmentForm'
import { setApiFormErrors } from '../../../shared/api/formErrors'

type Props = {
  options: EnvironmentOptions
  environment?: ProjectEnvironment
  onSave: (fields: EnvironmentFields, signal: AbortSignal) => Promise<void>
  onCancel: () => void
}

const inputClass = 'w-full rounded border border-gray-300 bg-white p-2 focus:border-blue-600 focus:outline-2 focus:outline-blue-600'

export function EnvironmentForm({ options, environment, onSave, onCancel }: Props) {
  const configure = !environment || environment.capabilities.configure
  const prefix = useId()
  const request = useRef<AbortController | null>(null)
  const [discarding, setDiscarding] = useState(false)
  const { register, handleSubmit, setError, clearErrors, setFocus, formState: { errors, isSubmitting, isDirty } } = useForm<EnvironmentFields>({
    resolver: zodResolver(createEnvironmentFormSchema(options, configure)),
    defaultValues: environmentInitialValues(options, environment),
    mode: 'onBlur',
    reValidateMode: 'onChange',
  })
  useEffect(() => {
    setFocus('name')
    return () => request.current?.abort()
  }, [setFocus])
  useEffect(() => {
    if (!isDirty) return
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [isDirty])

  async function save(fields: EnvironmentFields) {
    if (request.current) return
    const controller = new AbortController()
    request.current = controller
    clearErrors('root.server')
    try { await onSave(fields, controller.signal) } catch (failure) {
      if (!controller.signal.aborted) setApiFormErrors(environmentFormError(failure), setError, environmentFormFields)
    } finally {
      if (request.current === controller) request.current = null
    }
  }

  function fieldProps(field: keyof EnvironmentFields) {
    return {
      id: prefix + field,
      'aria-invalid': !!errors[field],
      'aria-describedby': prefix + field + '-hint' + (errors[field] ? ' ' + prefix + field + '-error' : ''),
    }
  }
  function error(field: keyof EnvironmentFields) {
    return errors[field] && <p id={prefix + field + '-error'} className="text-sm text-red-700">{errors[field]?.message}</p>
  }

  const resourceFields = [
    { field: 'cpus', label: 'CPU cores', key: 'cpus', divisor: 1, step: 'any' },
    { field: 'memory_mib', label: 'Memory (MiB)', key: 'memory_bytes', divisor: MIB, step: 'any' },
    { field: 'storage_gib', label: 'Storage (GiB)', key: 'storage_bytes', divisor: GIB, step: 'any' },
    { field: 'pids', label: 'Process limit', key: 'pids', divisor: 1, step: '1' },
  ] as const

  return <form onSubmit={(event) => { void handleSubmit(save)(event) }} noValidate className="grid gap-5">
    <fieldset disabled={isSubmitting || discarding} className="grid min-w-0 gap-5 disabled:opacity-60">
      <legend className="sr-only">Environment settings</legend>
      <div>
        <label htmlFor={prefix + 'name'} className="block font-medium">Name</label>
        <input {...fieldProps('name')} {...register('name')} maxLength={255} required className={inputClass} />
        <p id={prefix + 'name-hint'} className="text-sm text-gray-600">Required. Unique within this project, up to 255 characters.</p>
        {error('name')}
      </div>
      <div>
        <label htmlFor={prefix + 'description'} className="block font-medium">Description (optional)</label>
        <textarea {...fieldProps('description')} {...register('description')} maxLength={10000} rows={3} className={inputClass} />
        <p id={prefix + 'description-hint'} className="text-sm text-gray-600">Describe what this environment is used for. Up to 10,000 characters.</p>
        {error('description')}
      </div>
      {configure ? <>
        <div>
          <label htmlFor={prefix + 'base_image'} className="block font-medium">Base image</label>
          <select {...fieldProps('base_image')} {...register('base_image')} required className={inputClass}>
            <option value="">Select an approved image</option>
            {environment && !options.approved_images.includes(environment.base_image) &&
              <option value={environment.base_image} disabled>{environment.base_image} (no longer approved)</option>}
            {options.approved_images.map((image) => <option key={image} value={image}>{image}</option>)}
          </select>
          <p id={prefix + 'base_image-hint'} className="text-sm text-gray-600">Images approved by the platform administrator.</p>
          {error('base_image')}
        </div>
        <fieldset className="grid min-w-0 gap-3">
          <legend className="mb-2 font-semibold">Resources</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            {resourceFields.map(({ field, label, key, divisor, step }) => <div key={field}>
              <label htmlFor={prefix + field} className="block font-medium">{label}</label>
              <input {...fieldProps(field)} {...register(field, { valueAsNumber: true })} type="number" step={step} required
                min={options.min_resource_limits[key] / divisor} max={options.max_resource_limits[key] / divisor} className={inputClass} />
              <p id={prefix + field + '-hint'} className="text-sm text-gray-600">
                Allowed: {options.min_resource_limits[key] / divisor}–{options.max_resource_limits[key] / divisor}.
              </p>
              {error(field)}
            </div>)}
          </div>
        </fieldset>
        <fieldset className="grid min-w-0 gap-4">
          <legend className="mb-2 font-semibold">Network</legend>
          <p className="text-sm text-gray-600">IP addressing is automatic. Leave DNS settings empty to use the environment defaults.</p>
          <div>
            <label htmlFor={prefix + 'dns_servers'} className="block font-medium">DNS servers (optional)</label>
            <textarea {...fieldProps('dns_servers')} {...register('dns_servers')} rows={2} className={inputClass} spellCheck={false} />
            <p id={prefix + 'dns_servers-hint'} className="text-sm text-gray-600">
              Up to {options.network_limits.dns_servers} IPv4 or IPv6 addresses, separated by commas or new lines.
            </p>
            {error('dns_servers')}
          </div>
          <div>
            <label htmlFor={prefix + 'search_domains'} className="block font-medium">DNS search domains (optional)</label>
            <textarea {...fieldProps('search_domains')} {...register('search_domains')} rows={2} className={inputClass} spellCheck={false} />
            <p id={prefix + 'search_domains-hint'} className="text-sm text-gray-600">
              Up to {options.network_limits.search_domains} hostnames, separated by commas or new lines.
            </p>
            {error('search_domains')}
          </div>
        </fieldset>
      </> : <p className="rounded bg-gray-50 p-3 text-sm">This environment has been provisioned. You can update its name and description; runtime settings are read-only.</p>}
      <button type="submit" className="justify-self-start rounded bg-gray-900 px-4 py-2 font-medium text-white">
        {isSubmitting ? 'Saving…' : environment ? 'Save changes' : 'Create environment'}
      </button>
    </fieldset>
    {errors.root?.server && <p role="alert" className="rounded bg-red-50 p-3 text-red-800">{errors.root.server.message}</p>}
    {discarding ? <div className="grid gap-3 rounded border border-amber-200 bg-amber-50 p-4" role="group" aria-label="Discard unsaved changes">
      <p>Discard your unsaved environment changes?</p>
      <div className="flex flex-wrap gap-3">
        <button type="button" onClick={onCancel} className="rounded border px-3 py-2">Discard changes</button>
        <button type="button" autoFocus onClick={() => setDiscarding(false)} className="rounded border px-3 py-2">Keep editing</button>
      </div>
    </div> : <button type="button" disabled={isSubmitting} onClick={() => isDirty ? setDiscarding(true) : onCancel()}
      className="justify-self-start rounded px-2 py-1 underline disabled:opacity-40">Cancel</button>}
  </form>
}
