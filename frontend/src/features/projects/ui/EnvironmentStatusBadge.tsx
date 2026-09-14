import { environmentStatusLabels, type ProjectEnvironment } from '../model/environmentSchema'

const statusStyles: Record<ProjectEnvironment['status'], string> = {
  inactive: 'bg-gray-100 text-gray-700',
  provisioning: 'bg-blue-50 text-blue-800',
  stopped: 'bg-gray-100 text-gray-700',
  starting: 'bg-blue-50 text-blue-800',
  running: 'bg-green-50 text-green-800',
  ready: 'bg-green-50 text-green-800',
  stopping: 'bg-amber-50 text-amber-800',
  error: 'bg-red-50 text-red-800',
  deleting: 'bg-amber-50 text-amber-800',
}

export function EnvironmentStatusBadge({ status }: { status: ProjectEnvironment['status'] }) {
  return <span className={'inline-flex rounded-full px-2.5 py-1 text-xs font-medium ' + statusStyles[status]}>
    {environmentStatusLabels[status]}
  </span>
}
