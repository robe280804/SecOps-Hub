import { useEffect, useState } from 'react'
import { errorMessage } from '../../../shared/api/client'

export function useProjectQuery<T>(load: (signal: AbortSignal) => Promise<T>) {
  const [result, setResult] = useState<{
    load: typeof load, attempt: number, data: T | null, error: string | null
  } | null>(null)
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    load(controller.signal).then((result) => {
      if (!controller.signal.aborted) setResult({ load, attempt, data: result, error: null })
    }).catch((failure: unknown) => {
      if (!controller.signal.aborted) setResult({ load, attempt, data: null, error: errorMessage(failure) })
    })
    return () => controller.abort()
  }, [load, attempt])

  const current = result?.load === load && result.attempt === attempt ? result : null
  return {
    data: current?.data ?? null,
    error: current?.error ?? null,
    loading: current === null,
    reload: () => setAttempt((value) => value + 1),
  }
}
